<?php

namespace Platform\Recruiting\Services\Comms;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\Comms\ThemenAusloeser;
use Platform\Recruiting\Support\Comms\ThemenTexte;
use Platform\Recruiting\Support\PilotKreis;
use Platform\Recruiting\Support\PublicAddressStyle;

/**
 * Themen-Auswahl fuer Mitarbeiter (S-Themen §2.1/§2.2): entscheidet bei jedem
 * Eingang, ob die Frage „Worum geht es?“ mit drei Knoepfen rausgeht, und legt
 * dann die Anliegen-Zeile (state=asked) an. Bei aktiver Abwesenheit traegt die
 * Frage den Abwesenheitstext selbst — der Listener ueberspringt dann den
 * OOO-Handler, damit nur EINE Nachricht entsteht.
 *
 * Rueckgabe: 'gesendet' oder 'uebersprungen:<grund>' (fuer Log und Tests).
 */
final class ThemenAuswahlHandler
{
    public const GESENDET = 'gesendet';
    public const SCHON_GEFRAGT = 'uebersprungen:schon_gefragt';

    /**
     * Neutrale Werte fuer ThemenAusloeser: jede noch nicht geladene Bedingung
     * gilt als „bestanden“. So bleibt die reine Klasse die EINE Entscheidungs-
     * stelle, und die teuren Werte werden erst geladen, wenn die billigen
     * Pruefungen davor bestanden sind.
     */
    private const NEUTRAL = [
        'enabled' => true, 'hr_kanal' => true, 'fremder_kontext' => false, 'aktiver_ma' => true, 'pilot' => true,
        'quick_reply' => false, 'message_type' => 'text',
        'letzte_aktivitaet_ts' => null, 'jetzt_ts' => 0, 'ruhe_stunden' => 1,
    ];

    public function __construct(
        private readonly ?InteraktivSender $sender = null,
        private readonly ?ThemenAuswahlEmpfaenger $empfaenger = null,
        private readonly ?OooAutoReplyHandler $ooo = null,
    ) {}

    /**
     * Steckt die Abwesenheit fuer diesen Eingang schon in unserer Themen-Frage?
     * Dann darf der OOO-Handler nicht zusaetzlich senden — auch nicht bei einem
     * wiederholten Webhook fuer denselben Eingang.
     */
    public static function abwesenheitMitgeteilt(string $status): bool
    {
        return $status === self::GESENDET || $status === self::SCHON_GEFRAGT;
    }

    public function beiEingang(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): string
    {
        $teamId = (int) $channel->team_id;
        $k = self::NEUTRAL;
        $pruefe = static function (array $neu) use (&$k): ?string {
            $k = array_merge($k, $neu);

            return ThemenAusloeser::qualifiziert($k);
        };

        // 1. Schalter — nur lesen, nichts anlegen: Teams ohne Recruiting-
        //    Einstellungen (Helpdesk, Vertrieb) bekommen keine Zeile.
        $settings = RecApplicantSettings::query()->where('team_id', $teamId)->first();
        $schalterAn = $settings !== null && (bool) $settings->getSetting('comms_topic_enabled', false);
        if ($grund = $pruefe(['enabled' => $schalterAn])) {
            return 'uebersprungen:' . $grund;
        }

        // 2. HR-Kanal.
        $hrKanal = in_array((int) $channel->id, array_map('intval', RecruitingChannelResolver::channelIds($teamId)), true);
        if ($grund = $pruefe(['hr_kanal' => $hrKanal])) {
            return 'uebersprungen:' . $grund;
        }

        // 3. Klick / Sprachnachricht — ohne DB.
        if ($grund = $pruefe([
            'quick_reply' => QuickReply::isQuickReply($message->meta_payload),
            'message_type' => (string) ($message->message_type ?? 'text'),
        ])) {
            return 'uebersprungen:' . $grund;
        }

        // 4. Kontext. Mitarbeiter-Kontext ist kein fremder Fachprozess: der
        //    Listener haengt MA-Threads an RecEmployee, und genau die sollen die
        //    Frage bekommen. ThreadContextGate kennt RecEmployee nicht (es
        //    entscheidet ueber den Bewerber-Intake) — deshalb vorher herausnehmen.
        $kontexte = $thread->contexts()->pluck('context_model')
            ->push($thread->context_model)
            ->filter()
            ->unique()
            ->reject(fn ($m) => $m === RecEmployee::class)
            ->all();
        if ($grund = $pruefe(['fremder_kontext' => ThreadContextGate::blocksIntakeAny($kontexte)])) {
            return 'uebersprungen:' . $grund;
        }

        // 5. Ruhezeit (eine indizierte Abfrage).
        if ($grund = $pruefe([
            'letzte_aktivitaet_ts' => $this->letzteAktivitaetVor($thread, $message),
            'jetzt_ts' => ($message->sent_at ?? $message->created_at ?? Carbon::now())->getTimestamp(),
            'ruhe_stunden' => max(1, (int) $settings->getSetting('comms_topic_quiet_hours', 8)),
        ])) {
            return 'uebersprungen:' . $grund;
        }

        // 6. Meta wiederholt Webhooks, der Core feuert das Event auch fuer die
        //    Dublette. Unsere Frage liegt mit hoeherer id hinter dem Eingang und
        //    zaehlt deshalb nicht in die Ruhezeit — ohne diese Sperre fragten wir doppelt.
        if (RecConversationTopic::query()
            ->where('comms_whatsapp_thread_id', (int) $thread->id)
            ->where('origin_message_id', (int) $message->id)
            ->exists()) {
            return self::SCHON_GEFRAGT;
        }

        // 7. Geblockte/geblacklistete Kontakte bekommen keine Auto-Antwort
        //    (dieselbe Regel wie beim OOO-Handler).
        $phone = (string) ($thread->remote_phone_number ?? '');
        if ($phone !== '' && KontaktSperre::gesperrt($thread, $teamId, $phone)) {
            return 'uebersprungen:kontakt_gesperrt';
        }

        // 8. Zuletzt die teure Personen-Suche (laedt alle aktiven MA mit Nummer).
        $mitarbeiterId = $phone !== ''
            ? ($this->empfaenger ?? app(ThemenAuswahlEmpfaenger::class))->aktiverMitarbeiter($phone, $teamId)
            : null;
        if ($grund = $pruefe(['aktiver_ma' => $mitarbeiterId !== null])) {
            return 'uebersprungen:' . $grund;
        }

        // 9. Pilot-Sperre (Sebastian 19.10.2026: neue Features nur fuer die
        //    Pilotgruppe). Vorgabe an; der Personen-Schnitt steht in PilotKreis —
        //    ein RG+MA, dessen MA-Zeile umgestellt ist, schreibt auch ueber die
        //    RG-Nummer als Pilot.
        $nurPilot = (bool) $settings->getSetting('comms_topic_nur_pilot', true);
        if ($grund = $pruefe(['pilot' => !$nurPilot || PilotKreis::istImPilot((int) $mitarbeiterId)])) {
            return 'uebersprungen:' . $grund;
        }

        $duzen = PublicAddressStyle::informal($settings->getSetting('use_informal_address'));
        $vorspann = ($this->ooo ?? app(OooAutoReplyHandler::class))->vorspann($teamId);
        $body = ThemenTexte::frage($duzen, $settings->getSetting('comms_topic_text_ask'), $vorspann);

        $r = ($this->sender ?? app(InteraktivSender::class))->knoepfe($channel, $phone, $body, ThemenTexte::knoepfe());
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            Log::warning('recruiting.themen.frage_nicht_gesendet', [
                'thread_id' => $thread->id,
                'status' => $r['status'],
                'error' => $r['error'],
            ]);

            return 'uebersprungen:send_' . $r['status'];
        }

        // Die Frage ist draussen — ab hier gilt GESENDET, auch wenn die Zeile
        // nicht geschrieben werden kann. Sonst schickte der Listener zusaetzlich
        // die OOO-Vorlage hinterher.
        try {
            RecConversationTopic::create([
                'team_id' => $teamId,
                'comms_whatsapp_thread_id' => (int) $thread->id,
                'topic' => null,
                'state' => RecConversationTopic::STATE_ASKED,
                'origin_message_id' => (int) $message->id,
                'ask_message_id' => $r['message_id'],
                'asked_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('recruiting.themen.zeile_nicht_angelegt', [
                'thread_id' => $thread->id,
                'message_id' => $message->id,
                'ask_message_id' => $r['message_id'],
                'error' => $e->getMessage(),
            ]);
        }

        return self::GESENDET;
    }

    /** Juengste Nachricht im Thread VOR dieser — egal welche Richtung, egal ob Auto-Antwort. */
    private function letzteAktivitaetVor(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): ?int
    {
        $ts = CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', (int) $thread->id)
            ->where('id', '<', (int) $message->id)
            ->max(DB::raw('COALESCE(sent_at, created_at)'));

        return $ts ? Carbon::parse((string) $ts)->getTimestamp() : null;
    }
}
