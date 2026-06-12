<?php

namespace Platform\Recruiting\Listeners;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Events\CommsWhatsAppInboundReceived;
use Platform\Crm\Models\CommsLog;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\ApplicationMatchingService;
use Platform\Recruiting\Services\IncomingApplicationService;
use Platform\Recruiting\Services\ReminderResponseHandler;

class HandleWhatsAppInboundForRecruiting
{
    public function __construct(
        private IncomingApplicationService $applicationService,
        private ReminderResponseHandler $reminderResponseHandler,
        private ApplicationMatchingService $matchingService,
    ) {}

    public function handle(CommsWhatsAppInboundReceived $event): void
    {
        $channel = $event->channel;
        $thread = $event->thread;
        $message = $event->message;

        $logExtra = [
            'team_id' => $channel->team_id,
            'channel_type' => 'whatsapp',
            'channel_id' => $channel->id,
            'source' => 'recruiting_inbound',
            'recipient' => $thread->remote_phone_number,
        ];

        CommsLog::log(
            event: 'inbound_received',
            status: 'info',
            summary: "WhatsApp-Bewerbung empfangen von {$thread->remote_phone_number}",
            details: ['message_id' => $message->id, 'from' => $thread->remote_phone_number],
            extra: $logExtra,
        );

        // Skip if this thread is already linked to a non-recruiting context
        // (e.g. HCM onboarding, helpdesk ticket, sales, etc.)
        // Only allow threads with no context (new) or rec_applicant context (reply)
        if ($thread->context_model !== null) {
            $morphClass = (new \Platform\Recruiting\Models\RecApplicant)->getMorphClass();
            $fullClass = \Platform\Recruiting\Models\RecApplicant::class;

            if ($thread->context_model !== $morphClass && $thread->context_model !== $fullClass) {
                CommsLog::log(
                    event: 'inbound_skipped',
                    status: 'info',
                    summary: "WhatsApp-Thread gehört zu anderem Kontext ({$thread->context_model}), kein Recruiting-Applicant erstellt",
                    details: ['thread_id' => $thread->id, 'context_model' => $thread->context_model, 'context_model_id' => $thread->context_model_id],
                    extra: $logExtra,
                );

                return;
            }
        }

        // Intake-Gate: ist dieser Kanal überhaupt ein Bewerbungs-Eingang?
        if (!$this->matchingService->isIntakeChannel($channel)) {
            CommsLog::log(
                event: 'inbound_skipped',
                status: 'info',
                summary: "WhatsApp-Kanal ist kein Bewerbungs-Eingang, übersprungen",
                details: ['channel_name' => $channel->name],
                extra: $logExtra,
            );

            return;
        }

        Log::info('[Recruiting] WhatsApp inbound received on recruiting channel', [
            'channel_id' => $channel->id,
            'thread_id' => $thread->id,
            'message_id' => $message->id,
            'from' => $thread->remote_phone_number,
        ]);

        try {
            $senderPhone = $thread->remote_phone_number;
            if (!$senderPhone) {
                Log::warning('[Recruiting] No sender phone number in WhatsApp thread', [
                    'thread_id' => $thread->id,
                ]);
                return;
            }

            // Use the phone number as sender identifier
            // WhatsApp profile name can serve as display name
            $senderName = $thread->contact?->full_name ?? null;

            // Quellplattform vor dem Service-Aufruf bestimmen, damit die
            // deterministische Stufe 1 (Portal-Referenz) sie nutzen kann.
            $source = RecSourcePlatform::detectFromSender($senderPhone, (int) $channel->team_id);

            $result = $this->applicationService->handleInboundMessage(
                channel: $channel,
                senderIdentifier: $senderPhone,
                senderName: $senderName,
                subject: null,
                messageBody: $message->body,
                source: $source,
            );

            if (!$result) {
                return;
            }

            $applicant = $result['applicant'];

            // Quellplattform nur einmal bei Erstanlage setzen.
            if ($result['is_new'] && $source && empty($applicant->source_platform_id)) {
                $applicant->source_platform_id = $source->id;
                $applicant->save();
            }

            // Attach media files from the WhatsApp message to the applicant
            $this->attachWhatsAppFilesToApplicant($message, $thread, $applicant);

            // Link the thread to the applicant for communication tracking
            // addContext() writes both pivot table and legacy columns
            $thread->addContext($applicant->getMorphClass(), $applicant->id, 'recruiting_inbound');

            // Versuche Inbound als Reminder-Antwort (Ja/Nein) zu interpretieren.
            // Wenn ja: Booking-Status wird gesetzt + ggf. HR-Schreibtisch markiert.
            // Wenn nein: false zurueck, normaler Inbound-Flow laeuft unveraendert weiter.
            $wasReminderReply = $this->reminderResponseHandler->handle($applicant, (string) $message->body);
            if ($wasReminderReply) {
                Log::info('[Recruiting] Inbound als Reminder-Antwort verarbeitet', [
                    'applicant_id' => $applicant->id,
                    'message_id'   => $message->id,
                    'body'         => $message->body,
                ]);
            }

            // Reset AutoPilot state when applicant replies (so AutoPilot picks up again)
            // Also trigger re-enrichment so new message data gets extracted into extra fields
            if (!$result['is_new']) {
                $changed = false;

                if ($applicant->auto_pilot && $applicant->auto_pilot_state_id) {
                    $applicant->auto_pilot_state_id = null;
                    $changed = true;

                    Log::info('[Recruiting] AutoPilot state reset due to applicant reply', [
                        'applicant_id' => $applicant->id,
                    ]);
                }

                if (in_array($applicant->enrichment_status, ['enriched', 'no_contact', 'failed'])) {
                    $applicant->enrichment_status = null;
                    $changed = true;

                    Log::info('[Recruiting] Re-enrichment triggered by applicant reply', [
                        'applicant_id' => $applicant->id,
                    ]);
                }

                if ($changed) {
                    $applicant->save();
                }
            }

            Log::info('[Recruiting] WhatsApp application processed', [
                'applicant_id' => $applicant->id,
                'posting_id' => $result['posting']?->id,
                'is_new' => $result['is_new'],
            ]);

            CommsLog::log(
                event: $result['is_new'] ? 'inbound_created' : 'inbound_duplicate',
                status: 'success',
                summary: $result['is_new']
                    ? "Neuer Bewerber erstellt aus WhatsApp von {$senderPhone}"
                    : "Bestehender Bewerber gefunden, Notiz angehängt für {$senderPhone}",
                details: [
                    'applicant_id' => $applicant->id,
                    'posting_id' => $result['posting']?->id,
                    'is_new' => $result['is_new'],
                ],
                extra: $logExtra,
            );
        } catch (\Throwable $e) {
            Log::error('[Recruiting] Failed to process WhatsApp inbound for recruiting', [
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            CommsLog::log(
                event: 'inbound_failed',
                status: 'error',
                summary: "WhatsApp-Bewerbung fehlgeschlagen: {$e->getMessage()}",
                details: ['error' => $e->getMessage(), 'message_id' => $message->id],
                extra: $logExtra,
            );
        }
    }

    /**
     * Attach WhatsApp media files (images, documents, etc.) to the applicant.
     */
    private function attachWhatsAppFilesToApplicant(
        CommsWhatsAppMessage $message,
        CommsWhatsAppThread $thread,
        \Platform\Recruiting\Models\RecApplicant $applicant,
    ): void {
        // The WhatsAppWebhookController already processes media via InboundWhatsAppAttachmentService
        // We link those ContextFiles to the applicant as well
        if (!method_exists($message, 'getFileReferencesArray')) {
            return;
        }

        try {
            $fileRefs = $message->getFileReferencesArray();
            if (empty($fileRefs) || !method_exists($applicant, 'addFileReference')) {
                return;
            }

            foreach ($fileRefs as $fileRef) {
                $contextFileId = $fileRef['context_file_id'] ?? $fileRef['id'] ?? null;
                if (!$contextFileId) {
                    continue;
                }

                $applicant->addFileReference($contextFileId, [
                    'title' => $fileRef['title'] ?? 'Anhang',
                    'source' => 'recruiting_whatsapp_application',
                    'whatsapp_message_id' => $message->id,
                    'thread_id' => $thread->id,
                ]);
            }

            Log::info('[Recruiting] WhatsApp attachments linked to applicant', [
                'applicant_id' => $applicant->id,
                'file_count' => count($fileRefs),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Recruiting] Failed to attach WhatsApp files to applicant', [
                'applicant_id' => $applicant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
