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

        // Process each linked posting
        $result = null;
        foreach ($postings as $posting) {
            $result = $this->processForPosting($posting, $channel, $senderIdentifier, $senderName, $subject, $messageBody, $teamId);
        }

        return $result;
    }

    /**
     * Process an inbound message for a specific posting.
     * Handles duplicate detection and applicant creation.
     */
    private function processForPosting(
        RecPosting $posting,
        CommsChannel $channel,
        string $senderIdentifier,
        ?string $senderName,
        ?string $subject,
        ?string $messageBody,
        int $teamId,
    ): array {
        // Duplicate detection: check if this sender already applied to this posting
        $existingApplicant = $this->findExistingApplicant($senderIdentifier, $posting, $teamId);

        if ($existingApplicant) {
            Log::info('[IncomingApplicationService] Existing applicant found, appending to application', [
                'applicant_id' => $existingApplicant->id,
                'posting_id' => $posting->id,
                'sender' => $senderIdentifier,
            ]);

            // Append note about new message
            $notePrefix = now()->format('d.m.Y H:i');
            $appendNote = "[{$notePrefix}] Weitere Nachricht via {$channel->type}: " . ($subject ?? $messageBody ?? 'Nachricht erhalten');
            $existingApplicant->notes = trim(($existingApplicant->notes ?? '') . "\n" . $appendNote);
            $existingApplicant->save();

            return [
                'applicant' => $existingApplicant,
                'posting' => $posting,
                'is_new' => false,
            ];
        }

        // Create new applicant
        return DB::transaction(function () use ($posting, $channel, $senderIdentifier, $senderName, $subject, $messageBody, $teamId) {
            $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
            $defaultStatusId = $settings->getSetting('default_status_id');

            // Parse sender name
            $firstName = null;
            $lastName = null;
            if ($senderName) {
                $nameParts = $this->parseSenderName($senderName);
                $firstName = $nameParts['first_name'];
                $lastName = $nameParts['last_name'];
            }

            // Build notes from message context
            $notes = "Automatisch erstellt via {$channel->type} ({$channel->name})";
            if ($subject) {
                $notes .= "\nBetreff: {$subject}";
            }

            // Create the applicant
            $applicant = RecApplicant::create([
                'rec_applicant_status_id' => $defaultStatusId,
                'applied_at' => now()->toDateString(),
                'notes' => $notes,
                'progress' => 0,
                'team_id' => $teamId,
                'created_by_user_id' => null,
                'is_active' => true,
                'auto_pilot' => true,
            ]);

            // Create CRM contact and link it
            $this->createAndLinkContact(
                $applicant,
                $senderIdentifier,
                $firstName,
                $lastName,
                $channel->type,
                $teamId,
            );

            // Link to posting
            $applicant->postings()->attach($posting->id, [
                'applied_at' => now()->toDateString(),
                'notes' => "Eingegangen via {$channel->type}",
            ]);

            Log::info('[IncomingApplicationService] New applicant created', [
                'applicant_id' => $applicant->id,
                'posting_id' => $posting->id,
                'channel_type' => $channel->type,
                'sender' => $senderIdentifier,
            ]);

            return [
                'applicant' => $applicant,
                'posting' => $posting,
                'is_new' => true,
            ];
        });
    }

    /**
     * Find an existing applicant by sender identifier on the same posting.
     * Uses CRM contact email/phone matching to detect duplicates.
     */
    private function findExistingApplicant(string $senderIdentifier, RecPosting $posting, int $teamId): ?RecApplicant
    {
        $normalizedIdentifier = $this->normalizeIdentifier($senderIdentifier);

        // Search for applicants linked to this posting that have the same email or phone
        return RecApplicant::query()
            ->forTeam($teamId)
            ->active()
            ->whereHas('postings', fn ($q) => $q->where('rec_postings.id', $posting->id))
            ->where(function ($query) use ($normalizedIdentifier) {
                // Check via CRM contact email addresses
                $query->whereHas('crmContactLinks.contact.emailAddresses', function ($q) use ($normalizedIdentifier) {
                    $q->where('email', $normalizedIdentifier);
                });
                // Also check via CRM contact phone numbers
                $query->orWhereHas('crmContactLinks.contact.phoneNumbers', function ($q) use ($normalizedIdentifier) {
                    $q->where('number', 'like', '%' . preg_replace('/[^0-9]/', '', $normalizedIdentifier));
                });
            })
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
            $contact = \Platform\Crm\Models\CrmContact::create([
                'first_name' => $firstName ?? 'Bewerber',
                'last_name' => $lastName ?? $senderIdentifier,
                'team_id' => $teamId,
                'created_by_user_id' => null,
                'is_active' => true,
            ]);

            // Add email or phone to the contact
            if ($channelType === 'email' && filter_var($senderIdentifier, FILTER_VALIDATE_EMAIL)) {
                if (method_exists($contact, 'emailAddresses')) {
                    $contact->emailAddresses()->create([
                        'email' => $senderIdentifier,
                        'is_primary' => true,
                        'is_active' => true,
                        'team_id' => $teamId,
                    ]);
                }
            } elseif ($channelType === 'whatsapp') {
                if (method_exists($contact, 'phoneNumbers')) {
                    $contact->phoneNumbers()->create([
                        'number' => $senderIdentifier,
                        'type' => 'mobile',
                        'is_primary' => true,
                        'is_active' => true,
                        'team_id' => $teamId,
                    ]);
                }
            }

            // Link contact to applicant
            if (class_exists(\Platform\Crm\Models\CrmContactLink::class)) {
                \Platform\Crm\Models\CrmContactLink::firstOrCreate(
                    [
                        'contact_id' => $contact->id,
                        'linkable_type' => RecApplicant::class,
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
