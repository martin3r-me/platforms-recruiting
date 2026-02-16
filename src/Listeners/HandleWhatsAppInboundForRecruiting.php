<?php

namespace Platform\Recruiting\Listeners;

use Illuminate\Support\Facades\Log;
use Platform\Core\Events\CommsWhatsAppInboundReceived;
use Platform\Core\Models\CommsChannel;
use Platform\Core\Models\CommsWhatsAppMessage;
use Platform\Core\Models\CommsWhatsAppThread;
use Platform\Recruiting\Services\IncomingApplicationService;

class HandleWhatsAppInboundForRecruiting
{
    public function __construct(
        private IncomingApplicationService $applicationService,
    ) {}

    public function handle(CommsWhatsAppInboundReceived $event): void
    {
        $channel = $event->channel;
        $thread = $event->thread;
        $message = $event->message;

        // Check if this channel is linked to any recruiting postings
        if (!$this->channelHasPostings($channel)) {
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

            $result = $this->applicationService->handleInboundMessage(
                channel: $channel,
                senderIdentifier: $senderPhone,
                senderName: $senderName,
                subject: null,
                messageBody: $message->body,
            );

            if (!$result) {
                return;
            }

            $applicant = $result['applicant'];

            // Attach media files from the WhatsApp message to the applicant
            $this->attachWhatsAppFilesToApplicant($message, $thread, $applicant);

            // Link the thread to the applicant for communication tracking
            if (!$thread->context_model && $result['is_new']) {
                $thread->context_model = \Platform\Recruiting\Models\RecApplicant::class;
                $thread->context_model_id = $applicant->id;
                $thread->save();
            }

            Log::info('[Recruiting] WhatsApp application processed', [
                'applicant_id' => $applicant->id,
                'posting_id' => $result['posting']->id,
                'is_new' => $result['is_new'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Recruiting] Failed to process WhatsApp inbound for recruiting', [
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function channelHasPostings(CommsChannel $channel): bool
    {
        return $channel->recruitingPostings()
            ->open()
            ->exists();
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
