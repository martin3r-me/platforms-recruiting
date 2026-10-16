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

    public function __construct(
        private readonly ?InteraktivSender $sender = null,
        private readonly ?ThemenAuswahlEmpfaenger $empfaenger = null,
        private readonly ?OooAutoReplyHandler $ooo = null,
    ) {}

    public function beiEingang(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): string
    {
        $teamId = (int) $channel->team_id;
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $phone = (string) ($thread->remote_phone_number ?? '');

        // Mitarbeiter-Kontext ist kein fremder Fachprozess: der Listener haengt
        // MA-Threads an RecEmployee, und genau die sollen die Frage bekommen.
        // ThreadContextGate kennt RecEmployee nicht (es entscheidet ueber den
        // Bewerber-Intake) — deshalb hier vor der Pruefung herausnehmen.
        $kontexte = $thread->contexts()->pluck('context_model')
            ->push($thread->context_model)
            ->filter()
            ->unique()
            ->reject(fn ($k) => $k === RecEmployee::class)
            ->all();

        $schalterAn = (bool) $settings->getSetting('comms_topic_enabled', false);
        $hrKanal = $schalterAn
            && in_array((int) $channel->id, array_map('intval', RecruitingChannelResolver::channelIds($teamId)), true);
        $fremderKontext = ThreadContextGate::blocksIntakeAny($kontexte);

        $grund = ThemenAusloeser::qualifiziert([
            'enabled'              => $schalterAn,
            'hr_kanal'             => $hrKanal,
            'fremder_kontext'      => $fremderKontext,
            // Personen-Suche nur, wenn die billigen Pruefungen davor bestanden sind
            // (sie laeuft bei jedem Eingang auf jedem Kanal).
            'aktiver_ma'           => $hrKanal && !$fremderKontext && $phone !== ''
                && ($this->empfaenger ?? app(ThemenAuswahlEmpfaenger::class))->aktiverMitarbeiter($phone, $teamId) !== null,
            'quick_reply'          => QuickReply::isQuickReply($message->meta_payload),
            'message_type'         => (string) ($message->message_type ?? 'text'),
            'letzte_aktivitaet_ts' => $this->letzteAktivitaetVor($thread, $message),
            'jetzt_ts'             => ($message->sent_at ?? $message->created_at ?? Carbon::now())->getTimestamp(),
            'ruhe_stunden'         => max(1, (int) $settings->getSetting('comms_topic_quiet_hours', 8)),
        ]);
        if ($grund !== null) {
            return 'uebersprungen:' . $grund;
        }

        // Meta wiederholt Webhooks, der Core feuert das Event auch fuer die
        // Dublette. Unsere Frage liegt mit hoeherer id hinter dem Eingang und
        // zaehlt deshalb nicht in die Ruhezeit — ohne diese Sperre fragten wir doppelt.
        if (RecConversationTopic::query()
            ->where('comms_whatsapp_thread_id', (int) $thread->id)
            ->where('origin_message_id', (int) $message->id)
            ->exists()) {
            return 'uebersprungen:schon_gefragt';
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

        RecConversationTopic::create([
            'team_id' => $teamId,
            'comms_whatsapp_thread_id' => (int) $thread->id,
            'topic' => null,
            'state' => RecConversationTopic::STATE_ASKED,
            'origin_message_id' => (int) $message->id,
            'ask_message_id' => $r['message_id'],
            'asked_at' => Carbon::now(),
        ]);

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
