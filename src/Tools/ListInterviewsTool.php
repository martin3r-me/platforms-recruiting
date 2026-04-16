<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListInterviewsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.interviews.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/interviews - Listet Interview-Termine. Parameter: team_id (optional), interview_type_id (optional), position_id (optional), status (optional: planned/confirmed/cancelled/completed), is_active (optional), include_interviewers (optional, bool), include_bookings (optional, bool).';
    }

    public function getSchema(): array
    {
        return $this->mergeSchemas(
            $this->getStandardGetSchema(),
            [
                'properties' => [
                    'team_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Team-ID.',
                    ],
                    'interview_type_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Filter nach Interview-Typ.',
                    ],
                    'position_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Filter nach Position.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Optional: Filter nach Status (planned/confirmed/cancelled/completed).',
                    ],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Optional: nur aktive/inaktive.',
                    ],
                    'include_interviewers' => [
                        'type' => 'boolean',
                        'description' => 'Optional: Interviewer mitladen.',
                    ],
                    'include_bookings' => [
                        'type' => 'boolean',
                        'description' => 'Optional: Buchungen mitladen.',
                    ],
                ],
            ]
        );
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            $query = RecInterview::query()
                ->with(['interviewType', 'position'])
                ->forTeam($teamId);

            if (isset($arguments['interview_type_id'])) {
                $query->where('interview_type_id', (int)$arguments['interview_type_id']);
            }
            if (isset($arguments['position_id'])) {
                $query->where('rec_position_id', (int)$arguments['position_id']);
            }
            if (isset($arguments['status'])) {
                $query->where('status', (string)$arguments['status']);
            }
            if (isset($arguments['is_active'])) {
                $query->where('is_active', (bool)$arguments['is_active']);
            }

            if ($arguments['include_interviewers'] ?? false) {
                $query->with('interviewers');
            }
            if ($arguments['include_bookings'] ?? false) {
                $query->with('bookings.applicant.crmContactLinks.contact');
            }

            $this->applyStandardFilters($query, $arguments, ['status', 'starts_at', 'created_at']);
            $this->applyStandardSearch($query, $arguments, ['title', 'description', 'location']);
            $this->applyStandardSort($query, $arguments, ['title', 'starts_at', 'created_at'], 'starts_at', 'desc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $data = collect($result['data'])->map(function (RecInterview $i) use ($arguments) {
                $item = [
                    'id' => $i->id,
                    'uuid' => $i->uuid,
                    'title' => $i->title,
                    'interview_type' => $i->interviewType ? [
                        'id' => $i->interviewType->id,
                        'name' => $i->interviewType->name,
                    ] : null,
                    'position' => $i->position ? [
                        'id' => $i->position->id,
                        'title' => $i->position->title,
                    ] : null,
                    'location' => $i->location,
                    'starts_at' => $i->starts_at?->toISOString(),
                    'ends_at' => $i->ends_at?->toISOString(),
                    'min_participants' => $i->min_participants,
                    'max_participants' => $i->max_participants,
                    'status' => $i->status,
                    'is_active' => (bool)$i->is_active,
                    'created_at' => $i->created_at?->toISOString(),
                ];

                if ($arguments['include_interviewers'] ?? false) {
                    $item['interviewers'] = $i->interviewers->map(fn($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                    ])->values()->toArray();
                }

                if ($arguments['include_bookings'] ?? false) {
                    $item['bookings'] = $i->bookings->map(fn($b) => [
                        'id' => $b->id,
                        'applicant_id' => $b->rec_applicant_id,
                        'candidate_name' => $b->applicant?->crmContactLinks?->first()?->contact?->full_name,
                        'status' => $b->status,
                    ])->values()->toArray();
                }

                return $item;
            })->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Interviews: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'interviews', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
