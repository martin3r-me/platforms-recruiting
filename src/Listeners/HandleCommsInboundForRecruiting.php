<?php

namespace Platform\Recruiting\Listeners;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Events\CommsInboundReceived;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsEmailInboundMail;
use Platform\Crm\Models\CommsLog;
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

        Log::debug('[Recruiting] Email inbound event received', [
            'channel_id' => $channel->id,
            'channel_name' => $channel->name,
            'channel_sender' => $channel->sender_identifier,
            'thread_id' => $thread->id,
            'mail_id' => $mail->id,
        ]);

        $logExtra = [
            'team_id' => $channel->team_id,
            'channel_type' => 'email',
            'channel_id' => $channel->id,
            'source' => 'recruiting_inbound',
            'recipient' => $mail->from,
        ];

        CommsLog::log(
            event: 'inbound_received',
            status: 'info',
            summary: "Email-Bewerbung empfangen von {$mail->from}",
            details: ['subject' => $mail->subject, 'from' => $mail->from, 'mail_id' => $mail->id],
            extra: $logExtra,
        );

        // Check if this channel is linked to any recruiting postings
        if (!$this->channelHasPostings($channel)) {
            Log::debug('[Recruiting] Email channel has no open postings, skipping', [
                'channel_id' => $channel->id,
            ]);

            CommsLog::log(
                event: 'inbound_skipped',
                status: 'info',
                summary: "Email-Kanal hat keine offenen Postings, übersprungen",
                details: ['channel_name' => $channel->name],
                extra: $logExtra,
            );

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

            // Detect notification/forwarding emails and extract real applicant email from body
            $bodyExtraction = $this->applicationService->extractApplicantFromNotification(
                senderEmail: $senderEmail,
                subject: $mail->subject,
                textBody: $mail->text_body,
            );

            if ($bodyExtraction) {
                $senderEmail = $bodyExtraction['email'];
                $senderName = $bodyExtraction['name'] ?? $senderName;
            }

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

            // Also add to pivot table (used by Terminal forContext scope)
            $thread->addContext($applicant->getMorphClass(), $applicant->id, 'recruiting_inbound');

            // Reset AutoPilot state and re-enrichment when applicant replies
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
                    Log::info('[Recruiting] Re-enrichment triggered by applicant reply (email)', [
                        'applicant_id' => $applicant->id,
                    ]);
                }

                if ($changed) {
                    $applicant->save();
                }
            }

            Log::info('[Recruiting] Email application processed', [
                'applicant_id' => $applicant->id,
                'posting_id' => $result['posting']->id,
                'is_new' => $result['is_new'],
            ]);

            CommsLog::log(
                event: $result['is_new'] ? 'inbound_created' : 'inbound_duplicate',
                status: 'success',
                summary: $result['is_new']
                    ? "Neuer Bewerber erstellt aus Email von {$senderEmail}"
                    : "Bestehender Bewerber gefunden, Notiz angehängt für {$senderEmail}",
                details: [
                    'applicant_id' => $applicant->id,
                    'posting_id' => $result['posting']->id,
                    'is_new' => $result['is_new'],
                ],
                extra: array_merge($logExtra, ['recipient' => $senderEmail]),
            );
        } catch (\Throwable $e) {
            Log::error('[Recruiting] Failed to process email inbound for recruiting', [
                'channel_id' => $channel->id,
                'mail_id' => $mail->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            CommsLog::log(
                event: 'inbound_failed',
                status: 'error',
                summary: "Email-Bewerbung fehlgeschlagen: {$e->getMessage()}",
                details: ['error' => $e->getMessage(), 'mail_id' => $mail->id],
                extra: $logExtra,
            );
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
