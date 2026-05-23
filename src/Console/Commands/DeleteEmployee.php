<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Admin-/Test-Helper: loescht einen RecEmployee inklusive aller daran
 * haengenden Daten. Primaer fuer Test-Aufraeumung — fuer produktive
 * Loeschungen sollte stattdessen ein Soft-Delete-Workflow genutzt werden.
 *
 * Was geloescht wird:
 *  - RecEmployee selbst (force-delete)
 *  - RecEmployeeHrData (Bewertung, Anstellungsart, Snapshot-Daten)
 *  - CrmContactLink mit linkable_type=rec_employee (Employee-Seite des Links)
 *  - Zugehoeriger RecApplicant (rec_applicant_id) inkl. seiner Vertraege,
 *    Interview-Bookings, Posting-Verknuepfungen, extra_field_values, CRM-Links
 *
 * Optional (mit --with-contact):
 *  - CRM-Contact selbst (Vorsicht: wird auch von anderen Modulen referenziert)
 *
 * Aufruf:
 *   php artisan recruiting:delete-employee 3 --dry-run
 *   php artisan recruiting:delete-employee 3 --with-contact
 */
class DeleteEmployee extends Command
{
    protected $signature = 'recruiting:delete-employee
        {id : RecEmployee-ID (rec_employees.id)}
        {--with-contact : Loescht zusaetzlich den CRM-Contact des MA}
        {--dry-run : Zeigt nur was passieren wuerde, keine DB-Writes}';

    protected $description = 'Loescht einen RecEmployee + alle daran haengenden Daten (HR-Data, CRM-Links, zugehoerigen Bewerber mit Vertraegen/Bookings).';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $withContact = (bool) $this->option('with-contact');
        $dryRun = (bool) $this->option('dry-run');

        $employee = RecEmployee::with(['hrData', 'applicant.contracts', 'applicant.interviewBookings', 'applicant.crmContactLinks.contact', 'crmContactLinks.contact'])
            ->find($id);

        if (!$employee) {
            $this->error("RecEmployee #{$id} nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("RecEmployee: #{$employee->id} \"{$employee->first_name} {$employee->last_name}\"");
        $this->line("  team_id: {$employee->team_id}");
        $this->line("  rec_applicant_id: " . ($employee->rec_applicant_id ?? '—'));
        $this->line("  is_active: " . ($employee->is_active ? 'true' : 'false'));

        // HrData
        $this->line('');
        $this->line('  RecEmployeeHrData: ' . ($employee->hrData ? "ID #{$employee->hrData->id}" : '—'));

        // CRM-Links zum Employee
        $empLinks = CrmContactLink::where('linkable_type', $employee->getMorphClass())
            ->where('linkable_id', $employee->id)
            ->get();
        $this->line('  CrmContactLinks (Employee-Side): ' . $empLinks->count());

        // Applicant
        $applicant = $employee->applicant;
        if ($applicant) {
            $this->line('');
            $this->line("  Verlinkter RecApplicant: #{$applicant->id}");
            $contracts = RecContract::where('rec_applicant_id', $applicant->id)->get();
            $this->line("    Vertraege: {$contracts->count()}");
            foreach ($contracts as $c) {
                $this->line("      - Contract #{$c->id} status={$c->status}");
            }
            $this->line("    Interview-Bookings: " . $applicant->interviewBookings()->count());
            $this->line("    Postings (Pivot): " . $applicant->postings()->count());
            $this->line("    extra_field_values: " . $applicant->extraFieldValues()->count());
            $this->line("    CRM-Links (Applicant-Side): " . $applicant->crmContactLinks()->count());
        } else {
            $this->line('  Verlinkter RecApplicant: —');
        }

        // Contact-Info (fuer --with-contact)
        $primaryContactId = $employee->crmContactLinks?->first()?->contact_id;
        if ($primaryContactId) {
            $this->line('');
            $this->line("  Primaerer CRM-Contact: #{$primaryContactId}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY-RUN — keine DB-Writes. Mit --with-contact wuerde der CRM-Contact ' . ($withContact ? '#' . $primaryContactId : '(nicht gewaehlt)') . ' ebenfalls geloescht.');
            return Command::SUCCESS;
        }

        if (!$this->confirm('Wirklich endgueltig loeschen?', false)) {
            $this->warn('Abgebrochen.');
            return Command::SUCCESS;
        }

        try {
            DB::transaction(function () use ($employee, $applicant, $empLinks, $primaryContactId, $withContact) {
                // 1. HrData
                $employee->hrData()?->delete();

                // 2. CRM-Links zum Employee
                CrmContactLink::where('linkable_type', $employee->getMorphClass())
                    ->where('linkable_id', $employee->id)
                    ->delete();

                // 3. Verknuepfter Applicant + dessen Daten
                if ($applicant) {
                    RecContract::where('rec_applicant_id', $applicant->id)->delete();
                    $applicant->interviewBookings()->delete();
                    $applicant->postings()->detach();
                    $applicant->extraFieldValues()->delete();
                    $applicant->crmContactLinks()->delete();
                    $applicant->forceDelete();
                }

                // 4. Employee selbst
                $employee->forceDelete();

                // 5. Optional: CRM-Contact
                if ($withContact && $primaryContactId) {
                    $contactClass = '\\Platform\\Crm\\Models\\CrmContact';
                    if (class_exists($contactClass)) {
                        $contactClass::find($primaryContactId)?->forceDelete();
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->error("Fehler beim Loeschen: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Geloescht.');
        return Command::SUCCESS;
    }
}
