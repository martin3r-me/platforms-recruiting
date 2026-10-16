<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Database\QueryException;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsLog;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Models\RecEmployee;

/**
 * HR-Abwesenheitsmodus: schickt bei aktivem OOO automatisch das konfigurierte
 * Abwesenheits-Template auf eingehende Nachrichten zurueck. Gedrosselt auf
 * 1x/24h je Konversation. Gilt fuer Bewerber-, Mitarbeiter- und kontextlose
 * Threads — nie fuer Fremd-Kontexte (Helpdesk, Sales, ...).
 *
 * Der Send wird mit is_auto_reply=true markiert und zaehlt damit NICHT als
 * Antwort im "verpasst"-Zaehler (InboxQuery).
 */
final class OooAutoReplyHandler
{
    public const SETTINGS_KEY = 'comms_ooo_template_id';

    public function __construct(private readonly HoldingTemplateSender $sender) {}

    public function handle(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): void
    {
        if (!$this->isEligibleContext($thread->context_model)) {
            return;
        }

        $teamId = (int) $channel->team_id;
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);

        if (!$this->istAktiv($settings)) {
            return;
        }

        $phone = (string) ($thread->remote_phone_number ?? '');
        if ($phone === '') {
            return;
        }

        // Die Themen-Frage (S-Themen §2.1) traegt bei aktivem OOO den
        // Abwesenheitstext selbst. Ging sie in den letzten 24h an diesen Thread,
        // ist die Abwesenheit schon mitgeteilt — die Vorlagen-Drosselung unten
        // sieht das nicht, weil die Frage keine Vorlage ist.
        if ($this->abwesenheitInThemenFrage($thread)) {
            return;
        }

        if (KontaktSperre::gesperrt($thread, $teamId, $phone)) {
            CommsLog::log(
                event: 'ooo_autoreply_skipped_blacklisted',
                status: 'info',
                summary: "OOO-Auto-Reply uebersprungen: Kontakt geblockt/geblacklistet ({$phone})",
                details: ['thread_id' => $thread->id],
                extra: [
                    'team_id' => $teamId,
                    'channel_type' => 'whatsapp',
                    'channel_id' => $channel->id,
                    'source' => 'recruiting_ooo_autoreply',
                    'recipient' => $phone,
                ],
            );
            return;
        }

        // Feature aktiv? (Template konfiguriert UND bei Meta genehmigt)
        $templateName = $this->sender->configuredTemplateName($teamId, self::SETTINGS_KEY);
        if ($templateName === null) {
            return;
        }

        // Drosselung: 1x/24h je Konversation, gekeyt pro Thread + Template-Name
        // (gleiches Muster wie VoiceNoteAutoReplyHandler — kein Cross-Blocking).
        $last = CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', $thread->id)
            ->where('direction', 'outbound')
            ->where('template_name', $templateName)
            ->latest('created_at')
            ->first();

        if (VoiceNoteAutoReplyThrottle::shouldSkip($last?->created_at?->getTimestamp(), time())) {
            return;
        }

        $fmt = static fn (?string $ymd): string => $ymd ? \Carbon\Carbon::parse($ymd)->format('d.m.Y') : '';
        $namedValues = [
            'von'       => $fmt($settings->getSetting('comms_ooo_from')),
            'bis'       => $fmt($settings->getSetting('comms_ooo_until')),
            'wieder_da' => $fmt($settings->getSetting('comms_ooo_back_at')),
        ];

        // firstName bewusst leer — das OOO-Template nutzt kein {{name}}.
        $result = $this->sender->sendOne($teamId, $phone, '', self::SETTINGS_KEY, $namedValues, true);

        CommsLog::log(
            event: 'ooo_autoreply_sent',
            status: ($result['error'] === null && ($result['sent'] ?? 0) > 0) ? 'success' : 'error',
            summary: $result['error'] === null
                ? "OOO-Abwesenheitsnotiz an {$phone} gesendet"
                : "OOO-Abwesenheitsnotiz fehlgeschlagen: {$result['error']}",
            details: ['thread_id' => $thread->id, 'template' => $templateName, 'result' => $result],
            extra: [
                'team_id' => $teamId,
                'channel_type' => 'whatsapp',
                'channel_id' => $channel->id,
                'source' => 'recruiting_ooo_autoreply',
                'recipient' => $phone,
            ],
        );
    }

    /**
     * Abwesenheitstext als Freitext fuer die Themen-Frage (S-Themen §2.1): bei
     * aktivem OOO „Wir sind vom 20.10. bis 24.10. nicht erreichbar und ab 27.10.
     * wieder da.“ — fehlende Teile fallen weg. null, wenn OOO nicht aktiv ist.
     */
    public function vorspann(int $teamId): ?string
    {
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        if (!$this->istAktiv($settings)) {
            return null;
        }

        $fmt = static fn ($ymd): ?string => $ymd ? \Carbon\Carbon::parse((string) $ymd)->format('d.m.') : null;
        $von = $fmt($settings->getSetting('comms_ooo_from'));
        $bis = $fmt($settings->getSetting('comms_ooo_until'));
        $wiederDa = $fmt($settings->getSetting('comms_ooo_back_at'));

        // Aktiv heisst: von und wieder-da sind gesetzt (OooMode). Ohne „bis“
        // klaenge „vom 20.10. nicht erreichbar“ schief — dann „derzeit“.
        $zeitraum = ($von !== null && $bis !== null) ? "vom {$von} bis {$bis}" : 'derzeit';
        $text = "Wir sind {$zeitraum} nicht erreichbar";
        if ($wiederDa !== null) {
            $text .= " und ab {$wiederDa} wieder da";
        }

        return $text . '.';
    }

    /** Eine Stelle fuer die Aktiv-Pruefung — handle() und vorspann() duerfen nie auseinanderlaufen. */
    private function istAktiv(RecApplicantSettings $settings): bool
    {
        return OooMode::isActive(
            (bool) $settings->getSetting('comms_ooo_enabled', false),
            $settings->getSetting('comms_ooo_from'),
            $settings->getSetting('comms_ooo_back_at'),
            TeamClock::today($settings->getSetting('comms_timezone')),
        );
    }

    /**
     * Gab es in den letzten 24h eine Themen-Frage an diesen Thread? Aufgerufen
     * nur bei aktivem OOO — genau dann trug die Frage den Vorspann (vorspann()
     * und handle() teilen istAktiv()).
     */
    private function abwesenheitInThemenFrage(CommsWhatsAppThread $thread): bool
    {
        // Fehlt die Tabelle (Deploy ohne migrate), gab es auch keine Frage —
        // die Abwesenheitsvorlage geht wie bisher raus (Schlussreview m1).
        try {
            return RecConversationTopic::query()
                ->where('comms_whatsapp_thread_id', (int) $thread->id)
                ->where('asked_at', '>=', \Carbon\Carbon::now()->subDay())
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /** Bewerber-, Mitarbeiter- oder kontextloser Thread — sonst kein OOO. */
    private function isEligibleContext(?string $contextModel): bool
    {
        if ($contextModel === null) {
            return true;
        }

        return $contextModel === (new RecApplicant)->getMorphClass()
            || $contextModel === RecApplicant::class
            || $contextModel === RecEmployee::class;
    }
}
