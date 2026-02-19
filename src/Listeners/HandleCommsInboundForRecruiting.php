<?php

namespace Platform\Recruiting\Listeners;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Events\CommsInboundReceived;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsEmailInboundMail;
use Platform\Recruiting\Services\IncomingApplicationService;

class HandleCommsInboundForRecruiting
{
    public function __construct(
        private IncomingApplicationService $applicationService,
    ) {}

    public function handle(CommsInboundReceived $event): void
    {
        $channel = $event->channel;
        $thread = $event->thread;
        $mail = $event->mail;

        // Check if this channel is linked to any recruiting postings
        if (!$this->channelHasPostings($channel)) {
            return;
        }

        Log::info('[Recruiting] Email inbound received on recruiting channel', [
            'channel_id' => $channel->id,
            'thread_id' => $thread->id,
            'mail_id' => $mail->id,
            'from' => $mail->from,
        ]);

        try {
            $senderRaw = (string) ($mail->from ?? '');
            $senderEmail = $this->applicationService->extractEmailAddress($senderRaw);
            $senderName = $this->applicationService->extractDisplayName($senderRaw);

            if (!$senderEmail) {
                Log::warning('[Recruiting] Could not extract sender email', [
                    'mail_id' => $mail->id,
                    'from' => $senderRaw,
                ]);
                return;
            }

            $result = $this->applicationService->handleInboundMessage(
                channel: $channel,
                senderIdentifier: $senderEmail,
                senderName: $senderName,
                subject: $mail->subject,
                messageBody: $mail->text_body,
            );

            if (!$result) {
                return;
            }

            $applicant = $result['applicant'];

            // Attach files from the inbound mail to the applicant (CV, cover letter, etc.)
            $this->attachEmailFilesToApplicant($mail, $thread, $applicant);

            // Link the thread to the applicant for communication tracking
            // Always override - recruiting channel means recruiting context
            $thread->context_model = $applicant->getMorphClass();
            $thread->context_model_id = $applicant->id;
            $thread->save();

            Log::info('[Recruiting] Email application processed', [
                'applicant_id' => $applicant->id,
                'posting_id' => $result['posting']->id,
                'is_new' => $result['is_new'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Recruiting] Failed to process email inbound for recruiting', [
                'channel_id' => $channel->id,
                'mail_id' => $mail->id,
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
     * Attach email attachments (CV, cover letter, etc.) to the applicant as ContextFiles.
     */
    private function attachEmailFilesToApplicant(
        CommsEmailInboundMail $mail,
        $thread,
        \Platform\Recruiting\Models\RecApplicant $applicant,
    ): void {
        // Use existing ContextFileReferences from the mail
        // The InboundPostmarkController already processes attachments via InboundMailAttachmentService
        // We link those ContextFiles to the applicant as well
        if (!method_exists($mail, 'getFileReferencesArray')) {
            return;
        }

        try {
            $fileRefs = $mail->getFileReferencesArray();
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
                    'source' => 'recruiting_email_application',
                    'inbound_mail_id' => $mail->id,
                    'thread_id' => $thread->id,
                ]);
            }

            Log::info('[Recruiting] Email attachments linked to applicant', [
                'applicant_id' => $applicant->id,
                'file_count' => count($fileRefs),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Recruiting] Failed to attach email files to applicant', [
                'applicant_id' => $applicant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
