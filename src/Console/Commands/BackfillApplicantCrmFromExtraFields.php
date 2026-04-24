<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\SyncApplicantExtraFieldsToCrm;

class BackfillApplicantCrmFromExtraFields extends Command
{
    protected $signature = 'recruiting:backfill-applicant-crm
        {--dry-run : Zeigt nur was geschrieben würde}
        {--applicant= : Auf einen Applicant einschränken (ID)}
        {--team= : Auf ein Team einschränken (ID)}';

    protected $description = 'Zieht vorhandene rec_applicant Extra-Field-Werte (Adresse, Geburtsdatum) in die CRM-Kanonik (crm_postal_addresses primary, crm_contacts.birth_date).';

    public function handle(SyncApplicantExtraFieldsToCrm $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $applicantId = $this->option('applicant');
        $teamId = $this->option('team');

        $query = RecApplicant::query()
            ->with(['crmContactLinks.contact.postalAddresses']);

        if ($applicantId) {
            $query->where('id', (int) $applicantId);
        }
        if ($teamId) {
            $query->where('team_id', (int) $teamId);
        }

        $total = $query->count();
        $this->components->info("Backfill Applicant → CRM (Adresse, Geburtsdatum)");
        $this->line("Applicants im Scope: {$total}" . ($dry ? '  [DRY-RUN]' : ''));
        $this->newLine();

        $changed = 0;
        $unchanged = 0;
        $skipped = 0;
        $errors = 0;

        $query->orderBy('id')->chunkById(200, function ($applicants) use ($sync, $dry, &$changed, &$unchanged, &$skipped, &$errors) {
            foreach ($applicants as $applicant) {
                try {
                    if ($dry) {
                        $preview = $this->previewLines($applicant);
                        if ($preview === null) {
                            $this->line("  · #{$applicant->id} — keine verwertbaren Extra-Field-Daten.");
                            $skipped++;
                        } else {
                            $this->line("  ✓ #{$applicant->id} — würde schreiben:");
                            foreach ($preview as $l) $this->line("      {$l}");
                            $changed++;
                        }
                        continue;
                    }

                    $result = $sync->sync($applicant);
                    if ($result->anythingChanged()) {
                        $this->line("  ✓ #{$applicant->id}");
                        foreach ($result->changed as $c) $this->line("      {$c}");
                        $changed++;
                    } elseif (!empty($result->unchanged)) {
                        $unchanged++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $this->error("  ✗ #{$applicant->id}: {$e->getMessage()}");
                    $errors++;
                }
            }
        });

        $this->newLine();
        $this->components->bulletList([
            "Geändert:    {$changed}",
            "Unverändert: {$unchanged}",
            "Übersprungen:{$skipped}",
            "Fehler:      {$errors}",
        ]);

        if ($dry) {
            $this->newLine();
            $this->warn('[DRY-RUN] Keine Writes. Ohne --dry-run erneut laufen lassen.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function previewLines(RecApplicant $applicant): ?array
    {
        $contact = $applicant->crmContactLinks->first()?->contact;
        if (!$contact) return ['(kein CRM-Contact verknüpft)'];

        $lines = [];
        $geburtsdatum = $applicant->getExtraField('geburtsdatum');
        if ($geburtsdatum) {
            $lines[] = "birth_date ← {$geburtsdatum}";
        }
        $strasse = trim((string) ($applicant->getExtraField('strasse') ?? ''));
        $hausnr  = trim((string) ($applicant->getExtraField('hausnummer') ?? ''));
        $plz     = trim((string) ($applicant->getExtraField('plz') ?? ''));
        $stadt   = trim((string) ($applicant->getExtraField('stadt') ?? ''));
        if ($strasse !== '' || $hausnr !== '' || $plz !== '' || $stadt !== '') {
            $lines[] = "postal_address ← {$strasse} {$hausnr}, {$plz} {$stadt}";
        }
        return empty($lines) ? null : $lines;
    }
}
