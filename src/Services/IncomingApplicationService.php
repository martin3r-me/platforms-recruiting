<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecPosting;

class IncomingApplicationService
{
    /**
     * Find all open postings linked to a given CommsChannel.
     *
     * @return \Illuminate\Database\Eloquent\Collection<RecPosting>
     */
    public function findPostingsForChannel(CommsChannel $channel): \Illuminate\Database\Eloquent\Collection
    {
        return RecPosting::query()
            ->whereHas('commsChannels', fn ($q) => $q->where('comms_channels.id', $channel->id))
            ->open()
            ->get();
    }

    /**
     * Create or update an application from an inbound message.
     *
     * Handles:
     * - Finding postings linked to the channel
     * - Duplicate detection (same sender on same channel → existing applicant)
     * - Creating new applicant with CRM contact
     * - Linking applicant to posting
     *
     * @param CommsChannel $channel       The channel the message arrived on
     * @param string       $senderIdentifier  Email address or phone number of sender
     * @param string|null  $senderName    Display name of sender (e.g. "Max Mustermann")
     * @param string|null  $subject       Email subject or message preview
     * @param string|null  $messageBody   The message text
     *
     * @return array{applicant: RecApplicant, posting: RecPosting, is_new: bool}|null
     */
    public function handleInboundMessage(
        CommsChannel $channel,
        string $senderIdentifier,
        ?string $senderName = null,
        ?string $subject = null,
        ?string $messageBody = null,
    ): ?array {
        $postings = $this->findPostingsForChannel($channel);

        if ($postings->isEmpty()) {
            Log::debug('[IncomingApplicationService] No open postings linked to channel', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);
            return null;
        }

        $teamId = $channel->team_id;

        // Skip if this sender already has an active HCM onboarding or is an employee
        if ($this->senderHasActiveHcmRecord($senderIdentifier, $channel->type, $teamId)) {
            Log::info('[IncomingApplicationService] Sender has active onboarding or employee record, skipping applicant creation', [
                'sender' => $senderIdentifier,
                'channel_type' => $channel->type,
            ]);
            return null;
        }

        // Check if this sender already has an applicant linked to any of these postings
        $existingApplicant = $this->findExistingApplicantForPostings($senderIdentifier, $postings, $teamId);

        // Fallback: breitere Suche ohne Posting-/Active-Filter
        if (!$existingApplicant) {
            $existingApplicant = $this->findExistingApplicantByContact($senderIdentifier, $teamId);
        }

        if ($existingApplicant) {
            Log::info('[IncomingApplicationService] Existing applicant found, appending to application', [
                'applicant_id' => $existingApplicant->id,
                'posting_ids' => $postings->pluck('id')->toArray(),
                'sender' => $senderIdentifier,
            ]);

            $notePrefix = now()->format('d.m.Y H:i');
            $appendNote = "[{$notePrefix}] Weitere Nachricht via {$channel->type}: " . ($subject ?? $messageBody ?? 'Nachricht erhalten');
            $existingApplicant->notes = trim(($existingApplicant->notes ?? '') . "\n" . $appendNote);
            $existingApplicant->save();

            // Ensure applicant is linked to all postings (may have new ones)
            $this->linkApplicantToPostings($existingApplicant, $postings, $channel->type);

            // Mark WhatsApp as opted-in if this message came via WhatsApp
            if ($channel->type === 'whatsapp') {
                $this->markPhoneAsWhatsAppOptedIn($existingApplicant, $senderIdentifier);
            }

            return [
                'applicant' => $existingApplicant,
                'posting' => $postings->first(),
                'is_new' => false,
            ];
        }

        // Create one applicant and link to all postings
        return DB::transaction(function () use ($postings, $channel, $senderIdentifier, $senderName, $subject, $messageBody, $teamId) {
            $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
            $defaultStatusId = $settings->getSetting('default_status_id');

            $firstName = null;
            $lastName = null;
            if ($senderName) {
                $nameParts = $this->parseSenderName($senderName);
                $firstName = $nameParts['first_name'];
                $lastName = $nameParts['last_name'];
            }

            $notes = "Automatisch erstellt via {$channel->type} ({$channel->name})";
            if ($subject) {
                $notes .= "\nBetreff: {$subject}";
            }

            // Resolve phase 1 of primary position
            $primaryPosting = $postings->first();
            $firstPhase = $primaryPosting?->position?->firstPhase();

            $applicant = RecApplicant::create([
                'rec_applicant_status_id' => $defaultStatusId,
                'rec_phase_id' => $firstPhase?->id,
                'applied_at' => now()->toDateString(),
                'notes' => $notes,
                'progress' => 0,
                'team_id' => $teamId,
                'created_by_user_id' => null,
                'is_active' => true,
                'auto_pilot' => false,
            ]);

            $this->createAndLinkContact(
                $applicant,
                $senderIdentifier,
                $firstName,
                $lastName,
                $channel->type,
                $teamId,
            );

            $this->linkApplicantToPostings($applicant, $postings, $channel->type);

            Log::info('[IncomingApplicationService] New applicant created', [
                'applicant_id' => $applicant->id,
                'posting_ids' => $postings->pluck('id')->toArray(),
                'channel_type' => $channel->type,
                'sender' => $senderIdentifier,
            ]);

            return [
                'applicant' => $applicant,
                'posting' => $postings->first(),
                'is_new' => true,
            ];
        });
    }

    /**
     * Link an applicant to all given postings (skips already existing links).
     */
    private function linkApplicantToPostings(RecApplicant $applicant, $postings, string $channelType): void
    {
        $existingPostingIds = $applicant->postings()->pluck('rec_postings.id')->toArray();

        foreach ($postings as $posting) {
            if (in_array($posting->id, $existingPostingIds)) {
                continue;
            }

            $applicant->postings()->attach($posting->id, [
                'applied_at' => now()->toDateString(),
                'notes' => "Eingegangen via {$channelType}",
            ]);
        }
    }

    /**
     * Find an existing applicant by sender identifier on any of the given postings.
     * Uses CRM contact email/phone matching to detect duplicates.
     */
    private function findExistingApplicantForPostings(string $senderIdentifier, $postings, int $teamId): ?RecApplicant
    {
        $normalizedIdentifier = $this->normalizeIdentifier($senderIdentifier);
        $postingIds = $postings->pluck('id')->toArray();
        $phoneDigits = preg_replace('/[^0-9]/', '', $normalizedIdentifier);

        return RecApplicant::query()
            ->forTeam($teamId)
            ->active()
            ->whereHas('postings', fn ($q) => $q->whereIn('rec_postings.id', $postingIds))
            ->where(function ($query) use ($normalizedIdentifier, $phoneDigits) {
                // Match by email address
                $query->whereHas('crmContactLinks.contact.emailAddresses', function ($q) use ($normalizedIdentifier) {
                    $q->where('email_address', $normalizedIdentifier);
                });
                // Only check phone numbers if we have actual digits (min 6 to be meaningful)
                if (strlen($phoneDigits) >= 6) {
                    $query->orWhereHas('crmContactLinks.contact.phoneNumbers', function ($q) use ($phoneDigits) {
                        $q->where(function ($subQ) use ($phoneDigits) {
                            $subQ->whereRaw("REPLACE(REPLACE(REPLACE(international, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . $phoneDigits])
                                 ->orWhereRaw("REPLACE(REPLACE(raw_input, ' ', ''), '-', '') LIKE ?", ['%' . $phoneDigits]);
                        });
                    });
                }
            })
            ->first();
    }

    /**
     * Broad fallback: find any non-rejected applicant in the team by contact info,
     * regardless of posting or active-scope (catches parked / hr_desk applicants).
     */
    private function findExistingApplicantByContact(string $senderIdentifier, int $teamId): ?RecApplicant
    {
        $normalizedIdentifier = $this->normalizeIdentifier($senderIdentifier);
        $phoneDigits = preg_replace('/[^0-9]/', '', $normalizedIdentifier);

        return RecApplicant::query()
            ->forTeam($teamId)
            ->where('is_active', true)
            ->whereNull('rejected_at')
            ->where(function ($query) use ($normalizedIdentifier, $phoneDigits) {
                $query->whereHas('crmContactLinks.contact.emailAddresses', function ($q) use ($normalizedIdentifier) {
                    $q->where('email_address', $normalizedIdentifier);
                });
                if (strlen($phoneDigits) >= 6) {
                    $query->orWhereHas('crmContactLinks.contact.phoneNumbers', function ($q) use ($phoneDigits) {
                        $q->where(function ($subQ) use ($phoneDigits) {
                            $subQ->whereRaw("REPLACE(REPLACE(REPLACE(international, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . $phoneDigits])
                                 ->orWhereRaw("REPLACE(REPLACE(raw_input, ' ', ''), '-', '') LIKE ?", ['%' . $phoneDigits]);
                        });
                    });
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * Create a CRM contact and link it to the applicant.
     */
    private function createAndLinkContact(
        RecApplicant $applicant,
        string $senderIdentifier,
        ?string $firstName,
        ?string $lastName,
        string $channelType,
        int $teamId,
    ): void {
        if (!class_exists(\Platform\Crm\Models\CrmContact::class)) {
            Log::warning('[IncomingApplicationService] CRM module not available, skipping contact creation');
            return;
        }

        try {
            // Try to find existing CRM contact by email or phone
            $contact = $this->findExistingCrmContact($senderIdentifier, $channelType, $teamId);

            if ($contact) {
                Log::info('[IncomingApplicationService] Existing CRM contact found, reusing', [
                    'contact_id' => $contact->id,
                    'applicant_id' => $applicant->id,
                    'sender' => $senderIdentifier,
                ]);
            } else {
                $contactStatusId = \Platform\Crm\Models\CrmContactStatus::where('code', 'ACTIVE')->first()?->id;

                $contact = \Platform\Crm\Models\CrmContact::create([
                    'first_name' => $firstName ?? 'Bewerber',
                    'last_name' => $lastName ?? $senderIdentifier,
                    'team_id' => $teamId,
                    'created_by_user_id' => null,
                    'contact_status_id' => $contactStatusId,
                    'is_active' => true,
                ]);

                // Add email or phone to the newly created contact
                if ($channelType === 'email' && filter_var($senderIdentifier, FILTER_VALIDATE_EMAIL)) {
                    $emailTypeId = \Platform\Crm\Models\CrmEmailType::where('code', 'PRIVATE')->first()?->id;
                    if ($emailTypeId) {
                        $contact->emailAddresses()->create([
                            'email_address' => $senderIdentifier,
                            'email_type_id' => $emailTypeId,
                            'is_primary' => true,
                            'is_active' => true,
                        ]);
                    }
                } elseif ($channelType === 'whatsapp') {
                    $phoneTypeId = \Platform\Crm\Models\CrmPhoneType::where('code', 'MOBILE')->first()?->id;
                    if ($phoneTypeId) {
                        $contact->phoneNumbers()->create([
                            'raw_input' => $senderIdentifier,
                            'international' => $senderIdentifier,
                            'phone_type_id' => $phoneTypeId,
                            'is_primary' => true,
                            'is_active' => true,
                            'whatsapp_status' => \Platform\Crm\Models\CrmPhoneNumber::WHATSAPP_OPTED_IN,
                        ]);
                    }
                }
            }

            // Link contact to applicant
            if (class_exists(\Platform\Crm\Models\CrmContactLink::class)) {
                \Platform\Crm\Models\CrmContactLink::firstOrCreate(
                    [
                        'contact_id' => $contact->id,
                        'linkable_type' => $applicant->getMorphClass(),
                        'linkable_id' => $applicant->id,
                    ],
                    [
                        'team_id' => $teamId,
                        'created_by_user_id' => null,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::error('[IncomingApplicationService] Failed to create CRM contact', [
                'applicant_id' => $applicant->id,
                'sender' => $senderIdentifier,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find an existing CRM contact by email address or phone number.
     */
    private function findExistingCrmContact(string $senderIdentifier, string $channelType, int $teamId): ?\Platform\Crm\Models\CrmContact
    {
        if ($channelType === 'email' && filter_var($senderIdentifier, FILTER_VALIDATE_EMAIL)) {
            return \Platform\Crm\Models\CrmContact::where('team_id', $teamId)
                ->where('is_active', true)
                ->whereHas('emailAddresses', fn ($q) => $q->where('email_address', strtolower($senderIdentifier)))
                ->first();
        }

        if ($channelType === 'whatsapp') {
            $digits = preg_replace('/[^0-9]/', '', $senderIdentifier);
            if (strlen($digits) >= 6) {
                return \Platform\Crm\Models\CrmContact::where('team_id', $teamId)
                    ->where('is_active', true)
                    ->whereHas('phoneNumbers', function ($q) use ($digits) {
                        $q->whereRaw("REPLACE(REPLACE(REPLACE(international, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . $digits]);
                    })
                    ->first();
            }
        }

        return null;
    }

    /**
     * Normalize a sender identifier (lowercase email, strip phone formatting).
     */
    private function normalizeIdentifier(string $identifier): string
    {
        // If it looks like an email
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return strtolower(trim($identifier));
        }

        // For phone numbers, keep only digits and +
        return preg_replace('/[^0-9+]/', '', $identifier);
    }

    /**
     * Parse a display name into first/last name parts.
     */
    private function parseSenderName(string $name): array
    {
        // Remove email if embedded: "Max Mustermann <max@example.com>"
        $name = preg_replace('/<[^>]+>/', '', $name);
        $name = trim($name, ' "\'');

        $parts = preg_split('/\s+/', $name, 2);

        return [
            'first_name' => $parts[0] ?? null,
            'last_name' => $parts[1] ?? null,
        ];
    }

    /**
     * Mark a phone number as WhatsApp opted-in for an applicant's linked contacts.
     */
    private function markPhoneAsWhatsAppOptedIn(RecApplicant $applicant, string $phoneNumber): void
    {
        try {
            $phoneDigits = preg_replace('/[^0-9]/', '', $phoneNumber);

            foreach ($applicant->crmContactLinks as $link) {
                $contact = $link->contact;
                if (!$contact || !method_exists($contact, 'phoneNumbers')) {
                    continue;
                }

                $matchingPhones = $contact->phoneNumbers()
                    ->where(function ($q) use ($phoneDigits) {
                        $q->whereRaw("REPLACE(REPLACE(REPLACE(international, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . $phoneDigits])
                          ->orWhereRaw("REPLACE(REPLACE(raw_input, ' ', ''), '-', '') LIKE ?", ['%' . $phoneDigits]);
                    })
                    ->get();

                foreach ($matchingPhones as $phone) {
                    $phone->markWhatsappOptedIn();
                    Log::debug('[IncomingApplicationService] Phone marked as WhatsApp opted-in', [
                        'phone_id' => $phone->id,
                        'applicant_id' => $applicant->id,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[IncomingApplicationService] Failed to mark phone as WhatsApp opted-in', [
                'applicant_id' => $applicant->id,
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if the sender already has an active HCM onboarding or employee record.
     */
    private function senderHasActiveHcmRecord(string $senderIdentifier, string $channelType, int $teamId): bool
    {
        $contact = $this->findExistingCrmContact($senderIdentifier, $channelType, $teamId);
        if (!$contact) {
            return false;
        }

        if (class_exists(\Platform\Hcm\Models\HcmOnboarding::class)) {
            $hasOnboarding = \Platform\Hcm\Models\HcmOnboarding::where('team_id', $teamId)
                ->where('is_active', true)
                ->whereHas('crmContactLinks', fn ($q) => $q->where('contact_id', $contact->id))
                ->exists();

            if ($hasOnboarding) {
                return true;
            }
        }

        if (class_exists(\Platform\Hcm\Models\HcmEmployee::class)) {
            $hasEmployee = \Platform\Hcm\Models\HcmEmployee::where('team_id', $teamId)
                ->where('is_active', true)
                ->whereHas('crmContactLinks', fn ($q) => $q->where('contact_id', $contact->id))
                ->exists();

            if ($hasEmployee) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect notification/forwarding emails and extract the real applicant email from the body.
     *
     * Website forms often send notifications from a fixed sender address (e.g. website@company.com).
     * This method parses the body for known patterns to find the actual applicant's email and name.
     *
     * @return array{email: string, name: string|null}|null
     */
    public function extractApplicantFromNotification(
        ?string $senderEmail,
        ?string $subject,
        ?string $textBody,
    ): ?array {
        if (!$textBody) {
            return null;
        }

        $email = null;
        $name = null;

        // Format A: Markdown-style "**E-Mail:** address@example.com"
        if (preg_match('/\*\*E-Mail:\*\*\s*([^\s\n]+@[^\s\n]+)/i', $textBody, $m)) {
            $email = trim($m[1]);
            if (preg_match('/\*\*Name:\*\*\s*(.+)/i', $textBody, $nm)) {
                $name = trim($nm[1]);
            }
        }

        // Format B: Plain "E-Mail\n---\naddress@example.com"
        if (!$email && preg_match('/E-Mail\n-{2,}\n([^\s\n]+@[^\s\n]+)/i', $textBody, $m)) {
            $email = trim($m[1]);
            // Extract name parts
            $firstName = null;
            $lastName = null;
            if (preg_match('/Vorname\n-{2,}\n(.+)/i', $textBody, $fn)) {
                $firstName = trim($fn[1]);
            }
            if (preg_match('/(?:^|\n)Name\n-{2,}\n(.+)/i', $textBody, $ln)) {
                $lastName = trim($ln[1]);
            }
            if ($firstName || $lastName) {
                $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
            }
        }

        // Validate extracted email
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Only use body email if it's different from sender (confirms this is a notification)
        if ($senderEmail && strtolower($email) === strtolower($senderEmail)) {
            return null;
        }

        return [
            'email' => strtolower($email),
            'name' => $name,
        ];
    }

    /**
     * Extract email address from a raw "From" header value.
     */
    public function extractEmailAddress(string $raw): ?string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            return trim((string) ($m[1] ?? '')) ?: null;
        }
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return $raw;
        }
        return null;
    }

    /**
     * Extract display name from a raw "From" header value.
     */
    public function extractDisplayName(string $raw): ?string
    {
        if (preg_match('/^(.+?)\s*<[^>]+>/', $raw, $m)) {
            $name = trim($m[1], ' "\'');
            return $name !== '' ? $name : null;
        }
        return null;
    }
}
