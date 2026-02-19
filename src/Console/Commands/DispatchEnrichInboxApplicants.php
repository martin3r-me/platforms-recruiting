<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Platform\Recruiting\Models\RecApplicant;

class DispatchEnrichInboxApplicants extends Command
{
    protected $signature = 'recruiting:dispatch-enrich-inbox-applicants';

    protected $description = 'Holt alle Inbox-Bewerbungen (enrichment_status IS NULL) und übergibt sie einzeln an recruiting:enrich-inbox-applicants.';

    public function handle(): int
    {
        $applicants = RecApplicant::query()
            ->whereNull('enrichment_status')
            ->orderBy('created_at', 'asc')
            ->pluck('id');

        if ($applicants->isEmpty()) {
            $this->info('Keine offenen Inbox-Bewerbungen gefunden.');
            return Command::SUCCESS;
        }

        $this->info("Verarbeite {$applicants->count()} Inbox-Bewerbung(en)...");

        foreach ($applicants as $applicantId) {
            $this->line("→ Bewerbung #{$applicantId}");

            Artisan::call('recruiting:enrich-inbox-applicants', [
                '--applicant-id' => $applicantId,
                '--limit' => 1,
            ]);

            $output = trim(Artisan::output());
            if ($output !== '') {
                foreach (explode("\n", $output) as $line) {
                    $this->line("  {$line}");
                }
            }
        }

        $this->info("Fertig. {$applicants->count()} Bewerbung(en) verarbeitet.");
        return Command::SUCCESS;
    }
}
