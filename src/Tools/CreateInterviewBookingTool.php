<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class CreateInterviewBookingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.interview_bookings.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/interview-bookings - Bucht einen Bewerber für einen Interview-Termin. ERFORDERLICH: interview_id, applicant_id. Prüft Max-Teilnehmer und Stellen-Zuordnung.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID.',
                ],
                'interview_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Interview-Termins (ERFORDERLICH).',
                ],
                'applicant_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Bewerbers (ERFORDERLICH).',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Optional: Notizen.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: Status (registered/confirmed). Default: registered.',
                ],
            ],
            'required' => ['interview_id', 'applicant_id'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            $interviewId = (int)($arguments['interview_id'] ?? 0);
            $applicantId = (int)($arguments['applicant_id'] ?? 0);

            if ($interviewId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'interview_id ist erforderlich.');
            }
            if ($applicantId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'applicant_id ist erforderlich.');
            }

            $interview = RecInterview::where('team_id', $teamId)->find($interviewId);
            if (!$interview) {
                return ToolResult::error('NOT_FOUND', 'Interview-Termin nicht gefunden.');
            }

            $applicant = RecApplicant::where('team_id', $teamId)->find($applicantId);
            if (!$applicant) {
                return ToolResult::error('NOT_FOUND', 'Bewerber nicht gefunden.');
            }

            // Duplikat-Check
            $existing = RecInterviewBooking::where('rec_interview_id', $interviewId)
                ->where('rec_applicant_id', $applicantId)
                ->exists();
            if ($existing) {
                return ToolResult::error('DUPLICATE', 'Dieser Bewerber ist bereits für diesen Termin gebucht.');
            }

            // Max-Teilnehmer-Check
            if ($interview->max_participants) {
                $currentCount = RecInterviewBooking::where('rec_interview_id', $interviewId)
                    ->whereNotIn('status', ['cancelled'])
                    ->count();
                if ($currentCount >= $interview->max_participants) {
                    return ToolResult::error('CAPACITY_REACHED', "Maximale Teilnehmerzahl ({$interview->max_participants}) bereits erreicht.");
                }
            }

            $booking = RecInterviewBooking::updateOrCreate(
                [
                    'rec_interview_id' => $interviewId,
                    'rec_applicant_id' => $applicantId,
                ],
                [
                    'status' => $arguments['status'] ?? 'registered',
                    'notes' => $arguments['notes'] ?? null,
                    'booked_at' => now(),
                    'team_id' => $teamId,
                    'created_by_user_id' => $context->user?->id,
                ],
            );

            return ToolResult::success([
                'id' => $booking->id,
                'uuid' => $booking->uuid,
                'interview_id' => $interviewId,
                'applicant_id' => $applicantId,
                'status' => $booking->status,
                'message' => 'Bewerber erfolgreich gebucht.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Buchen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'interview_bookings', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
