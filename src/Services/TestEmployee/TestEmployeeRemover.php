<?php

namespace Platform\Recruiting\Services\TestEmployee;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\ContextFile;
use Platform\Core\Services\ContextFileService;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Models\CrmContactListMember;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Models\RecApplicantLegalStatus;

/**
 * Raeumt einen Test-MA (recruiting:test-ma --remove) restlos weg — samt allem,
 * was der TestEmployeeCreator und der echte Pfad dahinter angelegt haben.
 *
 * Der Loeschkern entspricht recruiting:delete-employee (HR-Daten, Links,
 * Bewerber mit Vertraegen/Buchungen/Pivot/Feldwerten, MA, Kontakt). Er ist
 * hier nachgebaut statt aus dem Command gezogen: dort haengt er an der
 * interaktiven Ausgabe/Rueckfrage, und das Verhalten des Commands soll sich
 * nicht aendern. Zusaetzlich: Rechtsstatus, Phasen-Uebergaenge, AutoPilot-Log,
 * Schulungszertifikat, Kontaktlisten-Mitgliedschaft, E-Mail/Telefon des
 * Kontakts und die ContextFiles (Datensatz + Datei).
 *
 * Sicherheit: nur Test-Daten. Ein MA mit is_test=false oder ein verlinkter
 * Bewerber ohne is_test wird verweigert, bevor irgendetwas geloescht wird.
 * Der CRM-Kontakt geht nur mit, wenn ihn sonst nichts verlinkt — sonst wird
 * nur UNSERE Verknuepfung geloest und das im Report genannt.
 */
final class TestEmployeeRemover
{
    /**
     * @return array<string,int> Zaehler je Tabelle; leer = nichts zu tun
     *                           (MA existiert nicht — auch beim zweiten Aufruf).
     *
     * @throws \InvalidArgumentException wenn MA oder Bewerber kein Test-Datensatz ist
     */
    public function remove(int $employeeId): array
    {
        $employee = RecEmployee::find($employeeId);
        if (!$employee) {
            return [];
        }

        if (!$employee->is_test) {
            throw new \InvalidArgumentException(
                "MA #{$employeeId} ist kein Test-MA (is_test=false) — nichts geloescht. "
                . 'Echte MA loescht man mit recruiting:delete-employee.'
            );
        }

        $applicant = $employee->rec_applicant_id ? RecApplicant::find($employee->rec_applicant_id) : null;
        if ($applicant && !$applicant->is_test) {
            throw new \InvalidArgumentException(
                "Der Bewerber #{$applicant->id} zu MA #{$employeeId} ist kein Test-Bewerber (is_test=false) — nichts geloescht."
            );
        }

        // Buchungen eines Test-MA wuerden nach dem Loeschen an ZAS durchsickern
        // (der Bestaetigungs-Export schliesst nur EXISTIERENDE Test-MA aus).
        $buchungen = DB::table('rec_dispo_assignments')
            ->where('rec_employee_id', $employeeId)
            ->pluck('rec_dispo_event_id');
        if ($buchungen->isNotEmpty()) {
            $refs = DB::table('rec_dispo_events')->whereIn('id', $buchungen->unique()->all())
                ->pluck('einsatz_ref')->filter()->unique()->values()->all();
            throw new \InvalidArgumentException(
                "MA #{$employeeId} hat Dispo-Buchungen ({$buchungen->count()}) — nichts geloescht. Erst die Test-VA entfernen: "
                . ($refs === []
                    ? 'php artisan recruiting:dispo-test-va --remove --ref=<einsatz_ref>'
                    : implode(' | ', array_map(fn ($r) => "php artisan recruiting:dispo-test-va --remove --ref={$r}", $refs)))
                . '.'
            );
        }

        // Vor dem Loeschen zaehlen: die Kontexte selbst bleiben stehen.
        $threads = $this->verbleibendeThreads($employee, $applicant);

        $dateiIds = [];

        $report = DB::transaction(function () use ($employee, $applicant, &$dateiIds) {
            $report = [];
            $zaehle = function (string $tabelle, int $anzahl) use (&$report): void {
                if ($anzahl > 0) {
                    $report[$tabelle] = ($report[$tabelle] ?? 0) + $anzahl;
                }
            };

            $employeeMorph = $employee->getMorphClass();
            $applicantMorph = $applicant?->getMorphClass();

            // Kontakte vorab merken: nach dem Loesen der Links weiss man sie nicht mehr.
            $kontaktIds = CrmContactLink::query()
                ->where(fn ($q) => $q
                    ->where(fn ($e) => $e->where('linkable_type', $employeeMorph)->where('linkable_id', $employee->id))
                    ->when($applicantMorph, fn ($q) => $q->orWhere(
                        fn ($a) => $a->where('linkable_type', $applicantMorph)->where('linkable_id', $applicant->id)
                    )))
                ->pluck('contact_id')->unique()->values()->all();

            $dateiIds = ContextFile::query()
                ->where(fn ($q) => $q
                    ->where(fn ($e) => $e->whereIn('context_type', ['rec_employee', RecEmployee::class])->where('context_id', $employee->id))
                    ->when($applicant, fn ($q) => $q->orWhere(
                        fn ($a) => $a->whereIn('context_type', ['rec_applicant', RecApplicant::class])->where('context_id', $applicant->id)
                    )))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            // Dispo-Reste ohne Buchung: Angebote (samt Schichten), Absage-Pruefungen, Weiterleitungen.
            // rec_dispo_job_requests haben keine MA-Spalte und bleiben.
            $angebote = DB::table('rec_dispo_job_offers')->where('rec_employee_id', $employee->id)->pluck('id')->all();
            if ($angebote !== []) {
                $zaehle('rec_dispo_job_offer_shifts', DB::table('rec_dispo_job_offer_shifts')->whereIn('offer_id', $angebote)->delete());
                $zaehle('rec_dispo_job_offers', DB::table('rec_dispo_job_offers')->whereIn('id', $angebote)->delete());
            }
            $zaehle('rec_dispo_decline_checks', DB::table('rec_dispo_decline_checks')->where('rec_employee_id', $employee->id)->delete());
            $zaehle('rec_conversation_forwards', DB::table('rec_conversation_forwards')->where('rec_employee_id', $employee->id)->delete());

            $zaehle('rec_employee_hr_data', RecEmployeeHrData::where('rec_employee_id', $employee->id)->delete());
            $zaehle('rec_contracts', RecContract::where('rec_employee_id', $employee->id)->delete());
            $zaehle('crm_contact_links', CrmContactLink::where('linkable_type', $employeeMorph)
                ->where('linkable_id', $employee->id)->delete());

            if ($applicant) {
                $zaehle('rec_contracts', RecContract::where('rec_applicant_id', $applicant->id)->delete());
                $zaehle('rec_interview_bookings', $applicant->interviewBookings()->delete());
                $zaehle('rec_applicant_posting', $applicant->postings()->detach());
                $zaehle('core_extra_field_values', $applicant->extraFieldValues()->delete());
                $zaehle('crm_contact_links', $applicant->crmContactLinks()->delete());
                $zaehle('rec_applicant_legal_statuses', RecApplicantLegalStatus::where('rec_applicant_id', $applicant->id)->delete());
                $zaehle('rec_phase_transitions', RecPhaseTransition::where('rec_applicant_id', $applicant->id)->delete());
                $zaehle('rec_auto_pilot_logs', RecAutoPilotLog::where('rec_applicant_id', $applicant->id)->delete());
                $zaehle('rec_training_certificates', RecTrainingCertificate::where('rec_applicant_id', $applicant->id)->delete());
                $applicant->forceDelete();
                $zaehle('rec_applicants', 1);
            }

            $employee->forceDelete();
            $zaehle('rec_employees', 1);

            foreach ($kontaktIds as $kontaktId) {
                // Haengt noch etwas anderes dran (z. B. ein echter Bewerber), bleibt der Kontakt.
                if (CrmContactLink::where('contact_id', $kontaktId)->exists()) {
                    $zaehle('crm_contacts_behalten', 1);
                    continue;
                }

                $kontakt = CrmContact::find($kontaktId);
                if (!$kontakt) {
                    continue;
                }

                $zaehle('crm_contact_list_members', CrmContactListMember::where('contact_id', $kontaktId)->delete());
                $zaehle('crm_email_addresses', $kontakt->emailAddresses()->delete());
                $zaehle('crm_phone_numbers', $kontakt->phoneNumbers()->delete());
                $kontakt->forceDelete();
                $zaehle('crm_contacts', 1);
            }

            $zaehle('context_files', count($dateiIds));

            return $report;
        });

        // Dateien erst NACH dem Commit: ein Rollback haette sonst Zeilen ohne Datei hinterlassen.
        // Der Core-Dienst loescht Varianten, Original und Datensatz.
        // Eine fehlende Datei (findOrFail) darf die uebrigen nicht aufhalten.
        $dienst = app(ContextFileService::class);
        $fehlgeschlagen = 0;
        foreach ($dateiIds as $id) {
            try {
                $dienst->delete($id);
            } catch (\Throwable) {
                $fehlgeschlagen++;
            }
        }
        if ($fehlgeschlagen > 0) {
            $report['context_files_fehlgeschlagen'] = $fehlgeschlagen;
        }
        if ($threads > 0) {
            $report['comms_threads_verbleibend'] = $threads;
        }

        return $report;
    }

    /** Kommunikations-Threads, die noch am MA/Bewerber haengen (werden NICHT geloescht, nur gemeldet). */
    private function verbleibendeThreads(RecEmployee $employee, ?RecApplicant $applicant): int
    {
        if (!Schema::hasTable('comms_thread_contexts')) {
            return 0;
        }

        return DB::table('comms_thread_contexts')
            ->where(fn ($q) => $q
                ->where(fn ($e) => $e->whereIn('context_model', ['rec_employee', RecEmployee::class])->where('context_model_id', $employee->id))
                ->when($applicant, fn ($q) => $q->orWhere(
                    fn ($a) => $a->whereIn('context_model', ['rec_applicant', RecApplicant::class])->where('context_model_id', $applicant->id)
                )))
            ->selectRaw('thread_type, thread_id')->distinct()->get()->count();
    }
}
