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
 *  - beiEingang(): getippte Nachricht in einem unsichtbaren Lohn-Chat
 *    (RecConversationTopic::VERSTECKT). Geparkt und Akte jetzt vollstaendig →
 *    weiter wie unten. Sonst dieselbe offene Frage noch einmal (Liste,
 *    Agenda-Frage oder Pruef-Frage; hoechstens einmal je Stunde, gemessen an
 *    updated_at der Zeile). Kein aktiver MA mehr → freigeben.
 *  - freigebenWennVollstaendig(): Lauf recruiting:lohn-parken-freigeben.
 *    Akte vollstaendig → Dank, dann Agenda-Frage bzw. Anleitung + Pruef-Frage
 *    (Markus 28.10.: erst „Ich habe eine Frage“ zeigt HR den Chat). Kommt
 *    nichts an (24-h-Fenster zu), geht der Chat wie bisher zu HR.
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
        return $status === self::ERINNERT || $status === self::STILL || str_starts_with($status, 'weiter:');
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
        $topic = $this->versteckt($teamId, (int) $thread->id);
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
        if ($employee === null) {
            $this->freigeben($topic);

            return self::FREIGEGEBEN;
        }
        $duzen = PublicAddressStyle::informal($settings->getSetting('use_informal_address'));

        // Akte inzwischen vollstaendig: weiter zum naechsten Schritt (Agenda
        // bzw. Anleitung + Pruefschritt) statt direkt zu HR (Markus 28.10.).
        if ($topic->state === RecConversationTopic::STATE_GEPARKT) {
            $luecken = $this->luecken()->fuer($employee);
            if ($luecken === []) {
                return $this->weiterNachAkte($topic, $thread, $channel, $phone, $employee, $settings, $duzen);
            }
        }

        if ($topic->updated_at !== null && $topic->updated_at->getTimestamp() > Carbon::now()->getTimestamp() - self::ERINNERUNG_SEKUNDEN
            && !$this->erstesTippenNachFrage($topic, $thread, $message)) {
            return self::STILL;
        }

        // Dieselbe offene Frage noch einmal, hoechstens einmal je Stunde.
        $neuerZustand = $topic->state;
        if ($topic->state === RecConversationTopic::STATE_GEPARKT) {
            $r = $this->sendeListe($channel, $phone, $employee, $settings, $duzen, $luecken);
        } elseif ($topic->state === RecConversationTopic::STATE_AGENDA_FRAGE) {
            $r = $this->sendeAgendaFrage($channel, $phone, $duzen);
        } else {
            $r = $this->sendePruefFrage($channel, $phone, $duzen);
            $neuerZustand = RecConversationTopic::STATE_LOHN_PRUEFEN;
        }
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            Log::warning('recruiting.lohn_parken.erinnerung_nicht_gesendet', [
                'topic_id' => $topic->id, 'status' => $r['status'], 'error' => $r['error'],
            ]);

            // Nicht STILL: dann darf die Abwesenheitsnotiz laufen (Review 26.10. m8).
            return 'ignoriert:send_' . $r['status'];
        }
        $topic->forceFill(['state' => $neuerZustand, 'updated_at' => Carbon::now()])->save();

        return self::ERINNERT;
    }

    /** @return string 'geparkt' | 'weiter:<schritt>' | 'freigegeben' | 'freigegeben:<grund>' */
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
        $channel = CommsChannel::query()->find((int) $thread->comms_channel_id);
        if ($channel === null) {
            return $this->freigeben($topic, $thread) ? 'freigegeben:ohne_nachricht' : 'geparkt';
        }
        $duzen = PublicAddressStyle::informal($settings->getSetting('use_informal_address'));

        return $this->weiterNachAkte($topic, $thread, $channel, $phone, $employee, $settings, $duzen);
    }

    /**
     * Akte nachgereicht (Lauf oder Weiterschreiben): Dank, dann derselbe Weg
     * wie bei vollstaendiger Akte — Agenda-Frage bzw. Anleitung + Pruefschritt.
     * Der Chat bleibt fuer HR unsichtbar (Markus 28.10.). Erst beanspruchen,
     * dann senden: zwei Laeufe schicken nicht zweimal. Kommt nichts an
     * (24-h-Fenster zu), geht der Chat wie bisher zu HR — die Freigabe haengt
     * nie am Versand.
     */
    private function weiterNachAkte(RecConversationTopic $topic, CommsWhatsAppThread $thread, CommsChannel $channel, string $phone, RecEmployee $employee, ?RecApplicantSettings $settings, bool $duzen): string
    {
        $n = RecConversationTopic::query()
            ->whereKey($topic->id)
            ->where('state', RecConversationTopic::STATE_GEPARKT)
            ->update(['state' => RecConversationTopic::STATE_LOHN_PRUEFEN, 'updated_at' => Carbon::now()]);
        if ($n !== 1) {
            return 'geparkt';
        }
        $topic->state = RecConversationTopic::STATE_LOHN_PRUEFEN;
        $topic->syncOriginalAttribute('state');

        $dank = $this->sender()->knoepfe(
            $channel,
            $phone,
            ThemenTexte::lohnFrei($duzen, $settings?->getSetting('comms_topic_text_lohn_frei')),
            [['id' => self::ACK, 'title' => 'Alles klar']],
        );
        $schritt = $dank['status'] === InteraktivSender::STATUS_SENT
            ? $this->naechsterSchritt($channel, $phone, $employee, $settings, $duzen)
            : $dank + ['state' => null];
        if ($schritt['status'] !== InteraktivSender::STATUS_SENT) {
            Log::info('recruiting.lohn_parken.dank_nicht_gesendet', [
                'topic_id' => $topic->id, 'status' => $schritt['status'], 'error' => $schritt['error'],
            ]);

            return $this->freigeben($topic, $thread) ? 'freigegeben:ohne_nachricht' : 'geparkt';
        }
        RecConversationTopic::query()->whereKey($topic->id)->update(['state' => $schritt['state'], 'updated_at' => Carbon::now()]);
        $topic->state = $schritt['state'];
        $topic->syncOriginalAttribute('state');

        return $schritt['state'] === RecConversationTopic::STATE_AGENDA_FRAGE ? 'weiter:agenda' : 'weiter:pruefen';
    }

    /**
     * Akte vollstaendig: ohne (bekannten) Agenda-Zugang erst die Agenda-Frage,
     * sonst Anleitung + Pruefschritt. Liefert den Zustand, den die Zeile danach hat.
     *
     * @return array{status:string, message_id:?int, error:?string, state:string}
     */
    public function naechsterSchritt(CommsChannel $channel, string $phone, RecEmployee $employee, ?RecApplicantSettings $settings, bool $duzen): array
    {
        if ($employee->agenda_zugang !== true) {
            return $this->sendeAgendaFrage($channel, $phone, $duzen) + ['state' => RecConversationTopic::STATE_AGENDA_FRAGE];
        }

        return $this->hinweisUndPruefen($channel, $phone, $settings, $duzen) + ['state' => RecConversationTopic::STATE_LOHN_PRUEFEN];
    }

    /**
     * Anleitung (Link-Knopf, ohne URL Quittung), danach die Pruef-Frage. Das
     * Ergebnis ist das der Anleitung; scheitert nur die Pruef-Frage, bleibt
     * es beim Pruefschritt — die stuendliche Erinnerung stellt sie erneut.
     *
     * @return array{status:string, message_id:?int, error:?string}
     */
    public function hinweisUndPruefen(CommsChannel $channel, string $phone, ?RecApplicantSettings $settings, bool $duzen): array
    {
        $url = trim((string) ($settings?->getSetting('comms_topic_lohn_url') ?? ''));
        $body = ThemenTexte::lohnOk($duzen, $settings?->getSetting('comms_topic_text_lohn_ok'), $url !== '');
        $r = $url !== ''
            ? $this->sender()->linkKnopf($channel, $phone, $body, 'Anleitung', $url)
            : $this->sender()->knoepfe($channel, $phone, $body, [['id' => self::ACK, 'title' => 'Alles klar']]);
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            return $r;
        }
        $frage = $this->sendePruefFrage($channel, $phone, $duzen);
        if ($frage['status'] !== InteraktivSender::STATUS_SENT) {
            Log::info('recruiting.lohn_parken.pruef_frage_nicht_gesendet', ['status' => $frage['status'], 'error' => $frage['error']]);
        }

        return $r;
    }

    /**
     * Agenda- oder Pruef-Frage gerade erst gestellt und das ist die erste
     * getippte Nachricht danach (Review 28.10. (4) M1): sofort einmal die
     * Knoepfe wieder statt Stille — wer direkt sein Problem tippt, soll
     * erfahren, dass der Klick fehlt. Die Liste (geparkt) bleibt bei der
     * Stunden-Bremse wie bisher.
     */
    private function erstesTippenNachFrage(RecConversationTopic $topic, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): bool
    {
        if ($topic->state === RecConversationTopic::STATE_GEPARKT || $topic->updated_at === null) {
            return false;
        }

        return !CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', (int) $thread->id)
            ->where('direction', 'inbound')
            ->where('id', '<', (int) $message->id)
            ->where('sent_at', '>=', $topic->updated_at)
            ->get(['id', 'meta_payload'])
            ->contains(fn (CommsWhatsAppMessage $m) => !QuickReply::isQuickReply($m->meta_payload));
    }

    /** @return array{status:string, message_id:?int, error:?string} */
    public function sendeAgendaFrage(CommsChannel $channel, string $phone, bool $duzen): array
    {
        return $this->sender()->knoepfe($channel, $phone, ThemenTexte::agendaFrage($duzen), ThemenTexte::agendaKnoepfe());
    }

    /** @return array{status:string, message_id:?int, error:?string} */
    private function sendePruefFrage(CommsChannel $channel, string $phone, bool $duzen): array
    {
        return $this->sender()->knoepfe($channel, $phone, ThemenTexte::lohnPruefen($duzen), ThemenTexte::pruefenKnoepfe());
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

        $r = $url !== null
            ? $this->sender()->linkKnopf($channel, $phone, $body, 'Zum Portal', $url)
            : $this->sender()->knoepfe($channel, $phone, $body, [['id' => self::ACK, 'title' => 'Alles klar']]);
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            return $r;
        }

        // Ausweg zu Dispo/HR (28.10., Review I2 Variante b). Scheitert er,
        // bleibt es beim Parken — die Liste ist raus, das Ergebnis zaehlt.
        $andere = $this->sender()->knoepfe($channel, $phone, ThemenTexte::andereThemen($duzen), ThemenTexte::andereKnoepfe());
        if ($andere['status'] !== InteraktivSender::STATUS_SENT) {
            Log::info('recruiting.lohn_parken.andere_themen_nicht_gesendet', ['status' => $andere['status'], 'error' => $andere['error']]);
        }

        return $r;
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

    /** Juengste Zeile des Threads, nur wenn sie fuer HR unsichtbar ist (geparkt, Agenda-Frage, Pruefschritt). */
    private function versteckt(int $teamId, int $threadId): ?RecConversationTopic
    {
        $zeile = RecConversationTopic::aktuelleJeThread($teamId, [$threadId])[$threadId] ?? null;
        if ($zeile === null || !in_array($zeile->state, RecConversationTopic::VERSTECKT, true)) {
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
            ->whereIn('state', RecConversationTopic::VERSTECKT)
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
