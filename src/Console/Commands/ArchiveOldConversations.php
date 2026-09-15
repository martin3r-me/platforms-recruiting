<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationHandled;
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
        $plan = $this->planFor($teamId, $days);

        $this->info(count($plan) . ' Chats ohne Eingang seit mehr als ' . $days . ' Tagen.');

        if ($this->option('dry-run')) {
            $this->line('Probelauf — nichts geschrieben.');

            return self::SUCCESS;
        }

        $this->stamp($teamId, $plan);
        $this->info(count($plan) . ' als erledigt gestempelt (Grund: backfill).');

        return self::SUCCESS;
    }

    /**
     * Ermittelt die betroffene Menge, schreibt aber nichts. Wird sowohl vom
     * Probelauf als auch vom scharfen Lauf aufgerufen — genau deshalb koennen
     * beide nie auseinanderlaufen.
     *
     * @return list<int>
     */
    public function planFor(int $teamId, int $days, ?int $now = null): array
    {
        // now()->subDays() statt Sekunden-Arithmetik — Konvention des Moduls
        // (siehe RecruitingChannelResolver u.a.). $now bleibt als Override fuer
        // deterministische Tests erhalten, laeuft aber ueber denselben Carbon-Pfad.
        $reference = $now !== null ? \Illuminate\Support\Carbon::createFromTimestamp($now) : now();
        $grenze = $reference->copy()->subDays($days);

        $query = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<', $grenze);

        $channelIds = RecruitingChannelResolver::channelIds($teamId);
        if ($channelIds !== []) {
            $query->whereIn('comms_channel_id', $channelIds);
        }

        $schonGestempelt = RecConversationHandled::query()
            ->where('team_id', $teamId)
            ->pluck('comms_whatsapp_thread_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $query->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => in_array($id, $schonGestempelt, true))
            ->values()
            ->all();
    }

    /**
     * Schreibt den Stempel fuer genau die uebergebene Menge. Reiner insert()
     * statt upsert(): planFor() hat schon gestempelte IDs bereits
     * ausgeschlossen, ein Konflikt auf comms_whatsapp_thread_id kann hier
     * also nicht auftreten.
     *
     * @param list<int> $threadIds
     */
    public function stamp(int $teamId, array $threadIds): void
    {
        if ($threadIds === []) {
            return;
        }

        $jetzt = now();

        foreach (array_chunk($threadIds, 200) as $chunk) {
            $rows = array_map(fn (int $id) => [
                'team_id' => $teamId,
                'comms_whatsapp_thread_id' => $id,
                'handled_at' => $jetzt,
                'handled_by_user_id' => null,
                'handled_reason' => RecConversationHandled::REASON_BACKFILL,
                'created_at' => $jetzt,
                'updated_at' => $jetzt,
            ], $chunk);

            RecConversationHandled::insert($rows);
        }
    }
}
