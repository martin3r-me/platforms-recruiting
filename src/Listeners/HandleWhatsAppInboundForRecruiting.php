<?php

namespace Platform\Recruiting\Listeners;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Events\CommsWhatsAppInboundReceived;
use Platform\Crm\Models\CommsLog;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\ApplicationMatchingService;
use Platform\Recruiting\Services\Comms\ApplicantThreadLinker;
use Platform\Recruiting\Services\Comms\OooAutoReplyHandler;
use Platform\Recruiting\Services\Comms\ThreadContextGate;
use Platform\Recruiting\Services\Comms\VoiceNoteAutoReplyHandler;
use Platform\Recruiting\Services\IncomingApplicationService;
use Platform\Recruiting\Services\ReminderResponseHandler;

class HandleWhatsAppInboundForRecruiting
{
    public function __construct(
        private IncomingApplicationService $applicationService,
        private ReminderResponseHandler $reminderResponseHandler,
        private ApplicationMatchingService $matchingService,
        private VoiceNoteAutoReplyHandler $voiceNoteAutoReply,
        private OooAutoReplyHandler $oooAutoReply,
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

        // HR-Abwesenheitsmodus: Auto-Quittung VOR dem Kontext-Gate, damit auch
        // Mitarbeiter-Threads erfasst werden (eigener Kontext-Filter im Handler,
        // Fremd-Kontexte bleiben aussen vor). Fehler stoppen nie den Inbound-Flow.
        try {
            $this->oooAutoReply->handle($channel, $thread, $message);
        } catch (\Throwable $e) {
            Log::warning('[Recruiting] OOO-Auto-Reply fehlgeschlagen', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Skip if this thread is already linked to a non-recruiting context
        // (e.g. HCM onboarding, helpdesk ticket, sales, etc.). Ein nackter
        // CrmContact-Kontext blockt NICHT — den heftet das CRM seit 04/2026
        // an jeden neuen Thread, bevor Recruiting die Nachricht sieht.
        // Geprüft werden Legacy-Spalte UND Pivot-Kontexte: die Legacy-Spalte
        // bleibt per "first context wins" auf crm_contact stehen, auch wenn
        // ein Fachprozess den Thread später per Pivot übernommen hat.
        $contextModels = $thread->contexts()->pluck('context_model')
            ->push($thread->context_model)
            ->filter()
            ->unique()
            ->all();

        if (ThreadContextGate::blocksIntakeAny($contextModels)) {
            $blocking = implode(', ', array_filter($contextModels, [ThreadContextGate::class, 'blocksIntake']));
            CommsLog::log(
                event: 'inbound_skipped',
                status: 'info',
                summary: "WhatsApp-Thread gehört zu anderem Kontext ({$blocking}), kein Recruiting-Applicant erstellt",
                details: ['thread_id' => $thread->id, 'context_model' => $thread->context_model, 'context_model_id' => $thread->context_model_id, 'context_models' => $contextModels],
                extra: $logExtra,
            );

            return;
        }

        // Auto-Hinweis bei Sprachnachrichten (greift für jede Recruiting-Konversation,
        // auch auf Nicht-Intake-Kanälen; gedrosselt 1×/24h; Feature aus wenn kein
        // Template konfiguriert). Bewusst VOR dem Intake-Gate, normaler Flow läuft danach weiter.
        // NICHT für nackte CrmContact-Threads: das wäre eine Recruiting-Antwort an
        // beliebige bekannte Kontakte (Kunden, Disponenten) auf beliebigen Kanälen —
        // vor dem Gate-Fix hat der alte Kontext-Check genau das unterbunden.
        if (!ThreadContextGate::isBareContactContext($thread->context_model)) {
            try {
                $this->voiceNoteAutoReply->handle($channel, $thread, $message);
            } catch (\Throwable $e) {
                Log::warning('[Recruiting] Voice-Note Auto-Reply fehlgeschlagen', [
                    'thread_id' => $thread->id,
                    'error' => $e->getMessage(),
                ]);
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
            // (Pivot + Beförderung der Legacy-Spalten, siehe Linker-Doc).
            ApplicantThreadLinker::link($thread, $applicant->id, 'recruiting_inbound');

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
