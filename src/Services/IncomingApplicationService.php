<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecSourcePlatform;

class IncomingApplicationService
{
    /**
     * Create or update an application from an inbound message.
     *
     * New staged matching semantics:
     * - Intake-Gate (Stufe 0): channel must be a recruiting intake channel.
     * - Bestandscheck: posting-independent broad lookup of existing applicants.
     * - New applicants: deterministic Stufe 1 inline (assign or suggest),
     *   otherwise dispatch the async matching job (Stufe 2-4).
     *
     * @param CommsChannel        $channel           The channel the message arrived on
     * @param string              $senderIdentifier  Email address or phone number of sender
     * @param string|null         $senderName        Display name of sender (e.g. "Max Mustermann")
     * @param string|null         $subject           Email subject or message preview
     * @param string|null         $messageBody       The message text
     * @param RecSourcePlatform|null $source         Already-detected source platform (from listener)
     *
     * @return array{applicant: RecApplicant, posting: ?RecPosting, is_new: bool}|null
     */
    public function handleInboundMessage(
        CommsChannel $channel,
        string $senderIdentifier,
        ?string $senderName = null,
        ?string $subject = null,
        ?string $messageBody = null,
        ?RecSourcePlatform $source = null,
    ): ?array {
        $matching = app(ApplicationMatchingService::class);

        if (!$matching->isIntakeChannel($channel)) {
            Log::debug('[IncomingApplicationService] Channel is not a recruiting intake channel', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);
            return null;
        }

        $teamId = $channel->team_id;

        if ($this->senderHasActiveHcmRecord($senderIdentifier, $channel->type, $teamId)) {
            Log::info('[IncomingApplicationService] Sender has active onboarding or employee record, skipping applicant creation', [
                'sender' => $senderIdentifier,
                'channel_type' => $channel->type,
            ]);
            return null;
        }

        // Bestandscheck (Stufe 0) — breite Suche, Posting-unabhängig
        $existingApplicant = $this->findExistingApplicantByContact($senderIdentifier, $teamId);

        if ($existingApplicant) {
            Log::info('[IncomingApplicationService] Existing applicant found, appending to application', [
                'applicant_id' => $existingApplicant->id,
                'sender' => $senderIdentifier,
            ]);

            $notePrefix = now()->format('d.m.Y H:i');
            $appendNote = "[{$notePrefix}] Weitere Nachricht via {$channel->type}: " . ($subject ?? $messageBody ?? 'Nachricht erhalten');
            $existingApplicant->notes = trim(($existingApplicant->notes ?? '') . "\n" . $appendNote);
            $existingApplicant->save();

            if ($channel->type === 'whatsapp') {
                $this->markPhoneAsWhatsAppOptedIn($existingApplicant, $senderIdentifier);
            }

            return [
                'applicant' => $existingApplicant,
                'posting' => $existingApplicant->postings()->first(),
                'is_new' => false,
            ];
        }

        // Neuer Bewerber: Stufe 1 inline, Stufe 2-4 asynchron im Job
        try {
            $match = $matching->matchDeterministic($channel, $source, $subject, $messageBody);
        } catch (\Throwable $e) {
            Log::warning('[IncomingApplicationService] Deterministic matching failed, falling back to async matching', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
            $match = null;
        }

        return DB::transaction(function () use ($match, $channel, $senderIdentifier, $senderName, $subject, $messageBody, $teamId) {
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

            $applicant = RecApplicant::create([
                'rec_applicant_status_id' => $defaultStatusId,
                'rec_phase_id' => null,
                'applied_at' => now()->toDateString(),
                'notes' => $notes,
                'progress' => 0,
                'team_id' => $teamId,
                'created_by_user_id' => null,
                'is_active' => true,
                'auto_pilot' => false,
                'is_unrouted' => true,
                'enrichment_status' => 'unrouted',
            ]);

            $this->createAndLinkContact($applicant, $senderIdentifier, $firstName, $lastName, $channel->type, $teamId);

            if ($match && $match->isAssignable()) {
                $this->assignPosting($applicant, $match);
            } elseif ($match) {
                // Referenz auf geschlossene Ausschreibung → Inbox mit Vorschlag
                $applicant->forceFill([
                    'suggested_posting_id' => $match->posting->id,
                    'match_reason' => $match->reason,
                ])->save();
            } else {
                \Platform\Recruiting\Jobs\MatchApplicantToPostingJob::dispatch(
                    $applicant->id,
                    $channel->id,
                    $subject,
                    $messageBody,
                )->afterCommit();
            }

            Log::info('[IncomingApplicationService] New applicant created', [
                'applicant_id' => $applicant->id,
                'matched_via' => $match?->via,
                'channel_type' => $channel->type,
                'sender' => $senderIdentifier,
            ]);

            return [
                'applicant' => $applicant,
                'posting' => ($match && $match->isAssignable()) ? $match->posting : null,
                'is_new' => true,
            ];
        });
    }

    /**
     * Assign an applicant to a posting: pivot with audit, phase from position, release enrichment.
     */
    public function assignPosting(RecApplicant $applicant, MatchResult $match): void
    {
        DB::transaction(function () use ($applicant, $match) {
            $applicant->postings()->syncWithoutDetaching([
                $match->posting->id => [
                    'applied_at' => now()->toDateString(),
                    'notes' => 'Zugeordnet via ' . $match->via,
                    'matched_via' => $match->via,
                    'match_confidence' => $match->confidence,
                ],
            ]);

            // Verantwortlichen per Kaskade setzen, falls noch keiner gesetzt ist.
            // Sonst hängen Auto-Start-Bewerber ownerlos in der AutoPilot-Query fest.
            $settings = RecApplicantSettings::getOrCreateForTeam($applicant->team_id);
            $ownerId = OwnerResolver::resolve(
                $applicant->owned_by_user_id ? (int) $applicant->owned_by_user_id : null,
                $match->posting->position?->owned_by_user_id ? (int) $match->posting->position->owned_by_user_id : null,
                (int) ($settings->getSetting('default_contact_user_id') ?? 0) ?: null,
                $applicant->team?->user_id ? (int) $applicant->team->user_id : null,
            );

            $applicant->forceFill([
                'rec_phase_id' => $applicant->rec_phase_id ?? $match->posting->position?->firstPhase()?->id,
                'owned_by_user_id' => $ownerId,
                'is_unrouted' => false,
                'suggested_posting_id' => null,
                'match_reason' => null,
                'enrichment_status' => null, // Enrichment-Scheduler greift jetzt
            ])->save();

            Log::info('[IncomingApplicationService] Applicant assigned to posting', [
                'applicant_id' => $applicant->id,
                'posting_id' => $match->posting->id,
                'matched_via' => $match->via,
                'confidence' => $match->confidence,
            ]);
        });
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
