<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Services\Comms\ConversationHandledState;
use Platform\Recruiting\Services\Comms\QuickReplyStamper;
use Platform\Recruiting\Services\Comms\RecruitingChannelResolver;

/**
 * Einmal-Aufraeumen der Altlast: stempelt Chats, deren letzter Eingang laenger
 * als N Tage zurueckliegt, als erledigt (Grund 'backfill').
 *
 * Laeuft NICHT automatisch und NICHT beim Deploy — wird spaeter genau einmal
 * von Hand gestartet, zuerst mit --dry-run. Deshalb ist planFor() strikt von
 * stamp() getrennt: der Probelauf ruft NUR planFor() auf und meldet damit
 * exakt die Menge, die ein scharfer Lauf schreiben wuerde.
 *
 * Nichts wird geloescht, und der Lauf ist vollstaendig rueckholbar:
 *   DELETE FROM rec_conversation_handled WHERE handled_reason = 'backfill';
 *
 * Schreibt die Person danach erneut, taucht der Chat ohnehin von selbst
 * wieder auf (ConversationHandledState hebt den Stempel bei neuem Eingang
 * auf).
 *
 * --quick-replies (Spec 16.10.2026): statt der Altersgrenze die Chats, deren
 * letzter Eingang ein Button-Klick war — Grund 'quick_reply', egal wie alt.
 * Holt nach, was der Inbound-Listener seit dem Deploy selbst stempelt.
 *
 * Warum nicht ConversationBulkHandler (Task 9): der baut fuer eine
 * VORGEGEBENE Liste von Thread-IDs den 'manual'-Stempel mit einem
 * ausfuehrenden Nutzer (upsert, Reason fest auf REASON_MANUAL). Hier fehlt
 * genau das, was ihn ausmacht — es gibt keinen Nutzer und keinen fertigen
 * ID-Satz, sondern eine eigene Auswahl (last_inbound_at-Schwelle) und ein
 * anderer Grund (REASON_BACKFILL). Eine zweite, nur leicht abweichende
 * Kopie derselben Upsert-Logik waere hier schlimmer als der duenne, eigene
 * insert() unten: schon gestempelte IDs sind durch planFor() bereits
 * ausgeschlossen, ein Konflikt kann also nicht auftreten.
 */
class ArchiveOldConversations extends Command
{
    protected $signature = 'recruiting:conversations-archivieren
        {--team= : Team-ID}
        {--older-than=30 : Tage seit dem letzten Eingang}
        {--quick-replies : statt der Altersgrenze: letzter Eingang war ein Button-Klick}
        {--dry-run : nur zeigen, nichts schreiben}';

    protected $description = 'Hakt alte, unbeantwortete WhatsApp-Konversationen als erledigt ab';

    public function handle(): int
    {
        $teamOption = $this->option('team');
        $teamSource = $teamOption ? '--team' : 'Konfiguration (recruiting.zas.inbound_team_id)';
        $teamId = (int) ($teamOption ?: config('recruiting.zas.inbound_team_id'));

        // Muss VOR jeder Query stehen: wer ohne --team laeuft, faellt auf den
        // Konfigurations-Rueckfall zurueck — das ist das einzige Mittel, um
        // vor dem Schreiben zu sehen, welches Team getroffen wird.
        $this->info("Team: {$teamId} (Quelle: {$teamSource})");

        if ($teamId <= 0) {
            $this->error('Kein Team angegeben (--team=).');

            return self::FAILURE;
        }

        $days = (int) $this->option('older-than');
        $quickReplies = (bool) $this->option('quick-replies');
        $reason = $quickReplies ? RecConversationHandled::REASON_QUICK_REPLY : RecConversationHandled::REASON_BACKFILL;

        try {
            $plan = $quickReplies ? $this->planQuickReplies($teamId) : $this->planFor($teamId, $days);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($quickReplies
            ? count($plan) . ' Chats, deren letzter Eingang ein Button-Klick war.'
            : count($plan) . ' Chats ohne Eingang seit mehr als ' . $days . ' Tagen.');

        if ($this->option('dry-run')) {
            $this->line('Probelauf — nichts geschrieben.');

            return self::SUCCESS;
        }

        if ($quickReplies) {
            $plan = $this->stampQuickReplies($teamId, $plan);
        } else {
            $this->stamp($teamId, $plan, $reason);
        }
        $this->info(count($plan) . ' als erledigt gestempelt (Grund: ' . $reason . ').');

        return self::SUCCESS;
    }

    /**
     * Ermittelt die betroffene Menge, schreibt aber nichts. Wird sowohl vom
     * Probelauf als auch vom scharfen Lauf aufgerufen — genau deshalb koennen
     * beide nie auseinanderlaufen.
     *
     * Fix (Abschluss-Durchsicht, Befund 4, IMPORTANT): war bisher fail-OPEN —
     * ein leeres Kanal-Set liess den Kanalfilter komplett entfallen und der
     * Lauf traf DANN JEDEN Thread des Teams, auch Dispo-Threads. Der Probelauf
     * zeigte davon nichts (nur eine Zahl), und das Kommando schreibt spaeter
     * in echte Produktionsdaten. Jetzt fail-CLOSED: ein leeres Kanal-Set
     * bricht mit einer klaren Fehlermeldung ab, statt die Reichweite
     * stillschweigend auszuweiten.
     *
     * @throws \RuntimeException wenn kein Recruiting-Kanal fuer das Team
     *         konfiguriert ist (RecruitingChannelResolver liefert []).
     * @return list<int>
     */
    public function planFor(int $teamId, int $days, ?int $now = null): array
    {
        $channelIds = self::channelIdsOrFail($teamId);

        // now()->subDays() statt Sekunden-Arithmetik — Konvention des Moduls
        // (siehe RecruitingChannelResolver u.a.). $now bleibt als Override fuer
        // deterministische Tests erhalten, laeuft aber ueber denselben Carbon-Pfad.
        $reference = $now !== null ? \Illuminate\Support\Carbon::createFromTimestamp($now) : now();
        $grenze = $reference->copy()->subDays($days);

        $threads = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<', $grenze)
            ->whereIn('comms_channel_id', $channelIds)
            ->get(['id', 'last_inbound_at']);

        return $this->withoutValidStamp($teamId, $threads);
    }

    /**
     * Chats, deren letzter Eingang ein Button-Klick war — nach derselben
     * Regel wie der Inbound-Listener (QuickReplyStamper::qualifies()): kein
     * neuerer Eingang, nichts Getipptes seit der letzten Antwort bzw. dem
     * letzten Stempel. Im Zweifel bleibt der Chat offen.
     *
     * @throws \RuntimeException wie planFor()
     * @return list<int>
     */
    public function planQuickReplies(int $teamId): array
    {
        return array_keys($this->quickReplyCandidates($teamId));
    }

    /**
     * Stempelt ueber den QuickReplyStamper, der die Regel beim Schreiben
     * erneut prueft und den Zeitpunkt des Klicks einsetzt — ein Text, der
     * zwischen Probelauf und Schreiben eingeht, hebt den Stempel damit auf.
     *
     * @param list<int> $plan
     * @return list<int> tatsaechlich gestempelt
     */
    private function stampQuickReplies(int $teamId, array $plan): array
    {
        $stamper = app(QuickReplyStamper::class);
        $gestempelt = [];
        foreach ($this->quickReplyCandidates($teamId) as $threadId => [$thread, $message]) {
            if (in_array($threadId, $plan, true) && $stamper->stampIfQuickReply($thread, $message)) {
                $gestempelt[] = $threadId;
            }
        }

        return $gestempelt;
    }

    /** @return array<int, array{0: CommsWhatsAppThread, 1: CommsWhatsAppMessage}> */
    private function quickReplyCandidates(int $teamId): array
    {
        $channelIds = self::channelIdsOrFail($teamId);

        $threads = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_inbound_at')
            ->whereIn('comms_channel_id', $channelIds)
            ->get(['id', 'team_id', 'last_inbound_at']);

        $offen = array_flip($this->withoutValidStamp($teamId, $threads));
        $threads = $threads->filter(fn ($t) => isset($offen[(int) $t->id]))->keyBy('id');
        if ($threads->isEmpty()) {
            return [];
        }

        $lastInboundIds = CommsWhatsAppMessage::query()
            ->whereIn('comms_whatsapp_thread_id', $threads->keys()->all())
            ->where('direction', 'inbound')
            ->groupBy('comms_whatsapp_thread_id')
            ->selectRaw('MAX(id) AS id')
            ->pluck('id')
            ->all();

        $stamper = app(QuickReplyStamper::class);
        $out = [];
        foreach (CommsWhatsAppMessage::query()->whereIn('id', $lastInboundIds)->get() as $message) {
            $thread = $threads->get((int) $message->comms_whatsapp_thread_id);
            if ($thread !== null && $stamper->qualifies($thread, $message)) {
                $out[(int) $thread->id] = [$thread, $message];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Schliesst nur Chats mit GUELTIGEM Stempel aus. Ein Stempel, den ein
     * spaeterer Eingang aufgehoben hat, zaehlt nicht — sonst bliebe genau
     * dieser Chat dauerhaft offen (bis 16.10.2026 schloss jeder Stempel aus,
     * und insert() waere an ihm gescheitert).
     *
     * @param iterable<CommsWhatsAppThread> $threads
     * @return list<int>
     */
    private function withoutValidStamp(int $teamId, iterable $threads): array
    {
        $handledAt = RecConversationHandled::query()
            ->where('team_id', $teamId)
            ->get(['comms_whatsapp_thread_id', 'handled_at'])
            ->mapWithKeys(fn ($row) => [(int) $row->comms_whatsapp_thread_id => $row->handled_at->getTimestamp()])
            ->all();

        $ids = [];
        foreach ($threads as $thread) {
            $id = (int) $thread->id;
            if (!ConversationHandledState::isHandled($handledAt[$id] ?? null, $thread->last_inbound_at?->getTimestamp())) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @throws \RuntimeException siehe planFor()
     * @return list<int>
     */
    private static function channelIdsOrFail(int $teamId): array
    {
        $channelIds = RecruitingChannelResolver::channelIds($teamId);
        if ($channelIds === []) {
            throw new \RuntimeException(
                'Kein Recruiting-WhatsApp-Kanal fuer Team ' . $teamId . ' konfiguriert '
                . '(RecruitingChannelResolver::channelIds() liefert ein leeres Set) — Abbruch, '
                . 'kein Kanalfilter moeglich. Ohne diesen Filter traefe der Lauf JEDEN Thread des '
                . 'Teams, auch Dispo-Threads. Erst das WABA-Konto in den Kommunikations-'
                . 'Einstellungen konfigurieren.',
            );
        }

        return $channelIds;
    }

    /**
     * Schreibt den Stempel fuer genau die uebergebene Menge. upsert() statt
     * insert(): ein aufgehobener (veralteter) Stempel steht noch in der
     * Tabelle und wird ueberschrieben — comms_whatsapp_thread_id ist unique.
     *
     * handled_at = letzter Eingang des Threads, nicht now() (Review
     * 16.10.2026): ein Text, der zwischen Planen und Schreiben eingeht, ist
     * juenger und hebt den Stempel damit auf, statt still mit abgelegt zu werden.
     *
     * @param list<int> $threadIds
     */
    public function stamp(int $teamId, array $threadIds, string $reason = RecConversationHandled::REASON_BACKFILL): void
    {
        if ($threadIds === []) {
            return;
        }

        $jetzt = now();

        foreach (array_chunk($threadIds, 200) as $chunk) {
            $eingang = CommsWhatsAppThread::query()
                ->whereIn('id', $chunk)
                ->pluck('last_inbound_at', 'id')
                ->all();

            $rows = array_map(fn (int $id) => [
                'team_id' => $teamId,
                'comms_whatsapp_thread_id' => $id,
                'handled_at' => $eingang[$id] ?? $jetzt,
                'handled_by_user_id' => null,
                'handled_reason' => $reason,
                'created_at' => $jetzt,
                'updated_at' => $jetzt,
            ], $chunk);

            RecConversationHandled::query()->upsert(
                $rows,
                ['comms_whatsapp_thread_id'],
                ['team_id', 'handled_at', 'handled_by_user_id', 'handled_reason', 'updated_at'],
            );
        }
    }
}
