<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecHrDeskCase;

class HrDeskRoutingService
{
    public function handleEuStatusChange(RecApplicant $applicant, ?bool $isEuCitizen, ?int $userId = null): void
    {
        if ($isEuCitizen !== false) {
            return;
        }

        // Non-EU: check if there's already an open case for this reason
        $existingOpenCase = $applicant->hrDeskCases()
            ->where('reason', RecHrDeskCase::REASON_NON_EU_CITIZEN)
            ->open()
            ->exists();

        if (!$existingOpenCase) {
            $this->routeToHrDesk($applicant, RecHrDeskCase::REASON_NON_EU_CITIZEN, $userId);
        }
    }

    public function routeToHrDesk(RecApplicant $applicant, string $reason, ?int $userId = null): RecHrDeskCase
    {
        $applicant->update([
            'is_on_hr_desk' => true,
            'auto_pilot' => false,
        ]);

        $case = RecHrDeskCase::create([
            'rec_applicant_id' => $applicant->id,
            'team_id' => $applicant->team_id,
            'reason' => $reason,
            'status' => RecHrDeskCase::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by_user_id' => $userId,
        ]);

        RecAutoPilotLog::create([
            'rec_applicant_id' => $applicant->id,
            'type' => 'hr_desk_routed',
            'summary' => "Bewerber auf HR-Schreibtisch verschoben (Grund: {$reason}).",
        ]);

        return $case;
    }

    public function approveCase(RecHrDeskCase $case, int $userId, ?string $notes = null): void
    {
        $case->update([
            'status' => RecHrDeskCase::STATUS_APPROVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_notes' => $notes,
        ]);

        $applicant = $case->applicant;

        // Only release from HR desk if no other open cases remain
        $hasOtherOpenCases = $applicant->hrDeskCases()
            ->where('id', '!=', $case->id)
            ->open()
            ->exists();

        if (!$hasOtherOpenCases) {
            $applicant->update(['is_on_hr_desk' => false]);
        }

        RecAutoPilotLog::create([
            'rec_applicant_id' => $applicant->id,
            'type' => 'hr_desk_approved',
            'summary' => "HR-Schreibtisch-Fall freigegeben." . ($notes ? " Notiz: {$notes}" : ''),
        ]);
    }

    public function rejectCase(RecHrDeskCase $case, int $userId, ?string $notes = null): void
    {
        $case->update([
            'status' => RecHrDeskCase::STATUS_REJECTED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_notes' => $notes,
        ]);

        $applicant = $case->applicant;
        $applicant->update([
            'rejected_at' => now(),
            'is_on_hr_desk' => false,
            'auto_pilot' => false,
            'is_active' => false,
        ]);

        RecAutoPilotLog::create([
            'rec_applicant_id' => $applicant->id,
            'type' => 'hr_desk_rejected',
            'summary' => "Bewerber über HR-Schreibtisch abgelehnt." . ($notes ? " Notiz: {$notes}" : ''),
        ]);
    }
}
