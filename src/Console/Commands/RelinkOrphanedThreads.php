<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Crm\Models\CommsEmailThread;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Models\CrmEmailType;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Models\CrmPhoneType;
use Platform\Recruiting\Models\RecApplicant;

class RelinkOrphanedThreads extends Command
{
    protected $signature = 'recruiting:relink-orphaned-threads
        {--team-id= : Team-ID (required)}
        {--limit=50 : Maximale Anzahl Bewerber}
        {--dry-run : Zeigt nur, was passieren würde}
        {--applicant-id= : Einzelnen Bewerber bearbeiten}';

    protected $description = 'Verknüpft verwaiste Email-Threads mit Bewerbern anhand des CRM-Kontaktnamens und extrahiert Kontaktdaten.';

    public function handle(): int
    {
        $teamId = $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $applicantId = $this->option('applicant-id');

        if (!$teamId) {
            $this->error('--team-id ist erforderlich.');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — es werden keine Daten geändert.');
        }

        $query = RecApplicant::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereHas('crmContactLinks.contact')
            ->with(['crmContactLinks.contact.emailAddresses', 'crmContactLinks.contact.phoneNumbers']);

        if ($applicantId) {
            $query->where('id', $applicantId);
        }

        $applicants = $query->orderBy('id')->limit($limit)->get();

        $this->info("Prüfe {$applicants->count()} Bewerber...");

        $linked = 0;
        $enriched = 0;
        $skipped = 0;

        foreach ($applicants as $applicant) {
            $contact = $applicant->crmContactLinks->first()?->contact;
            if (!$contact) {
                $skipped++;
                continue;
            }

            $morphClass = $applicant->getMorphClass();

            // Check if applicant already has linked email threads
            $hasThreads = CommsEmailThread::query()
                ->forContext($morphClass, $applicant->id)
                ->exists();

            if ($hasThreads) {
                $this->line("  #{$applicant->id} {$contact->full_name}: Thread bereits verknüpft, übersprungen.");
                $skipped++;
                continue;
            }

            // Search for threads by contact name in subject
            $fullName = trim($contact->full_name);
            if (empty($fullName) || $fullName === 'Bewerber') {
                $this->line("  #{$applicant->id}: Kein verwertbarer Name, übersprungen.");
                $skipped++;
                continue;
            }

            $threads = CommsEmailThread::query()
                ->whereHas('channel', fn ($q) => $q->where('team_id', $teamId))
                ->where('subject', 'LIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $fullName) . '%')
                ->with(['inboundMails' => fn ($q) => $q->orderBy('received_at', 'asc')])
                ->orderByDesc('last_inbound_at')
                ->limit(3)
                ->get();

            if ($threads->isEmpty()) {
                $this->line("  #{$applicant->id} {$fullName}: Kein passender Thread gefunden.");
                $skipped++;
                continue;
            }

            $this->info("  #{$applicant->id} {$fullName}: {$threads->count()} Thread(s) gefunden.");

            foreach ($threads as $thread) {
                $this->line("    Thread #{$thread->id}: \"{$thread->subject}\"");

                if (!$dryRun) {
                    $thread->addContext($morphClass, $applicant->id, 'relink_orphaned');
                }
                $linked++;

                // Extract contact data from first inbound mail body
                $mail = $thread->inboundMails->first();
                if (!$mail || !$mail->text_body) {
                    continue;
                }

                $extracted = $this->extractContactData($mail->text_body);

                if ($extracted['email'] && !$contact->emailAddresses->contains('email_address', $extracted['email'])) {
                    $this->info("    + Email: {$extracted['email']}");
                    if (!$dryRun) {
                        $this->addEmail($contact, $extracted['email']);
                    }
                    $enriched++;
                }

                if ($extracted['phone'] && $contact->phoneNumbers->isEmpty()) {
                    $this->info("    + Telefon: {$extracted['phone']}");
                    if (!$dryRun) {
                        $this->addPhone($contact, $extracted['phone']);
                    }
                    $enriched++;
                }
            }

            // Set enrichment_status so enrichment pipeline picks it up
            if (!$dryRun) {
                $applicant->update(['enrichment_status' => null]);
            }
        }

        $this->newLine();
        $this->info("Fertig. Threads verknüpft: {$linked} | Kontaktdaten ergänzt: {$enriched} | Übersprungen: {$skipped}");

        return Command::SUCCESS;
    }

    private function extractContactData(string $textBody): array
    {
        $email = null;
        $phone = null;

        // Format A: Markdown "**E-Mail:** address@example.com"
        if (preg_match('/\*\*E-Mail:\*\*\s*([^\s\n]+@[^\s\n]+)/i', $textBody, $m)) {
            $candidate = trim($m[1]);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $email = strtolower($candidate);
            }
        }

        // Format B: Plain "E-Mail\n---\naddress@example.com"
        if (!$email && preg_match('/E-Mail\n-{2,}\n([^\s\n]+@[^\s\n]+)/i', $textBody, $m)) {
            $candidate = trim($m[1]);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $email = strtolower($candidate);
            }
        }

        // Phone: Markdown "**Telefon:** +49..."
        if (preg_match('/\*\*Telefon:\*\*\s*([+\d\s\-()]+)/i', $textBody, $m)) {
            $phone = trim($m[1]);
        }

        // Phone: Plain "Telefon\n---\n+49..."
        if (!$phone && preg_match('/Telefon\n-{2,}\n([+\d\s\-()]+)/i', $textBody, $m)) {
            $phone = trim($m[1]);
        }

        return ['email' => $email, 'phone' => $phone];
    }

    private function addEmail($contact, string $email): void
    {
        try {
            $typeId = CrmEmailType::where('code', 'PRIVATE')->first()?->id;
            $contact->emailAddresses()->create([
                'email_address' => $email,
                'email_type_id' => $typeId,
                'is_primary' => true,
                'is_active' => true,
            ]);
        } catch (\Throwable $e) {
            $this->warn("    Email-Fehler: {$e->getMessage()}");
        }
    }

    private function addPhone($contact, string $phone): void
    {
        try {
            $typeId = CrmPhoneType::where('code', 'MOBILE')->first()?->id;
            $contact->phoneNumbers()->create([
                'raw_input' => $phone,
                'international' => $phone,
                'phone_type_id' => $typeId,
                'is_primary' => true,
                'is_active' => true,
            ]);
        } catch (\Throwable $e) {
            $this->warn("    Telefon-Fehler: {$e->getMessage()}");
        }
    }
}
