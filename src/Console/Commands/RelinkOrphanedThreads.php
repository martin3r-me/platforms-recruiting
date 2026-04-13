<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Crm\Models\CommsEmailThread;
use Platform\Crm\Models\CrmEmailType;
use Platform\Crm\Models\CrmPhoneType;
use Platform\Recruiting\Models\RecApplicant;

class RelinkOrphanedThreads extends Command
{
    protected $signature = 'recruiting:relink-orphaned-threads
        {--team-id= : Team-ID (required)}
        {--limit=100 : Maximale Anzahl Bewerber}
        {--dry-run : Zeigt nur, was passieren würde}
        {--applicant-id= : Einzelnen Bewerber bearbeiten}
        {--without-email : Nur Bewerber ohne Email am CRM-Kontakt}
        {--enriched : Nur Bewerber die bereits enriched sind aber keine Daten haben}';

    protected $description = 'Verknüpft verwaiste Email-Threads mit Bewerbern anhand exakter Betreff-Übereinstimmung und extrahiert Kontaktdaten.';

    public function handle(): int
    {
        $teamId = $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $applicantId = $this->option('applicant-id');
        $withoutEmail = (bool) $this->option('without-email');
        $enrichedOnly = (bool) $this->option('enriched');

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

        if ($withoutEmail) {
            $query->whereDoesntHave('crmContactLinks.contact.emailAddresses');
        }

        if ($enrichedOnly) {
            $query->where('enrichment_status', 'enriched');
        }

        $applicants = $query->orderBy('id')->limit($limit)->get();

        $this->info("Prüfe {$applicants->count()} Bewerber...");
        $this->newLine();

        $stats = ['linked' => 0, 'email_added' => 0, 'phone_added' => 0, 'skipped' => 0, 'no_thread' => 0, 'ambiguous' => 0];

        foreach ($applicants as $applicant) {
            $contact = $applicant->crmContactLinks->first()?->contact;
            if (!$contact) {
                $stats['skipped']++;
                continue;
            }

            $morphClass = $applicant->getMorphClass();

            // Check if applicant already has linked email threads
            $hasThreads = CommsEmailThread::query()
                ->forContext($morphClass, $applicant->id)
                ->exists();

            if ($hasThreads) {
                $stats['skipped']++;
                continue;
            }

            $fullName = trim($contact->full_name);
            if (empty($fullName) || $fullName === 'Bewerber') {
                $this->line("  <fg=yellow>#{$applicant->id}</>: Kein verwertbarer Name, übersprungen.");
                $stats['skipped']++;
                continue;
            }

            // Exact subject match: "Neue Bewerbung: {Name}"
            $exactSubject = 'Neue Bewerbung: ' . $fullName;

            $threads = CommsEmailThread::query()
                ->whereHas('channel', fn ($q) => $q->where('team_id', $teamId))
                ->where('subject', $exactSubject)
                ->with(['inboundMails' => fn ($q) => $q->orderBy('received_at', 'asc')])
                ->orderByDesc('last_inbound_at')
                ->get();

            if ($threads->isEmpty()) {
                $this->line("  <fg=yellow>#{$applicant->id}</> {$fullName}: kein Thread gefunden");
                $stats['no_thread']++;
                continue;
            }

            // Extract emails from all threads to check for ambiguity
            $threadEmails = [];
            foreach ($threads as $thread) {
                $mail = $thread->inboundMails->first();
                $extracted = ($mail && $mail->text_body) ? $this->extractContactData($mail->text_body) : ['email' => null, 'phone' => null];
                $threadEmails[$thread->id] = $extracted;
            }

            // Collect unique non-null emails across threads
            $uniqueEmails = collect($threadEmails)
                ->pluck('email')
                ->filter()
                ->unique()
                ->values();

            // If multiple different emails found → ambiguous, skip
            if ($uniqueEmails->count() > 1) {
                $this->line("  <fg=red>#{$applicant->id}</> {$fullName}: AMBIG — {$threads->count()} Threads mit unterschiedlichen Emails: {$uniqueEmails->implode(', ')}");
                $stats['ambiguous']++;
                continue;
            }

            // Take the most recent thread (already sorted by last_inbound_at desc)
            $thread = $threads->first();
            $extracted = $threadEmails[$thread->id];

            $this->line("  <fg=green>#{$applicant->id}</> {$fullName} → Thread #{$thread->id} \"{$thread->subject}\"");

            if (!$dryRun) {
                $thread->addContext($morphClass, $applicant->id, 'relink_orphaned');
            }
            $stats['linked']++;

            if ($extracted['email'] && !$contact->emailAddresses->contains('email_address', $extracted['email'])) {
                $this->line("    <fg=cyan>+ Email:</> {$extracted['email']}");
                if (!$dryRun) {
                    $this->addEmail($contact, $extracted['email']);
                }
                $stats['email_added']++;
            }

            if ($extracted['phone'] && $contact->phoneNumbers->isEmpty()) {
                $this->line("    <fg=cyan>+ Telefon:</> {$extracted['phone']}");
                if (!$dryRun) {
                    $this->addPhone($contact, $extracted['phone']);
                }
                $stats['phone_added']++;
            }

            // Reset enrichment so pipeline re-processes with thread data
            if (!$dryRun) {
                $applicant->update(['enrichment_status' => null]);
            }
        }

        $this->newLine();
        $this->table(
            ['Aktion', 'Anzahl'],
            [
                ['Threads verknüpft', $stats['linked']],
                ['Emails ergänzt', $stats['email_added']],
                ['Telefon ergänzt', $stats['phone_added']],
                ['Kein Thread gefunden', $stats['no_thread']],
                ['Mehrdeutig (übersprungen)', $stats['ambiguous']],
                ['Übersprungen', $stats['skipped']],
            ]
        );

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
