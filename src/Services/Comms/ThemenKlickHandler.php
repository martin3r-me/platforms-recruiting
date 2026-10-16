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
use Platform\Recruiting\Services\Comms\Forward\ConversationForwarder;
use Platform\Recruiting\Services\Comms\Forward\DispoForwardChannels;
use Platform\Recruiting\Services\Comms\Forward\ForwardTargets;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Support\Comms\ThemenTexte;
use Platform\Recruiting\Support\PhoneE164;
use Platform\Recruiting\Support\PublicAddressStyle;

/**
 * Themen-Auswahl, Klicks (S-Themen §2.3–2.6): verarbeitet den Klick eines
 * Mitarbeiters auf UNSERE Knoepfe (topic_*, dispo_<kanal>, topic_ack).
 *
 *  - topic_dispo  → Filialen als Knoepfe (bis 3) bzw. Liste (ab 4).
 *  - dispo_<id>   → wa.me-Link zur gewaehlten Dispo; NUR wenn der Link
 *                   rausging: automatische Weiterleitung der Ursprungsnachricht
 *                   in die Dispo-Postfach-Karte + Stempel routed_dispo.
 *  - topic_lohn   → Hinweis aus der Akte (OffenePunkte) bzw. Login-Anleitung.
 *                   Kein Stempel — eine echte Lohnfrage bleibt bei HR.
 *  - topic_hr     → nur das Thema setzen.
 *  - topic_ack    → Quittungsknopf ohne Wirkung (s. sendeHinweis()).
 *
 * Die IDs kommen von WhatsApp und sind damit frei waehlbar: eine Dispo-ID muss
 * in DispoForwardChannels::options(Team) stehen, sonst passiert nichts.
 *
 * Idempotenz: Meta wiederholt Webhooks, der Core feuert das Event auch fuer die
 * Dublette. Der Klick wird ueber last_click_message_id ATOMAR beansprucht
 * (bedingtes UPDATE); scheitert die Verarbeitung (Send abgelehnt), wird der
 * Anspruch zurueckgegeben — die Zeile ist danach wie vorher, ein wiederholter
 * Webhook darf es noch einmal versuchen.
 *
 * Rueckgabe: 'verarbeitet:<id>' oder 'ignoriert:<grund>' (Log und Tests).
 */
final class ThemenKlickHandler
{
    public const SCHON_VERARBEITET = 'ignoriert:schon_verarbeitet';

    private const ACK = 'topic_ack';
    private const KOMMENTAR = 'Automatisch: Mitarbeiter hat Thema Disposition gewählt';
    private const VORBELEGUNG_OHNE_TEXT = 'Hallo, ich habe eine Frage zu meinem Einsatz.';
    private const LISTEN_KNOPF = 'Dispo wählen';
    private const MAX_LISTE = 10;
    private const MAX_TEXT = 1000;
    private const MAX_URL = 2000;

    /** Eine Instanz je Handler: DispoForwardChannels merkt sich die Optionen je Team. */
    private ?DispoForwardChannels $kanaeleMemo = null;

    public function __construct(
        private readonly ?InteraktivSender $sender = null,
        private readonly ?ThemenAuswahlEmpfaenger $empfaenger = null,
        private readonly ?DispoForwardChannels $kanaele = null,
        private readonly ?ConversationForwarder $forwarder = null,
        private readonly ?OffenePunkte $offenePunkte = null,
    ) {}

    /**
     * Hat dieser Eingang schon eine Antwort von uns bekommen (bzw. bei einem
     * wiederholten Webhook: beim ersten Mal)? Dann darf der Listener nicht
     * zusaetzlich die OOO-Vorlage schicken.
     */
    public static function beantwortet(string $status): bool
    {
        return str_starts_with($status, 'verarbeitet:') || $status === self::SCHON_VERARBEITET;
    }

    public function beiEingang(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): string
    {
        $id = QuickReply::auswahlId($message->meta_payload);
        if ($message->direction !== 'inbound' || $id === null || !QuickReply::istThemenKlick($message->meta_payload)) {
            return 'ignoriert:kein_themen_klick';
        }

        // Quittung nach einem Lohn-Hinweis: nichts tun, nur nicht stempeln
        // (der Stamper nimmt topic_* aus) — der Chat bleibt offen.
        if ($id === self::ACK) {
            return 'verarbeitet:' . self::ACK;
        }

        $teamId = (int) $thread->team_id;
        $settings = RecApplicantSettings::query()->where('team_id', $teamId)->first();
        $ruheStunden = max(1, (int) ($settings?->getSetting('comms_topic_quiet_hours', 8) ?? 8));

        // Aktuelles Anliegen: juengste Frage dieses Threads innerhalb der Ruhezeit.
        $topic = RecConversationTopic::query()
            ->where('team_id', $teamId)
            ->where('comms_whatsapp_thread_id', (int) $thread->id)
            ->where('asked_at', '>=', Carbon::now()->subHours($ruheStunden))
            ->orderByDesc('asked_at')
            ->orderByDesc('id')
            ->first();
        if ($topic === null) {
            return 'ignoriert:keine_frage';
        }

        $vorher = $topic->last_click_message_id !== null ? (int) $topic->last_click_message_id : null;
        if ($vorher !== null && $vorher >= (int) $message->id) {
            return self::SCHON_VERARBEITET;
        }

        // Atomar beanspruchen — zwei gleichzeitige Dubletten: nur eine gewinnt.
        $beansprucht = RecConversationTopic::query()
            ->whereKey($topic->id)
            ->where(fn ($q) => $q->whereNull('last_click_message_id')->orWhere('last_click_message_id', '<', (int) $message->id))
            ->update(['last_click_message_id' => (int) $message->id]);
        if ($beansprucht !== 1) {
            return self::SCHON_VERARBEITET;
        }
        $topic->last_click_message_id = (int) $message->id;
        $topic->syncOriginalAttribute('last_click_message_id');

        try {
            $status = $this->verarbeite($id, $channel, $thread, $message, $topic, $settings, $teamId);
        } catch (\Throwable $e) {
            $this->freigeben($topic, (int) $message->id, $vorher);

            throw $e;
        }

        if (!str_starts_with($status, 'verarbeitet:')) {
            $this->freigeben($topic, (int) $message->id, $vorher);
        }

        return $status;
    }

    private function verarbeite(
        string $id,
        CommsChannel $channel,
        CommsWhatsAppThread $thread,
        CommsWhatsAppMessage $message,
        RecConversationTopic $topic,
        ?RecApplicantSettings $settings,
        int $teamId,
    ): string {
        $duzen = PublicAddressStyle::informal($settings?->getSetting('use_informal_address'));
        $phone = (string) ($thread->remote_phone_number ?? '');

        if (str_starts_with($id, 'dispo_')) {
            return $this->filialeGewaehlt($id, $channel, $thread, $message, $topic, $teamId, $duzen, $phone);
        }

        return match ($id) {
            'topic_dispo' => $this->dispoGewaehlt($channel, $topic, $teamId, $duzen, $phone),
            'topic_lohn' => $this->lohnGewaehlt($channel, $topic, $settings, $teamId, $duzen, $phone),
            'topic_hr' => $this->themaSetzen($topic, RecConversationTopic::TOPIC_HR, RecConversationTopic::STATE_CHOSEN, 'topic_hr'),
            default => 'ignoriert:unbekannte_auswahl',
        };
    }

    // ------------------------------------------------------------ Disposition

    private function dispoGewaehlt(CommsChannel $channel, RecConversationTopic $topic, int $teamId, bool $duzen, string $phone): string
    {
        // Nur Filialen mit gueltiger Nummer anbieten — ein falscher wa.me-Link
        // fuehrte den MA ins Leere (Praezedenzfall duty_phone).
        $nummern = $this->waNummern($teamId);
        $optionen = array_values(array_filter(
            $this->kanaele()->options($teamId),
            fn (array $o) => isset($nummern[$o['channel_id']]),
        ));
        if ($optionen === []) {
            Log::warning('recruiting.themen.keine_filialen', ['team_id' => $teamId, 'topic_id' => $topic->id]);

            return 'ignoriert:keine_filialen';
        }

        $body = ThemenTexte::filialFrage($duzen);
        if (count($optionen) <= 3) {
            $r = $this->sender()->knoepfe($channel, $phone, $body, array_map(
                fn (array $o) => ['id' => 'dispo_' . $o['channel_id'], 'title' => ThemenTexte::filialKnopf($o['label'])],
                $optionen,
            ));
        } else {
            if (count($optionen) > self::MAX_LISTE) {
                Log::warning('recruiting.themen.zu_viele_filialen', ['team_id' => $teamId, 'anzahl' => count($optionen)]);
            }
            $r = $this->sender()->liste($channel, $phone, $body, self::LISTEN_KNOPF, array_map(
                fn (array $o) => ['id' => 'dispo_' . $o['channel_id'], 'title' => ThemenTexte::listenTitel($o['label'])],
                array_slice($optionen, 0, self::MAX_LISTE),
            ));
        }
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            return $this->sendGescheitert('filialen', $topic, $r);
        }

        return $this->themaSetzen($topic, RecConversationTopic::TOPIC_DISPO, RecConversationTopic::STATE_CHOSEN, 'topic_dispo');
    }

    private function filialeGewaehlt(
        string $id,
        CommsChannel $channel,
        CommsWhatsAppThread $thread,
        CommsWhatsAppMessage $message,
        RecConversationTopic $topic,
        int $teamId,
        bool $duzen,
        string $phone,
    ): string {
        // Nur Ziffern nach dem Praefix, und nur Kanaele aus der Auswahl DIESES Teams.
        $kanalId = preg_match('/^dispo_(\d{1,18})$/', $id, $m) ? (int) $m[1] : null;
        $label = $kanalId !== null ? $this->kanaele()->labelFor($teamId, $kanalId) : null;
        if ($label === null) {
            return 'ignoriert:unbekannte_filiale';
        }
        if ($topic->topic !== RecConversationTopic::TOPIC_DISPO) {
            return 'ignoriert:falsches_thema';
        }
        // Doppel-Tipp oder alter Knopf nach der Umleitung: kein zweiter Link,
        // keine zweite Karte bei der Dispo (Review Task 4, M1).
        if ($topic->state === RecConversationTopic::STATE_ROUTED) {
            return 'ignoriert:schon_weitergeleitet';
        }

        $nummer = $this->waNummern($teamId)[$kanalId] ?? null;
        if ($nummer === null) {
            Log::warning('recruiting.themen.filiale_ohne_gueltige_nummer', ['team_id' => $teamId, 'kanal_id' => $kanalId]);

            return 'ignoriert:kanal_ohne_nummer';
        }

        // Alles Getippte seit der letzten Antwort/dem letzten Stempel — der
        // Stempel unten legt es fuer HR ab, also muss es bei der Dispo ankommen
        // (Review Task 4, I1).
        $eingaenge = $this->getippteEingaenge($thread, $message, $topic);
        $url = self::waMeUrl($nummer, $this->vorbelegung($eingaenge));
        $r = $this->sender()->linkKnopf($channel, $phone, ThemenTexte::dispoLink($duzen, $label), ThemenTexte::filialKnopf('Zur ' . $label), $url);

        // Die Weiterleitung ist das Sicherheitsnetz: sie geht auch dann raus,
        // wenn Meta den Link ablehnt — das Anliegen soll bei der Dispo ankommen
        // (Review Task 4, M3). dispo_channel_id merkt sich „schon weitergeleitet“,
        // damit ein Wiederholversuch keine zweite Karte anlegt.
        if ((int) ($topic->dispo_channel_id ?? 0) !== $kanalId
            && $this->weiterleiten($thread, $topic, $teamId, $kanalId, $phone, $eingaenge->pluck('id')->all())) {
            $topic->forceFill(['dispo_channel_id' => $kanalId])->save();
        }

        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            // Kein Stempel: ohne Link bleibt der Chat bei HR sichtbar.
            return $this->sendGescheitert('dispo_link', $topic, $r);
        }

        $this->stempeln($thread, $message);
        $topic->forceFill([
            'state' => RecConversationTopic::STATE_ROUTED,
            'dispo_channel_id' => $kanalId,
        ])->save();

        return 'verarbeitet:' . $id;
    }

    /**
     * Getippte (Nicht-Klick-)Eingaenge vor dem Klick, die HR noch nicht
     * beantwortet bzw. abgelegt hat — dieselbe Grenze wie im QuickReplyStamper.
     * Nie leer, solange es den Ursprung gibt.
     *
     * @return \Illuminate\Support\Collection<int, CommsWhatsAppMessage>
     */
    private function getippteEingaenge(CommsWhatsAppThread $thread, CommsWhatsAppMessage $klick, RecConversationTopic $topic): \Illuminate\Support\Collection
    {
        $threadId = (int) $thread->id;
        $letzteAntwortId = (int) CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', $threadId)
            ->where('direction', 'outbound')
            ->where('is_auto_reply', false)
            ->max('id');
        $stempelAt = RecConversationHandled::query()
            ->where('comms_whatsapp_thread_id', $threadId)
            ->value('handled_at');

        $eingaenge = CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', $threadId)
            ->where('direction', 'inbound')
            ->where('id', '>', $letzteAntwortId)
            ->where('id', '<', (int) $klick->id)
            ->when($stempelAt !== null, fn ($q) => $q->where('sent_at', '>', $stempelAt))
            ->orderBy('id')
            ->get(['id', 'body', 'meta_payload'])
            ->reject(fn (CommsWhatsAppMessage $m) => QuickReply::isQuickReply($m->meta_payload))
            ->values();

        if ($eingaenge->isEmpty() && $topic->origin_message_id !== null) {
            $eingaenge = CommsWhatsAppMessage::query()
                ->whereKey((int) $topic->origin_message_id)
                ->where('comms_whatsapp_thread_id', $threadId)
                ->get(['id', 'body', 'meta_payload']);
        }

        return $eingaenge;
    }

    /** @param \Illuminate\Support\Collection<int, CommsWhatsAppMessage> $eingaenge */
    private function vorbelegung(\Illuminate\Support\Collection $eingaenge): string
    {
        $text = $eingaenge->map(fn ($m) => trim((string) $m->body))->filter(fn ($t) => $t !== '')->implode("\n");

        return $text === '' ? self::VORBELEGUNG_OHNE_TEXT : $text;
    }

    /** Text auf 1000 Zeichen, die ganze URL auf 2000 Zeichen gekappt (Review Task 4, M3). */
    public static function waMeUrl(string $nummer, string $text): string
    {
        $basis = 'https://wa.me/' . $nummer . '?text=';
        $text = mb_substr($text, 0, self::MAX_TEXT);
        while ($text !== '' && strlen($basis . rawurlencode($text)) > self::MAX_URL) {
            $text = mb_substr($text, 0, max(0, mb_strlen($text) - 20));
        }

        return $basis . rawurlencode($text);
    }

    /**
     * wa.me-Nummern der Dispo-Kanaele dieses Teams: E.164 ohne „+“. Ungueltige
     * Nummern fehlen (Review Task 4, M4) — nationale Schreibweise wird normalisiert.
     *
     * @return array<int, string> channel_id => Ziffern
     */
    private function waNummern(int $teamId): array
    {
        $ids = array_column($this->kanaele()->options($teamId), 'channel_id');
        if ($ids === []) {
            return [];
        }
        $nummern = [];
        foreach (CommsChannel::query()->whereIn('id', $ids)->where('team_id', $teamId)->get(['id', 'sender_identifier']) as $c) {
            $e164 = PhoneE164::normalize((string) $c->sender_identifier);
            if ($e164 === null) {
                Log::warning('recruiting.themen.dispo_nummer_ungueltig', ['team_id' => $teamId, 'kanal_id' => $c->id]);
                continue;
            }
            $nummern[(int) $c->id] = ltrim($e164, '+');
        }

        return $nummern;
    }

    /** @param list<int> $messageIds */
    private function weiterleiten(CommsWhatsAppThread $thread, RecConversationTopic $topic, int $teamId, int $kanalId, string $phone, array $messageIds): bool
    {
        try {
            $employee = $this->mitarbeiter($phone, $teamId);
            $name = $employee !== null ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) : '';
            $r = $this->forwarder()->forward(
                $teamId,
                $thread,
                $messageIds,
                self::KOMMENTAR,
                $employee?->id !== null ? (int) $employee->id : null,
                $name !== '' ? $name : $phone,
                null,
                ForwardTargets::DISPO,
                ForwardTargets::SOURCE_HR,
                $kanalId,
            );
            if (!$r['ok']) {
                Log::error('recruiting.themen.weiterleitung_abgelehnt', [
                    'thread_id' => $thread->id, 'topic_id' => $topic->id, 'kanal_id' => $kanalId, 'error' => $r['error'],
                ]);
            }

            return (bool) $r['ok'];
        } catch (\Throwable $e) {
            Log::error('recruiting.themen.weiterleitung_fehlgeschlagen', [
                'thread_id' => $thread->id, 'topic_id' => $topic->id, 'kanal_id' => $kanalId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Wie QuickReplyStamper::stampIfQuickReply — Zeitpunkt des Klicks, ein neuer Eingang hebt ihn auf. */
    private function stempeln(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): void
    {
        $now = Carbon::now();
        RecConversationHandled::query()->upsert(
            [[
                'team_id' => (int) $thread->team_id,
                'comms_whatsapp_thread_id' => (int) $thread->id,
                'handled_at' => $message->sent_at ?? $message->created_at ?? $now,
                'handled_by_user_id' => null,
                'handled_reason' => RecConversationHandled::REASON_ROUTED_DISPO,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['comms_whatsapp_thread_id'],
            ['team_id', 'handled_at', 'handled_by_user_id', 'handled_reason', 'updated_at'],
        );
    }

    // ------------------------------------------------------------ Lohn

    private function lohnGewaehlt(
        CommsChannel $channel,
        RecConversationTopic $topic,
        ?RecApplicantSettings $settings,
        int $teamId,
        bool $duzen,
        string $phone,
    ): string {
        $employee = $this->mitarbeiter($phone, $teamId);
        if ($employee === null) {
            return 'ignoriert:kein_aktiver_ma';
        }

        $punkte = $this->offenePunkte()->fuer($employee)['punkte'] ?? [];
        if ($punkte !== []) {
            $body = ThemenTexte::lohnOffen($duzen, $settings?->getSetting('comms_topic_text_lohn_offen'), array_column($punkte, 'label'));
            $url = $employee->portal_v2_since !== null && (string) $employee->portal_token !== ''
                ? route('recruiting.public.employee-portal', ['token' => $employee->portal_token])
                : null;
            $r = $this->sendeHinweis($channel, $phone, $body, 'Zum Portal', $url);
            $state = RecConversationTopic::STATE_AKTE_HINT;
        } else {
            $body = ThemenTexte::lohnOk($duzen, $settings?->getSetting('comms_topic_text_lohn_ok'));
            $url = trim((string) ($settings?->getSetting('comms_topic_lohn_url') ?? ''));
            $r = $this->sendeHinweis($channel, $phone, $body, 'Anleitung', $url !== '' ? $url : null);
            $state = RecConversationTopic::STATE_LOHN_INFO;
        }
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            return $this->sendGescheitert('lohn', $topic, $r);
        }

        // Kein Stempel: der Chat bleibt bei HR offen (§2.4).
        return $this->themaSetzen($topic, RecConversationTopic::TOPIC_LOHN, $state, 'topic_lohn');
    }

    /**
     * Mit URL ein Link-Knopf. Ohne URL ein einzelner Quittungsknopf statt
     * reinem Text: WhatsAppMetaService::sendText() kennt kein Auto-Antwort-
     * Kennzeichen — ein reiner Text zaehlte als menschliche HR-Antwort und
     * verfaelschte Ungelesen/Followup. Der Klick darauf (topic_ack) ist ein No-op.
     *
     * @return array{status:string, message_id:?int, error:?string}
     */
    private function sendeHinweis(CommsChannel $channel, string $phone, string $body, string $knopf, ?string $url): array
    {
        return $url !== null
            ? $this->sender()->linkKnopf($channel, $phone, $body, $knopf, $url)
            : $this->sender()->knoepfe($channel, $phone, $body, [['id' => self::ACK, 'title' => 'Alles klar']]);
    }

    // ------------------------------------------------------------ Gemeinsam

    private function themaSetzen(RecConversationTopic $topic, string $thema, string $state, string $id): string
    {
        $topic->forceFill([
            'topic' => $thema,
            'state' => $state,
            'chosen_at' => Carbon::now(),
        ])->save();

        return 'verarbeitet:' . $id;
    }

    /** @param array{status:string, message_id:?int, error:?string} $r */
    private function sendGescheitert(string $was, RecConversationTopic $topic, array $r): string
    {
        Log::warning('recruiting.themen.klick_antwort_nicht_gesendet', [
            'was' => $was,
            'topic_id' => $topic->id,
            'thread_id' => $topic->comms_whatsapp_thread_id,
            'status' => $r['status'],
            'error' => $r['error'],
        ]);

        return 'ignoriert:send_' . $r['status'];
    }

    /** Anspruch zurueckgeben — nur, wenn er noch unserer ist. */
    private function freigeben(RecConversationTopic $topic, int $messageId, ?int $vorher): void
    {
        RecConversationTopic::query()
            ->whereKey($topic->id)
            ->where('last_click_message_id', $messageId)
            ->update(['last_click_message_id' => $vorher]);
        $topic->last_click_message_id = $vorher;
        $topic->syncOriginalAttribute('last_click_message_id');
    }

    private function mitarbeiter(string $phone, int $teamId): ?RecEmployee
    {
        if ($phone === '') {
            return null;
        }
        $id = $this->empfaenger()->aktiverMitarbeiter($phone, $teamId);

        return $id === null ? null : RecEmployee::query()->where('team_id', $teamId)->find($id);
    }

    private function sender(): InteraktivSender
    {
        return $this->sender ?? app(InteraktivSender::class);
    }

    private function empfaenger(): ThemenAuswahlEmpfaenger
    {
        return $this->empfaenger ?? app(ThemenAuswahlEmpfaenger::class);
    }

    private function kanaele(): DispoForwardChannels
    {
        return $this->kanaeleMemo ??= $this->kanaele ?? app(DispoForwardChannels::class);
    }

    private function forwarder(): ConversationForwarder
    {
        return $this->forwarder ?? new ConversationForwarder($this->kanaele());
    }

    private function offenePunkte(): OffenePunkte
    {
        return $this->offenePunkte ?? app(OffenePunkte::class);
    }
}
