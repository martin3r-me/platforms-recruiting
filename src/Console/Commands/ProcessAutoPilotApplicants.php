<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Auth\Authenticatable;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Services\Comms\PostmarkEmailService;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Support\SeatStandbyPolicy;
use Platform\Recruiting\Jobs\NotifyWaitlistForInterview;

class ProcessAutoPilotApplicants extends Command
{
    protected $signature = 'recruiting:process-auto-pilot-applicants
        {--limit=20 : Maximale Anzahl Bewerbungen pro Run}
        {--max-runtime-seconds=600 : Maximale Laufzeit pro Run (Sekunden)}
        {--applicant-id= : Optional: einzelne Bewerbung bearbeiten}
        {--dry-run : Zeigt nur, was bearbeitet würde}';

    protected $description = 'Bearbeitet Bewerbungen mit auto_pilot=true: sendet WA-Template oder Email mit Public-Form-Link. Deterministisch, kein LLM.';

    private ?int $waitingForApplicantStateId = null;
    private ?int $completedStateId = null;
    private ?int $reviewNeededStateId = null;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = min(max((int) $this->option('limit'), 1), 100);
        $maxRuntimeSeconds = min(max((int) $this->option('max-runtime-seconds'), 10), 12 * 60 * 60);
        $deadline = Carbon::now()->addSeconds($maxRuntimeSeconds);

        $applicantId = $this->option('applicant-id');
        $applicantId = is_numeric($applicantId) ? (int) $applicantId : null;

        $lockTtlSeconds = max(6 * 60 * 60, $maxRuntimeSeconds + 3600);
        $lockKey = $applicantId
            ? "recruiting:process-auto-pilot-applicant:{$applicantId}"
            : 'recruiting:process-auto-pilot-applicants';
        $lock = Cache::lock($lockKey, $lockTtlSeconds);

        if (!$lock->get()) {
            $this->warn('Läuft bereits (Lock aktiv).');
            return Command::SUCCESS;
        }

        try {
            if ($dryRun) {
                $this->warn('DRY-RUN — es werden keine Daten geändert.');
            }

            $this->waitingForApplicantStateId = RecAutoPilotState::where('code', 'waiting_for_applicant')->whereNull('team_id')->value('id');
            $this->completedStateId = RecAutoPilotState::where('code', 'completed')->whereNull('team_id')->value('id');
            $this->reviewNeededStateId = RecAutoPilotState::where('code', 'review_needed')->whereNull('team_id')->value('id');

            $processed = 0;
            $seenIds = [];
            $originalAuthUser = Auth::user();

            while ($processed < $limit) {
                if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                    $this->warn("Zeitbudget erreicht ({$maxRuntimeSeconds}s). Rest macht der nächste Run.");
                    break;
                }

                $applicant = $this->nextAutoPilotApplicant($applicantId, $seenIds);
                if (!$applicant) {
                    if ($processed === 0) {
                        $this->info('Keine offenen AutoPilot-Bewerbungen gefunden.');
                    }
                    break;
                }

                $seenIds[] = (int) $applicant->id;
                $processed++;

                $teamSettings = RecApplicantSettings::getOrCreateForTeam($applicant->team_id);
                $position = $this->resolvePrimaryPosition($applicant);
                $positionSettings = $position?->auto_pilot_settings ?? [];
                $phaseSettings = $applicant->phase?->auto_pilot_settings ?? [];

                if (!$this->getEffectiveSetting($teamSettings, $positionSettings, 'auto_pilot_enabled', true, $phaseSettings)) {
                    $source = isset($phaseSettings['auto_pilot_enabled']) ? 'Phasen-Setting' : (isset($positionSettings['auto_pilot_enabled']) ? 'Positions-Setting' : 'Team-Setting');
                    $this->line("  #{$applicant->id}: AutoPilot deaktiviert ({$source}) — übersprungen.");
                    continue;
                }

                $owner = $applicant->ownedByUser;
                if (!$owner) {
                    $this->line("  #{$applicant->id}: übersprungen (kein Owner).");
                    continue;
                }

                $positionInfo = $position ? " | Position: {$position->title}" : '';
                $this->info("--- Bewerbung #{$applicant->id} | Owner: {$owner->name} | Team: " . ($applicant->team?->name ?? '—') . $positionInfo);

                if ($dryRun) {
                    $this->line("  DRY-RUN: würde verarbeitet werden.");
                    continue;
                }

                $this->impersonateForTask($owner, $applicant->team);
                $this->processApplicant($applicant, $teamSettings, $positionSettings, $phaseSettings);
            }

            // Restore auth
            if ($originalAuthUser instanceof Authenticatable) {
                Auth::setUser($originalAuthUser);
            } else {
                try { Auth::guard()->logout(); } catch (\Throwable $e) {}
            }

            $this->newLine();
            $this->info("Fertig. Bearbeitet: {$processed} Bewerbung(en).");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Fehler: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            try { Auth::guard()->logout(); } catch (\Throwable $e) {}
            try { $lock->release(); } catch (\Throwable $e) {}
        }
    }

    private function processApplicant(RecApplicant $applicant, RecApplicantSettings $teamSettings, array $positionSettings = [], array $phaseSettings = []): void
    {
        // 1. Update progress for UI/state, regardless of completion_type
        $progress = $applicant->calculateProgress();
        $applicant->progress = $progress;
        $applicant->save();

        // 2. Determine completion via the phase's completion_type:
        //    'fields'  → progress >= 100 (visibility-aware, default)
        //    'booking' → matching non-cancelled booking exists
        //    'manual'  → never auto-complete
        if ($applicant->isPhaseComplete()) {
            $applicant->checkAutoPilotCompletion();
            $applicant->refresh();

            // If applicant advanced to next phase, it's now in a new cycle
            if (!$applicant->auto_pilot_completed_at) {
                $phaseName = $applicant->phase?->name ?? '?';
                $this->info("  Phase abgeschlossen — weiter zu \"{$phaseName}\".");
            } else {
                $this->info("  Alle Felder komplett — abgeschlossen.");
            }
            return;
        }

        // 2a-0. Warteliste pausiert den Auto-Pilot: Hat der Bewerber einen
        // offenen Warteliste-Eintrag (kein Termin verfuegbar, er wartet auf
        // Benachrichtigung), wird KEIN Erstkontakt/Reminder geschickt — sonst
        // bekaeme er "bitte Termin auswaehlen", obwohl er sich genau deshalb
        // eingetragen hat. Sobald ein Termin frei wird und er bucht, ist die
        // Buchungs-Phase abgeschlossen (oben) und der Flow laeuft normal weiter.
        if (\Platform\Recruiting\Models\RecInterviewWaitlist::where('rec_applicant_id', $applicant->id)->open()->exists()) {
            $this->logAutoPilot($applicant, 'silent', 'Bewerber steht auf der Warteliste — Auto-Pilot pausiert (kein Reminder).');
            $this->info('  Auf Warteliste — Auto-Pilot pausiert.');
            return;
        }

        // 2a. Phase-Stop-Marker: wenn die aktuelle Phase explizit
        // auto_pilot_disabled=true gesetzt hat, wird kein Template/Reminder
        // versendet. Cascade auf Position/Team-Default wird damit bewusst
        // umgangen — gut für Phasen die durch externe Trigger abgeschlossen
        // werden (Vertragsversand vom Schulungsleiter, Bewerber-Signatur,
        // etc.) und kein automatisches Anschreiben brauchen.
        if (($phaseSettings['auto_pilot_disabled'] ?? false) === true) {
            $phaseName = $applicant->phase?->name ?? '?';
            $this->logAutoPilot($applicant, 'silent', "Phase \"{$phaseName}\" ist als auto_pilot_disabled markiert — kein Template-Versand.");
            $this->info("  Phase still (auto_pilot_disabled) — kein Versand.");
            return;
        }

        // 2. Resolve channel (uses effective settings for WA account)
        $channelPriority = $this->getEffectiveSetting($teamSettings, $positionSettings, 'auto_pilot_channel_priority', 'whatsapp_first', $phaseSettings);
        $resolved = $this->resolveChannelWithOverrides($applicant, $channelPriority, $teamSettings, $positionSettings, $phaseSettings);

        if (!$resolved) {
            $this->logAutoPilot($applicant, 'warning', "Kein Kanal verfügbar (Priorität: {$channelPriority}).");
            $this->warn("  Kein Kanal verfügbar — übersprungen.");
            return;
        }

        $channel = $resolved['channel'];
        $channelType = $resolved['type']; // 'whatsapp' or 'email'

        // 3. Get public form link
        $publicUrl = $applicant->getPublicUrl();
        $formToken = $this->extractFormToken($publicUrl);

        // 4. First contact (never sent a reminder)
        if ($applicant->auto_pilot_last_reminder_at === null) {
            $sent = $this->sendMessageWithOverrides($applicant, $channel, $channelType, $publicUrl, $formToken, $teamSettings, $positionSettings, isReminder: false, phaseSettings: $phaseSettings);

            if ($sent) {
                $applicant->auto_pilot_reminder_count = 1;
                $applicant->auto_pilot_last_reminder_at = now();
                $applicant->auto_pilot_state_id = $this->waitingForApplicantStateId;
                $applicant->save();
                $this->logAutoPilot($applicant, 'template_sent', "Erstkontakt per {$channelType} gesendet.");
                $this->info("  Erstkontakt per {$channelType} gesendet.");
            } else {
                $this->logAutoPilot($applicant, 'warning', "Versand per {$channelType} fehlgeschlagen.");
                $this->warn("  Versand fehlgeschlagen.");
            }
            return;
        }

        // 5. Reminder check
        $intervalHours = (int) $this->getEffectiveSetting($teamSettings, $positionSettings, 'auto_pilot_reminder_interval_hours', 24, $phaseSettings);
        $maxReminders = (int) $this->getEffectiveSetting($teamSettings, $positionSettings, 'auto_pilot_max_reminders', 3, $phaseSettings);
        $reminderDue = $applicant->auto_pilot_last_reminder_at->addHours($intervalHours)->isPast();

        if (!$reminderDue) {
            $this->line("  Warten — nächste Erinnerung in " . $applicant->auto_pilot_last_reminder_at->addHours($intervalHours)->diffForHumans());
            return;
        }

        // 5a. Max reminders reached?
        if ($applicant->auto_pilot_reminder_count >= $maxReminders) {
            $applicant->auto_pilot_state_id = $this->reviewNeededStateId;
            $applicant->save();
            $this->logAutoPilot($applicant, 'max_reminders_reached', "Max. Erinnerungen erreicht ({$maxReminders}/{$maxReminders}).");
            $this->releaseSeats($applicant);
            $this->info("  Max. Erinnerungen erreicht — review_needed.");
            return;
        }

        // 5b. Send reminder
        $sent = $this->sendMessageWithOverrides($applicant, $channel, $channelType, $publicUrl, $formToken, $teamSettings, $positionSettings, isReminder: true, phaseSettings: $phaseSettings);

        if ($sent) {
            $applicant->auto_pilot_reminder_count = $applicant->auto_pilot_reminder_count + 1;
            $applicant->auto_pilot_last_reminder_at = now();
            $applicant->save();
            $this->logAutoPilot($applicant, 'reminder_sent', "Erinnerung {$applicant->auto_pilot_reminder_count}/{$maxReminders} per {$channelType} gesendet.");
            $this->info("  Erinnerung {$applicant->auto_pilot_reminder_count}/{$maxReminders} per {$channelType} gesendet.");
        } else {
            $this->logAutoPilot($applicant, 'warning', "Erinnerungs-Versand per {$channelType} fehlgeschlagen.");
            $this->warn("  Erinnerungs-Versand fehlgeschlagen.");
        }
    }

    /**
     * Standby: Auto-Pilot hat aufgegeben — 'booked'-Buchungen geben ihren
     * Platz frei (seat_released_at), bleiben aber bestehen. Der frei
     * gewordene Platz wird sofort der Warteliste angeboten. Idempotent
     * (bereits released wird uebersprungen) — der Max-Branch kann nach
     * Inbound-State-Reset mehrfach feuern.
     */
    private function releaseSeats(RecApplicant $applicant): void
    {
        $bookings = RecInterviewBooking::where('rec_applicant_id', $applicant->id)
            ->where('status', 'booked')
            ->whereNull('seat_released_at')
            ->get();

        foreach ($bookings as $booking) {
            if (!SeatStandbyPolicy::shouldRelease($booking->status, $booking->seat_released_at !== null)) {
                continue;
            }
            $booking->seat_released_at = now();
            $booking->save();

            $this->logAutoPilot($applicant, 'seat_released', "Schulungsplatz freigegeben — keine Reaktion auf Erinnerungen (Buchung #{$booking->id}).", [
                'booking_id'   => $booking->id,
                'interview_id' => $booking->rec_interview_id,
                'source'       => 'auto_pilot',
            ]);
            NotifyWaitlistForInterview::dispatch($booking->rec_interview_id);
        }
    }

    /**
     * Resolve channel based on effective settings (position overrides team).
     */
    private function resolveChannelWithOverrides(RecApplicant $applicant, string $priority, RecApplicantSettings $teamSettings, array $positionSettings, array $phaseSettings = []): ?array
    {
        $teamId = $applicant->team_id;
        $applicant->loadMissing(['crmContactLinks.contact.phoneNumbers', 'crmContactLinks.contact.emailAddresses']);

        $waAccountId = $this->getEffectiveSetting($teamSettings, $positionSettings, 'auto_pilot_wa_account_id', null, $phaseSettings);
        $waChannel = $this->resolveWhatsAppChannelById($waAccountId);
        $emailChannel = CommsChannel::where('team_id', $teamId)->where('type', 'email')->where('is_active', true)->first();

        $hasPhone = $this->findPrimaryPhoneNumber($applicant) !== null;
        $hasEmail = $this->findPrimaryEmail($applicant) !== null;

        $canWhatsApp = $waChannel && $hasPhone;
        $canEmail = $emailChannel && $hasEmail;

        return match ($priority) {
            'whatsapp_first' => $canWhatsApp
                ? ['channel' => $waChannel, 'type' => 'whatsapp']
                : ($canEmail ? ['channel' => $emailChannel, 'type' => 'email'] : null),

            'email_first' => $canEmail
                ? ['channel' => $emailChannel, 'type' => 'email']
                : ($canWhatsApp ? ['channel' => $waChannel, 'type' => 'whatsapp'] : null),

            'whatsapp_only' => $canWhatsApp ? ['channel' => $waChannel, 'type' => 'whatsapp'] : null,

            'email_only' => $canEmail ? ['channel' => $emailChannel, 'type' => 'email'] : null,

            default => null,
        };
    }

    /**
     * Resolve a real CommsChannel from DB by WhatsApp account ID.
     */
    private function resolveWhatsAppChannelById($accountId): ?CommsChannel
    {
        if (!$accountId) {
            return null;
        }

        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
            return null;
        }

        $account = \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($accountId);
        if (!$account || !$account->active) {
            return null;
        }

        // Find the real CommsChannel synced from this WA account
        return CommsChannel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('sender_identifier', $account->phone_number)
            ->first();
    }

    private function sendMessageWithOverrides(
        RecApplicant $applicant,
        CommsChannel $channel,
        string $channelType,
        string $publicUrl,
        string $formToken,
        RecApplicantSettings $teamSettings,
        array $positionSettings,
        bool $isReminder = false,
        array $phaseSettings = [],
    ): bool {
        if ($channelType === 'whatsapp') {
            return $this->sendWhatsAppTemplateWithOverrides($applicant, $channel, $formToken, $teamSettings, $positionSettings, $isReminder, $phaseSettings);
        }

        return $this->sendEmail($applicant, $channel, $publicUrl, $isReminder);
    }

    private function sendWhatsAppTemplateWithOverrides(
        RecApplicant $applicant,
        CommsChannel $channel,
        string $formToken,
        RecApplicantSettings $teamSettings,
        array $positionSettings,
        bool $isReminder = false,
        array $phaseSettings = [],
    ): bool {
        try {
            $phoneNumber = $this->findPrimaryPhoneNumber($applicant);
            if (!$phoneNumber) {
                return false;
            }

            // Resolve template from DB by ID (initial vs. reminder) — phase overrides position overrides team
            $settingKey = $isReminder ? 'auto_pilot_wa_reminder_template_id' : 'auto_pilot_wa_initial_template_id';
            $templateId = $this->getEffectiveSetting($teamSettings, $positionSettings, $settingKey, null, $phaseSettings);
            $templateName = null;
            $templateLang = 'de';

            if ($templateId && class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
                $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
                if ($template && $template->status === 'APPROVED') {
                    $templateName = $template->name;
                    $templateLang = $template->language;
                }
            }

            if (!$templateName) {
                $this->logAutoPilot($applicant, 'warning', 'Kein WA-Template konfiguriert für: ' . ($isReminder ? 'Erinnerung' : 'Erstkontakt'));
                return false;
            }

            // Build components
            $components = [];

            // Body parameters — auto-fill from applicant context
            if ($template) {
                $bodyParams = $this->parseTemplateBodyParams($template->components ?? []);
                if (!empty($bodyParams)) {
                    $contactName = $this->getContactName($applicant);
                    $bodyParameters = [];
                    foreach ($bodyParams as $param) {
                        $value = match (strtolower($param['name'])) {
                            '1', 'name', 'vorname' => $contactName,
                            default => $param['example'] ?: $contactName,
                        };
                        $paramEntry = ['type' => 'text', 'text' => $value];
                        if (!is_numeric($param['name'])) {
                            $paramEntry['parameter_name'] = $param['name'];
                        }
                        $bodyParameters[] = $paramEntry;
                    }
                    $components[] = [
                        'type' => 'body',
                        'parameters' => $bodyParameters,
                    ];
                }
            }

            // URL button params
            if ($template && $formToken) {
                $hasUrlButton = collect($template->components ?? [])
                    ->where('type', 'BUTTONS')
                    ->flatMap(fn ($c) => $c['buttons'] ?? [])
                    ->contains('type', 'URL');

                if ($hasUrlButton) {
                    $components[] = [
                        'type' => 'button', 'sub_type' => 'url', 'index' => 0,
                        'parameters' => [['type' => 'text', 'text' => $formToken]],
                    ];
                }
            }

            $service = app(WhatsAppMetaService::class);
            $message = $service->sendTemplate(
                channel: $channel,
                to: $phoneNumber->international,
                templateName: $templateName,
                components: $components,
                languageCode: $templateLang,
            );

            // Link thread to applicant so it's visible in the UI
            $thread = $message->thread ?? null;
            if ($thread) {
                $thread->addContext($applicant->getMorphClass(), $applicant->id, 'auto_pilot');
            }

            return true;
        } catch (\Throwable $e) {
            $this->logAutoPilot($applicant, 'error', 'WA-Template-Fehler: ' . $e->getMessage());
            $this->warn("  WA-Fehler: " . $e->getMessage());
            return false;
        }
    }

    private function sendEmail(RecApplicant $applicant, CommsChannel $channel, string $publicUrl, bool $isReminder = false): bool
    {
        try {
            $email = $this->findPrimaryEmail($applicant);
            if (!$email) {
                return false;
            }

            $contactName = $this->getContactName($applicant);
            $teamName = $applicant->team?->name ?? '';
            $owner = $applicant->ownedByUser;

            if ($isReminder) {
                $subject = 'Erinnerung: Bitte ergänzen Sie Ihre Bewerbungsdaten';
                $body = "Hallo {$contactName},\n\n"
                    . "wir haben noch nicht alle Angaben zu Ihrer Bewerbung erhalten. "
                    . "Bitte ergänzen Sie die fehlenden Daten über unser Online-Formular:\n\n"
                    . "{$publicUrl}\n\n"
                    . "Vielen Dank und viele Grüße";
            } else {
                $subject = 'Ihre Bewerbung — bitte ergänzen Sie Ihre Daten';
                $body = "Hallo {$contactName},\n\n"
                    . "vielen Dank für Ihr Interesse an einer Tätigkeit bei {$teamName}.\n\n"
                    . "Damit wir Ihre Bewerbung vollständig bearbeiten können, bitten wir Sie, "
                    . "noch einige Angaben über unser Online-Formular zu ergänzen:\n\n"
                    . "{$publicUrl}\n\n"
                    . "Vielen Dank und viele Grüße";
            }

            $htmlBody = nl2br(e($body));

            $service = app(PostmarkEmailService::class);
            $service->send(
                channel: $channel,
                to: $email,
                subject: $subject,
                htmlBody: $htmlBody,
                textBody: $body,
                opt: [
                    'sender' => $owner,
                    'tag' => 'auto-pilot',
                ],
            );

            // Link new email thread to applicant
            $this->linkNewEmailThread($applicant, $channel, $email);

            return true;
        } catch (\Throwable $e) {
            $this->logAutoPilot($applicant, 'error', 'Email-Fehler: ' . $e->getMessage());
            $this->warn("  Email-Fehler: " . $e->getMessage());
            return false;
        }
    }

    private function linkNewEmailThread(RecApplicant $applicant, CommsChannel $channel, string $email): void
    {
        try {
            $threads = \Platform\Crm\Models\CommsEmailThread::query()
                ->where('comms_channel_id', $channel->id)
                ->whereNull('context_model')
                ->where(function ($q) use ($email) {
                    $q->where('last_outbound_to_address', $email)
                      ->orWhere('last_inbound_from_address', $email);
                })
                ->where('created_at', '>=', now()->subMinutes(5))
                ->get();

            foreach ($threads as $thread) {
                $thread->addContext($applicant->getMorphClass(), $applicant->id, 'autopilot');
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    // ===== Position Resolution =====

    /**
     * Find the primary position for an applicant (oldest posting by applied_at).
     */
    private function resolvePrimaryPosition(RecApplicant $applicant): ?RecPosition
    {
        $applicant->loadMissing(['postings.position']);

        $primaryPosting = $applicant->postings
            ->sortBy('pivot.applied_at')
            ->first();

        return $primaryPosting?->position;
    }

    /**
     * Get an effective setting value: phase → position → team cascade.
     * Only non-null values at each level override the next.
     */
    private function getEffectiveSetting(RecApplicantSettings $teamSettings, array $positionSettings, string $key, $default = null, array $phaseSettings = [])
    {
        if (array_key_exists($key, $phaseSettings) && $phaseSettings[$key] !== null) {
            return $phaseSettings[$key];
        }

        if (array_key_exists($key, $positionSettings) && $positionSettings[$key] !== null) {
            return $positionSettings[$key];
        }

        return $teamSettings->getSetting($key, $default);
    }

    // ===== Helpers =====

    private function nextAutoPilotApplicant(?int $applicantId, array $excludeIds = []): ?RecApplicant
    {
        $query = RecApplicant::query()
            ->with(['autoPilotState', 'team', 'ownedByUser', 'phase'])
            ->where('is_unrouted', false)
            ->where('auto_pilot', true)
            ->where('is_active', true)
            ->whereNull('auto_pilot_completed_at')
            ->whereNotNull('owned_by_user_id');

        if ($applicantId) {
            $query->where('id', $applicantId);
        }

        if ($this->reviewNeededStateId) {
            $query->where(function ($q) {
                $q->whereNull('auto_pilot_state_id')
                  ->orWhere('auto_pilot_state_id', '!=', $this->reviewNeededStateId);
            });
        }

        if (!empty($excludeIds)) {
            $query->whereNotIn('id', array_map('intval', $excludeIds));
        }

        return $query->orderBy('updated_at', 'asc')->first();
    }

    private function findPrimaryPhoneNumber(RecApplicant $applicant): ?CrmPhoneNumber
    {
        $applicant->loadMissing(['crmContactLinks.contact.phoneNumbers']);

        foreach ($applicant->crmContactLinks as $link) {
            $contact = $link->contact;
            if (!$contact) continue;

            $primary = $contact->phoneNumbers
                ->where('is_active', true)
                ->where('is_primary', true)
                ->whereNotNull('international')
                ->first();

            if ($primary) return $primary;

            $fallback = $contact->phoneNumbers
                ->where('is_active', true)
                ->whereNotNull('international')
                ->first();

            if ($fallback) return $fallback;
        }

        return null;
    }

    private function findPrimaryEmail(RecApplicant $applicant): ?string
    {
        $applicant->loadMissing(['crmContactLinks.contact.emailAddresses']);

        $fallback = null;
        foreach ($applicant->crmContactLinks as $link) {
            foreach ($link->contact?->emailAddresses ?? [] as $email) {
                if (!$email->is_active) continue;
                if ($email->is_primary) return $email->email_address;
                if ($fallback === null) $fallback = $email->email_address;
            }
        }

        return $fallback;
    }

    private function getContactName(RecApplicant $applicant): string
    {
        foreach ($applicant->crmContactLinks as $link) {
            $name = $link->contact?->first_name;
            if ($name) return $name;
        }
        return 'Bewerber/in';
    }

    private function extractFormToken(string $publicUrl): string
    {
        return basename(parse_url($publicUrl, PHP_URL_PATH));
    }

    private function parseTemplateBodyParams(array $components): array
    {
        $params = [];
        foreach ($components as $component) {
            if (($component['type'] ?? '') !== 'BODY') {
                continue;
            }

            $text = $component['text'] ?? '';

            $examplesByName = [];
            $namedParams = $component['example']['body_text_named_params'] ?? [];
            foreach ($namedParams as $np) {
                $examplesByName[$np['param_name']] = $np['example'] ?? '';
            }
            $positionalExamples = $component['example']['body_text'][0] ?? [];

            preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);

            foreach ($matches[1] as $i => $paramName) {
                $params[] = [
                    'name' => $paramName,
                    'example' => $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '',
                ];
            }
        }
        return $params;
    }

    private function impersonateForTask(User $user, ?Team $team): void
    {
        Auth::setUser($user);

        if ($team) {
            $user->current_team_id = (int) $team->id;
            $user->setRelation('currentTeamRelation', $team);
        }
    }

    private function logAutoPilot(RecApplicant $applicant, string $type, string $summary, ?array $details = null): void
    {
        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicant->id,
                'type' => $type,
                'summary' => $summary,
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
