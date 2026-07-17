<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\HrDeskRoutingService;

/**
 * Einmalige Überführung der Nicht-EU-Bestandsfälle in die
 * Nach-Schulung-Logik (Spec 2026-07-17, §5). Idempotent — kann
 * gefahrlos mehrfach laufen. IMMER zuerst mit --dry-run.
 *
 * Regel 2 nutzt bewusst NICHT HrDeskRoutingService::approveCase() —
 * dessen $userId-Parameter ist non-nullable int, und
 * rec_hr_desk_cases.resolved_by_user_id ist FK-constrained auf
 * `users` (nullable, aber constrained). Ein literaler Wert 0 hat in
 * einer Laravel-Auto-Increment-users-Tabelle keine Entsprechung und
 * würde den Foreign-Key-Constraint verletzen. Migrations-Fälle haben
 * keinen menschlichen Akteur, deshalb das Regel-1-Muster: direktes
 * $case->update ohne resolved_by_user_id + eigenes
 * hr_desk_auto_resolved-Log + releaseIfNoOtherOpenCases-Helper
 * (übernimmt die Desk-Entlassung + defensiven Progressions-Kick, die
 * approveCase() sonst erledigt hätte). approveCase()'s Zusatzverhalten
 * (Prüf-Gate, hr_desk_approved-Log) ist für den Migrations-Fall nicht
 * relevant.
 */
class MigrateNonEuCases extends Command
{
    protected $signature = 'recruiting:migrate-non-eu-cases {--dry-run : Nur zählen, nichts schreiben}';

    protected $description = 'Überführt Nicht-EU-Bestandsfälle in die Nach-Schulung-Prüfung (einmalig, idempotent)';

    public function handle(HrDeskRoutingService $routing): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = ['r1_geschlossen' => 0, 'r2_approved' => 0, 'r3_angelegt' => 0, 'r4_obsolet' => 0, 'r_offen_gelassen' => 0];

        $openCases = RecHrDeskCase::query()
            ->open()
            ->where('reason', RecHrDeskCase::REASON_NON_EU_CITIZEN)
            ->with('applicant.legalStatus')
            ->get();

        foreach ($openCases as $case) {
            $applicant = $case->applicant;
            if (!$applicant) {
                continue;
            }
            $legal = $applicant->legalStatus;
            $hasAttended = RecInterviewBooking::where('rec_applicant_id', $applicant->id)
                ->where('status', 'attended')
                ->exists();

            // Regel 4: inzwischen EU → Fall obsolet.
            if ($legal?->is_eu_citizen === true) {
                $counts['r4_obsolet']++;
                if (!$dry) {
                    $case->update([
                        'status' => RecHrDeskCase::STATUS_APPROVED,
                        'resolved_at' => now(),
                        'resolution_notes' => 'Migration: Bewerber ist inzwischen als EU-Buerger gekennzeichnet.',
                    ]);
                    // Log-Typ wie autoCloseObsoleteCases: Reporting kann
                    // human-approved von auto-closed unterscheiden.
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $applicant->id,
                        'type'             => 'hr_desk_auto_resolved',
                        'summary'          => 'Migration: Nicht-EU-Fall obsolet (inzwischen EU-Buerger).',
                    ]);
                    $this->releaseIfNoOtherOpenCases($applicant, $case);
                }
                continue;
            }

            // Regel 2: geprüft → regulär freigeben. Kein approveCase()-
            // Aufruf (siehe Klassen-Docblock: FK-constrained
            // resolved_by_user_id, kein menschlicher Akteur) —
            // stattdessen dasselbe Update-Muster wie Regel 1/4.
            if (!$applicant->isLegalStatusUnchecked()) {
                $counts['r2_approved']++;
                if (!$dry) {
                    $case->update([
                        'status' => RecHrDeskCase::STATUS_APPROVED,
                        'resolved_at' => now(),
                        'resolution_notes' => 'Migration: bereits geprueft — zurueck in den Schulungsleiter-Flow.',
                    ]);
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $applicant->id,
                        'type'             => 'hr_desk_auto_resolved',
                        'summary'          => 'Migration: Nicht-EU-Fall freigegeben (Rechtsstatus bereits geprueft).',
                    ]);
                    $this->releaseIfNoOtherOpenCases($applicant, $case);
                }
                continue;
            }

            // Regel 1: ungeprüft, Schulung noch nicht besucht → Fall
            // schließen, weiterlaufen lassen; kommt bei attended wieder.
            if (!$hasAttended) {
                $counts['r1_geschlossen']++;
                if (!$dry) {
                    $case->update([
                        'status' => RecHrDeskCase::STATUS_APPROVED,
                        'resolved_at' => now(),
                        'resolution_notes' => 'Migration: Pruefung erfolgt nach der Schulung (neuer Flow).',
                    ]);
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $applicant->id,
                        'type'             => 'hr_desk_auto_resolved',
                        'summary'          => 'Migration: Nicht-EU-Pruefung auf nach-Schulung umgestellt.',
                    ]);
                    $this->releaseIfNoOtherOpenCases($applicant, $case);
                }
                continue;
            }

            // Offen + ungeprüft + attended: liegt bereits richtig —
            // der neue Desk-Sende-Bereich bedient ihn. Nichts tun.
            $counts['r_offen_gelassen']++;
        }

        // Regel 3: ungeprüfte Prüfpflichtige (false ODER null, MIT
        // legalStatus) mit attended-Booking, aber ohne offenen Fall —
        // die heute in der Nachbereitung rot hängen.
        $candidates = RecApplicant::query()
            ->where('is_active', true)
            ->whereNull('rejected_at')
            ->whereHas('legalStatus', function ($q) {
                $q->whereNull('legal_status_checked_at')
                    ->where(fn ($q2) => $q2->where('is_eu_citizen', false)->orWhereNull('is_eu_citizen'));
            })
            ->whereHas('interviewBookings', fn ($q) => $q->where('status', 'attended'))
            ->whereDoesntHave('hrDeskCases', function ($q) {
                $q->where('reason', RecHrDeskCase::REASON_NON_EU_CITIZEN)->open();
            })
            ->get();

        foreach ($candidates as $applicant) {
            $counts['r3_angelegt']++;
            if (!$dry) {
                $routing->routeIfNotAlreadyOpen(
                    $applicant,
                    RecHrDeskCase::REASON_NON_EU_CITIZEN,
                    null,
                    'Migration: Nach Schulung — Rechtsstatus pruefen + Vertraege versenden.'
                );
            }
        }

        $this->table(['Regel', 'Anzahl'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info($dry ? 'DRY-RUN — nichts geschrieben.' : 'Migration ausgeführt.');

        return self::SUCCESS;
    }

    /**
     * Flags zurücksetzen + defensiver Progressions-Kick — Semantik wie
     * approveCase (ohne dessen Prüf-Gate; Migration IST die Ausnahme).
     */
    private function releaseIfNoOtherOpenCases(RecApplicant $applicant, RecHrDeskCase $closedCase): void
    {
        $hasOther = $applicant->hrDeskCases()
            ->where('id', '!=', $closedCase->id)
            ->open()
            ->exists();

        if ($hasOther) {
            return;
        }

        $applicant->update(['is_on_hr_desk' => false, 'auto_pilot' => true]);

        try {
            $applicant->refresh();
            $applicant->checkAutoPilotCompletion();
        } catch (\Throwable) {
            // defensiver Kick — Fehler blockiert die Migration nicht.
        }
    }
}
