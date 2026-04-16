<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class GetInterviewTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.interview.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/interviews/{id} - Ruft ein einzelnes Interview mit allen Details ab. Parameter: interview_id (required).';
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
                    'description' => 'ID des Interviews (ERFORDERLICH).',
                ],
            ],
            'required' => ['interview_id'],
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

            $found = $this->validateAndFindModel($arguments, $context, 'interview_id', RecInterview::class, 'NOT_FOUND', 'Interview nicht gefunden.');
            if ($found['error']) {
                return $found['error'];
            }
            $interview = $found['model'];

            if ((int)$interview->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf dieses Interview.');
            }

            $interview->load(['interviewType', 'position', 'interviewers', 'bookings.applicant.crmContactLinks.contact']);

            return ToolResult::success([
                'id' => $interview->id,
                'uuid' => $interview->uuid,
                'title' => $interview->title,
                'description' => $interview->description,
                'interview_type' => $interview->interviewType ? [
                    'id' => $interview->interviewType->id,
                    'name' => $interview->interviewType->name,
                ] : null,
                'position' => $interview->position ? [
                    'id' => $interview->position->id,
                    'title' => $interview->position->title,
                ] : null,
                'location' => $interview->location,
                'starts_at' => $interview->starts_at?->toISOString(),
                'ends_at' => $interview->ends_at?->toISOString(),
                'min_participants' => $interview->min_participants,
                'max_participants' => $interview->max_participants,
                'status' => $interview->status,
                'is_active' => (bool)$interview->is_active,
                'interviewers' => $interview->interviewers->map(fn($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                ])->values()->toArray(),
                'bookings' => $interview->bookings->map(fn($b) => [
                    'id' => $b->id,
                    'applicant_id' => $b->rec_applicant_id,
                    'candidate_name' => $b->applicant?->crmContactLinks?->first()?->contact?->full_name,
                    'status' => $b->status,
                    'notes' => $b->notes,
                    'booked_at' => $b->booked_at?->toISOString(),
                ])->values()->toArray(),
                'created_at' => $interview->created_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Interviews: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'interviews', 'get'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
