<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Auth\Authenticatable;
use Platform\Comms\ChannelWhatsApp\Models\CommsChannelWhatsAppAccount;
use Platform\Comms\ChannelWhatsApp\Services\WhatsAppChannelService;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Services\Comms\PostmarkEmailService;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;

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

                $settings = RecApplicantSettings::getOrCreateForTeam($applicant->team_id);

                if (!$settings->getSetting('auto_pilot_enabled', true)) {
                    $this->line("  #{$applicant->id}: AutoPilot deaktiviert (Team-Setting) — übersprungen.");
                    continue;
                }

                $owner = $applicant->ownedByUser;
                if (!$owner) {
                    $this->line("  #{$applicant->id}: übersprungen (kein Owner).");
                    continue;
                }

                $this->info("--- Bewerbung #{$applicant->id} | Owner: {$owner->name} | Team: " . ($applicant->team?->name ?? '—'));

                if ($dryRun) {
                    $this->line("  DRY-RUN: würde verarbeitet werden.");
                    continue;
                }

                $this->impersonateForTask($owner, $applicant->team);
                $this->processApplicant($applicant, $settings);
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

    private function processApplicant(RecApplicant $applicant, RecApplicantSettings $settings): void
    {
        // 1. Check progress — complete if 100%
        $progress = $applicant->calculateProgress();
        $applicant->progress = $progress;
        $applicant->save();

        if ($progress >= 100) {
            $applicant->auto_pilot_state_id = $this->completedStateId;
            $applicant->auto_pilot_completed_at = now();
            $applicant->save();
            $this->logAutoPilot($applicant, 'completed', 'Alle Pflichtfelder ausgefüllt — AutoPilot abgeschlossen.');
            $this->info("  Alle Felder komplett — abgeschlossen.");
            return;
        }

        // 2. Resolve channel
        $channelPriority = $settings->getSetting('auto_pilot_channel_priority', 'whatsapp_first');
        $resolved = $this->resolveChannel($applicant, $channelPriority);

        if (!$resolved) {
            $this->logAutoPilot($applicant, 'warning', "Kein Kanal verfügbar (Priorität: {$channelPriority}).");
            $this->warn("  Kein Kanal verfügbar — übersprungen.");
            return;
        }

        $channelType = $resolved['type']; // 'whatsapp' or 'email'

        // 3. Get public form link
        $publicUrl = $applicant->getPublicUrl();
        $formToken = $this->extractFormToken($publicUrl);

        // 4. First contact (never sent a reminder)
        if ($applicant->auto_pilot_last_reminder_at === null) {
            $sent = $this->sendMessage($applicant, $resolved, $publicUrl, $formToken, $settings, isReminder: false);

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
        $intervalHours = (int) $settings->getSetting('auto_pilot_reminder_interval_hours', 24);
        $maxReminders = (int) $settings->getSetting('auto_pilot_max_reminders', 3);
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
            $this->info("  Max. Erinnerungen erreicht — review_needed.");
            return;
        }

        // 5b. Send reminder
        $sent = $this->sendMessage($applicant, $resolved, $publicUrl, $formToken, $settings, isReminder: true);

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
     * Resolve channel based on settings priority.
     * Returns ['type' => 'whatsapp'|'email', 'wa_account' => ?CommsChannelWhatsAppAccount, 'email_channel' => ?CommsChannel] or null.
     */
    private function resolveChannel(RecApplicant $applicant, string $priority): ?array
    {
        $teamId = $applicant->team_id;
        $applicant->loadMissing(['crmContactLinks.contact.phoneNumbers', 'crmContactLinks.contact.emailAddresses']);

        $waAccount = CommsChannelWhatsAppAccount::where('team_id', $teamId)->whereNull('deleted_at')->first();
        $emailChannel = CommsChannel::where('team_id', $teamId)->where('type', 'email')->where('is_active', true)->first();

        $hasPhone = $this->findPrimaryPhoneNumber($applicant) !== null;
        $hasEmail = $this->findPrimaryEmail($applicant) !== null;

        $canWhatsApp = $waAccount && $hasPhone;
        $canEmail = $emailChannel && $hasEmail;

        $wa = ['type' => 'whatsapp', 'wa_account' => $waAccount, 'email_channel' => null];
        $em = ['type' => 'email', 'wa_account' => null, 'email_channel' => $emailChannel];

        return match ($priority) {
            'whatsapp_first' => $canWhatsApp ? $wa : ($canEmail ? $em : null),
            'email_first' => $canEmail ? $em : ($canWhatsApp ? $wa : null),
            'whatsapp_only' => $canWhatsApp ? $wa : null,
            'email_only' => $canEmail ? $em : null,
            default => null,
        };
    }

    /**
     * Send a template (WhatsApp) or email with the public form link.
     */
    private function sendMessage(
        RecApplicant $applicant,
        array $resolved,
        string $publicUrl,
        string $formToken,
        RecApplicantSettings $settings,
        bool $isReminder = false,
    ): bool {
        if ($resolved['type'] === 'whatsapp') {
            return $this->sendWhatsAppTemplate($applicant, $resolved['wa_account'], $formToken, $settings, $isReminder);
        }

        return $this->sendEmail($applicant, $resolved['email_channel'], $publicUrl, $isReminder);
    }

    private function sendWhatsAppTemplate(
        RecApplicant $applicant,
        CommsChannelWhatsAppAccount $account,
        string $formToken,
        RecApplicantSettings $settings,
        bool $isReminder = false,
    ): bool {
        try {
            $phoneNumber = $this->findPrimaryPhoneNumber($applicant);
            if (!$phoneNumber) {
                return false;
            }

            // Resolve template from DB by ID (initial vs. reminder)
            $settingKey = $isReminder ? 'auto_pilot_wa_reminder_template_id' : 'auto_pilot_wa_initial_template_id';
            $templateId = $settings->getSetting($settingKey);
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

            $service = app(WhatsAppChannelService::class);
            $service->sendTemplate(
                account: $account,
                to: $phoneNumber->international,
                templateName: $templateName,
                languageCode: $templateLang,
                components: [
                    ['type' => 'button', 'sub_type' => 'url', 'index' => 0,
                     'parameters' => [['type' => 'text', 'text' => $formToken]]],
                ],
            );

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
            \Platform\Crm\Models\CommsEmailThread::query()
                ->where('comms_channel_id', $channel->id)
                ->whereNull('context_model')
                ->where(function ($q) use ($email) {
                    $q->where('last_outbound_to_address', $email)
                      ->orWhere('last_inbound_from_address', $email);
                })
                ->where('created_at', '>=', now()->subMinutes(5))
                ->update([
                    'context_model' => $applicant->getMorphClass(),
                    'context_model_id' => $applicant->id,
                ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    // ===== Helpers =====

    private function nextAutoPilotApplicant(?int $applicantId, array $excludeIds = []): ?RecApplicant
    {
        $query = RecApplicant::query()
            ->with(['autoPilotState', 'team', 'ownedByUser'])
            ->where('auto_pilot', true)
            ->whereNull('auto_pilot_completed_at')
            ->whereNotNull('owned_by_user_id');

        if ($applicantId) {
            $query->where('id', $applicantId);
        }

        // Skip applicants in review_needed state
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
            $name = $link->contact?->full_name;
            if ($name) return $name;
        }
        return 'Bewerber/in';
    }

    private function extractFormToken(string $publicUrl): string
    {
        return basename(parse_url($publicUrl, PHP_URL_PATH));
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
