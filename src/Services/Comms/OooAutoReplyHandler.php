<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsLog;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;

/**
 * HR-Abwesenheitsmodus: schickt bei aktivem OOO automatisch das konfigurierte
 * Abwesenheits-Template auf eingehende Nachrichten zurueck. Gedrosselt auf
 * 1x/24h je Konversation. Gilt fuer Bewerber-, Mitarbeiter- und kontextlose
 * Threads — nie fuer Fremd-Kontexte (Helpdesk, Sales, ...).
 *
 * Der Send wird mit is_auto_reply=true markiert und zaehlt damit NICHT als
 * Antwort im "verpasst"-Zaehler (ConversationInboxService).
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

        if (!OooMode::isActive(
            (bool) $settings->getSetting('comms_ooo_enabled', false),
            $settings->getSetting('comms_ooo_from'),
            $settings->getSetting('comms_ooo_back_at'),
            TeamClock::today($settings->getSetting('comms_timezone')),
        )) {
            return;
        }

        $phone = (string) ($thread->remote_phone_number ?? '');
        if ($phone === '') {
            return;
        }

        if ($this->isBlockedContact($thread, $teamId, $phone)) {
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

    /**
     * Zweistufiges Block-Gate: is_blacklisted ODER Kontakt-Status BLOCKED.
     * Erst der verknuepfte Thread-Kontakt; ohne Kontakt Fallback-Lookup ueber
     * die Telefonnummer in mehreren Schreibweisen (CRM-Muster, +49/ohne +).
     */
    private function isBlockedContact(CommsWhatsAppThread $thread, int $teamId, string $phone): bool
    {
        $contact = $thread->contact;
        if ($contact instanceof CrmContact) {
            return (bool) $contact->is_blacklisted
                || $contact->contactStatus?->code === 'BLOCKED';
        }

        $variants = array_unique([$phone, '+' . ltrim($phone, '+'), ltrim($phone, '+')]);

        return CrmContact::query()
            ->where('team_id', $teamId)
            ->where(function ($q) {
                $q->where('is_blacklisted', true)
                    ->orWhereHas('contactStatus', fn ($s) => $s->where('code', 'BLOCKED'));
            })
            ->whereHas('phoneNumbers', fn ($p) => $p->whereIn('international', $variants))
            ->exists();
    }
}
