<?php

namespace Platform\Recruiting\Services\Comms;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\Comms\ThemenTexte;
use Platform\Recruiting\Support\PublicAddressStyle;

/**
 * Lohn-Chat parken, solange die Akte unvollstaendig ist (Variante B,
 * Sebastian 26.10.2026; Markus: „erst schreiben, wenn die Akte vollständig ist“).
 *
 * WhatsApp laesst sich nicht sperren — geparkt heisst: der Chat steht nicht
 * unter „Ungelesen“/Ampel und auch sonst nirgends in der Liste (InboxQuery, Markus 28.10.).
 * Die Nachrichten bleiben gespeichert; erst die Freigabe zeigt den Chat HR.
 *
 *  - beiEingang(): getippte Nachricht in einem geparkten Chat. Akte noch
 *    unvollstaendig → die Liste noch einmal (hoechstens einmal je Stunde,
 *    gemessen an updated_at der Zeile), Chat bleibt geparkt. Akte vollstaendig
 *    (oder kein aktiver MA mehr) → freigeben, die Nachricht geht normal an HR.
 *  - freigebenWennVollstaendig(): Lauf recruiting:lohn-parken-freigeben.
 *    Akte vollstaendig → Dank an den MA und freigeben. Die Freigabe haengt NIE
 *    am Versand: ausserhalb des 24-h-Fensters lehnt Meta ab, der Chat muss
 *    trotzdem zu HR.
 */
class LohnParken
{
    public const ERINNERT = 'geparkt:erinnert';
    public const STILL = 'geparkt:still';
    public const FREIGEGEBEN = 'freigegeben';
    public const ERINNERUNG_SEKUNDEN = 3600;

    private const ACK = 'topic_ack';

    public function __construct(
        private readonly ?InteraktivSender $sender = null,
        private readonly ?ThemenAuswahlEmpfaenger $empfaenger = null,
        private readonly ?LohnLuecken $luecken = null,
    ) {}

    /** Hat das Parken auf diesen Eingang reagiert? Dann keine Abwesenheitsnotiz dazu. */
    public static function antwortet(string $status): bool
    {
        return $status === self::ERINNERT || $status === self::STILL;
    }

    public function beiEingang(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): string
    {
        if ($message->direction !== 'inbound' || QuickReply::isQuickReply($message->meta_payload)) {
            return 'ignoriert:kein_getippter_eingang';
        }
        $teamId = (int) $thread->team_id;
        $settings = RecApplicantSettings::query()->where('team_id', $teamId)->first();
        if (!self::schalterAn($settings)) {
            return 'ignoriert:schalter_aus';
        }
        $topic = $this->geparkt($teamId, (int) $thread->id);
        if ($topic === null) {
            return 'ignoriert:nicht_geparkt';
        }
        // HR hat inzwischen selbst geantwortet (Review 26.10. I2): kein Bot
        // mitten ins Gespraech — das Parken endet, die Nachricht geht an HR.
        if (self::hrHatGeantwortet($topic)) {
            $this->freigeben($topic);

            return 'freigegeben:hr_hat_geantwortet';
        }

        $phone = (string) ($thread->remote_phone_number ?? '');
        $employee = $this->mitarbeiter($phone, $teamId);
        $luecken = $employee !== null ? $this->luecken()->fuer($employee) : [];
        if ($employee === null || $luecken === []) {
            $this->freigeben($topic);

            return self::FREIGEGEBEN;
        }

        if ($topic->updated_at !== null && $topic->updated_at->getTimestamp() > Carbon::now()->getTimestamp() - self::ERINNERUNG_SEKUNDEN) {
            return self::STILL;
        }

        $duzen = PublicAddressStyle::informal($settings->getSetting('use_informal_address'));
        $r = $this->sendeListe($channel, $phone, $employee, $settings, $duzen, $luecken);
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            Log::warning('recruiting.lohn_parken.erinnerung_nicht_gesendet', [
                'topic_id' => $topic->id, 'status' => $r['status'], 'error' => $r['error'],
            ]);

            // Nicht STILL: dann darf die Abwesenheitsnotiz laufen (Review 26.10. m8).
            return 'ignoriert:send_' . $r['status'];
        }
        $topic->touch();

        return self::ERINNERT;
    }

    /** @return string 'geparkt' | 'freigegeben' | 'freigegeben:<grund>' */
    public function freigebenWennVollstaendig(RecConversationTopic $topic): string
    {
        $teamId = (int) $topic->team_id;
        $settings = RecApplicantSettings::query()->where('team_id', $teamId)->first();
        $thread = CommsWhatsAppThread::query()->where('team_id', $teamId)->find((int) $topic->comms_whatsapp_thread_id);
        if ($thread === null) {
            return $this->freigeben($topic) ? 'freigegeben:ohne_thread' : 'geparkt';
        }
        // HR hat schon geantwortet: der Chat ist in Arbeit, nichts zurueckholen.
        if (self::hrHatGeantwortet($topic)) {
            return $this->freigeben($topic) ? 'freigegeben:hr_hat_geantwortet' : 'geparkt';
        }
        if (!self::schalterAn($settings)) {
            return $this->freigeben($topic, $thread) ? 'freigegeben:schalter_aus' : 'geparkt';
        }

        $phone = (string) ($thread->remote_phone_number ?? '');
        $employee = $this->mitarbeiter($phone, $teamId);
        if ($employee === null) {
            return $this->freigeben($topic, $thread) ? 'freigegeben:kein_aktiver_ma' : 'geparkt';
        }
        if ($this->luecken()->fuer($employee) !== []) {
            return 'geparkt';
        }

        // Erst beanspruchen, dann senden: zwei Laeufe schicken nicht zweimal Dank.
        if (!$this->freigeben($topic, $thread)) {
            return 'geparkt';
        }
        $channel = CommsChannel::query()->find((int) $thread->comms_channel_id);
        if ($channel === null) {
            return 'freigegeben:ohne_nachricht';
        }
        $duzen = PublicAddressStyle::informal($settings->getSetting('use_informal_address'));
        $r = $this->sender()->knoepfe(
            $channel,
            $phone,
            ThemenTexte::lohnFrei($duzen, $settings->getSetting('comms_topic_text_lohn_frei')),
            [['id' => self::ACK, 'title' => 'Alles klar']],
        );
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            Log::info('recruiting.lohn_parken.dank_nicht_gesendet', [
                'topic_id' => $topic->id, 'status' => $r['status'], 'error' => $r['error'],
            ]);

            return 'freigegeben:ohne_nachricht';
        }

        return self::FREIGEGEBEN;
    }

    /**
     * Die Liste der Luecken mit Portal-Knopf (ohne neues Portal: Quittungsknopf,
     * s. ThemenKlickHandler::sendeHinweis — reiner Text zaehlte als HR-Antwort).
     *
     * @param list<string> $luecken
     * @return array{status:string, message_id:?int, error:?string}
     */
    public function sendeListe(CommsChannel $channel, string $phone, RecEmployee $employee, ?RecApplicantSettings $settings, bool $duzen, array $luecken): array
    {
        $body = ThemenTexte::lohnOffen($duzen, $settings?->getSetting('comms_topic_text_lohn_offen'), $luecken);
        $url = LohnLuecken::portalUrl($employee);

        return $url !== null
            ? $this->sender()->linkKnopf($channel, $phone, $body, 'Zum Portal', $url)
            : $this->sender()->knoepfe($channel, $phone, $body, [['id' => self::ACK, 'title' => 'Alles klar']]);
    }

    /**
     * Menschliche Antwort von HR nach der Lohn-Wahl? Dieselbe Regel wie
     * InboxQuery::hrAntwortZeitpunkte (kein Auto-Reply, mit Absender). chosen_at fehlt nie bei einer
     * geparkten Zeile; ohne gilt die Zeile als nicht beantwortet.
     */
    public static function hrHatGeantwortet(RecConversationTopic $topic): bool
    {
        if ($topic->chosen_at === null) {
            return false;
        }

        return CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', (int) $topic->comms_whatsapp_thread_id)
            ->where('direction', 'outbound')
            ->where('is_auto_reply', false)
            // Nur mit Absender: Einmalcode/Nachweis-Erinnerung gehen ohne
            // auto-Flag, aber auch ohne Person raus (Review 28.10. I1).
            ->whereNotNull('sent_by_user_id')
            ->where('created_at', '>', $topic->chosen_at)
            ->exists();
    }

    public static function schalterAn(?RecApplicantSettings $settings): bool
    {
        return $settings !== null && (bool) $settings->getSetting('comms_topic_enabled', false);
    }

    /** Juengste Zeile des Threads, nur wenn sie geparkt ist. */
    private function geparkt(int $teamId, int $threadId): ?RecConversationTopic
    {
        $zeile = RecConversationTopic::aktuelleJeThread($teamId, [$threadId])[$threadId] ?? null;
        if ($zeile === null || $zeile->state !== RecConversationTopic::STATE_GEPARKT) {
            return null;
        }

        return RecConversationTopic::query()->find($zeile->id);
    }

    /**
     * Bedingt: nur eine geparkte Zeile wird freigegeben (zwei Laeufe, Eingang + Lauf).
     *
     * Mit $thread (Lauf): der Chat wird wieder ungelesen und ein Erledigt-
     * Stempel faellt weg — sonst blieb ein Chat, den HR im Filter „Wartet auf
     * Akte“ schon geoeffnet hatte, nach der Freigabe nur unter „Alle“ stehen
     * (Review 26.10. I1). Beim Eingang ist der Chat durch die neue Nachricht
     * ohnehin offen.
     */
    private function freigeben(RecConversationTopic $topic, ?CommsWhatsAppThread $thread = null): bool
    {
        $n = RecConversationTopic::query()
            ->whereKey($topic->id)
            ->where('state', RecConversationTopic::STATE_GEPARKT)
            ->update(['state' => RecConversationTopic::STATE_FREIGEGEBEN, 'updated_at' => Carbon::now()]);
        if ($n === 1) {
            $topic->state = RecConversationTopic::STATE_FREIGEGEBEN;
            $topic->syncOriginalAttribute('state');
            if ($thread !== null) {
                $thread->markAsUnread();
                RecConversationHandled::query()
                    ->where('team_id', (int) $topic->team_id)
                    ->where('comms_whatsapp_thread_id', (int) $thread->id)
                    ->delete();
            }
        }

        return $n === 1;
    }

    private function mitarbeiter(string $phone, int $teamId): ?RecEmployee
    {
        if ($phone === '') {
            return null;
        }
        $id = ($this->empfaenger ?? app(ThemenAuswahlEmpfaenger::class))->aktiverMitarbeiter($phone, $teamId);

        return $id === null ? null : RecEmployee::query()->where('team_id', $teamId)->find($id);
    }

    private function luecken(): LohnLuecken
    {
        return $this->luecken ?? app(LohnLuecken::class);
    }

    private function sender(): InteraktivSender
    {
        return $this->sender ?? app(InteraktivSender::class);
    }
}
