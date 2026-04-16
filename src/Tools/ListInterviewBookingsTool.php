<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListInterviewBookingsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.interview_bookings.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/interview-bookings - Listet Interview-Buchungen. Parameter: team_id (optional), interview_id (optional), applicant_id (optional), status (optional: registered/confirmed/attended/cancelled/no_show).';
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
                    'interview_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Filter nach Interview.',
                    ],
                    'applicant_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Filter nach Bewerber.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Optional: Filter nach Status.',
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

            $query = RecInterviewBooking::query()
                ->with(['interview', 'applicant.crmContactLinks.contact'])
                ->where('team_id', $teamId);

            if (isset($arguments['interview_id'])) {
                $query->where('rec_interview_id', (int)$arguments['interview_id']);
            }
            if (isset($arguments['applicant_id'])) {
                $query->where('rec_applicant_id', (int)$arguments['applicant_id']);
            }
            if (isset($arguments['status'])) {
                $query->where('status', (string)$arguments['status']);
            }

            $this->applyStandardFilters($query, $arguments, ['status', 'booked_at', 'created_at']);
            $this->applyStandardSort($query, $arguments, ['status', 'booked_at', 'created_at'], 'created_at', 'desc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $data = collect($result['data'])->map(fn(RecInterviewBooking $b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'interview_id' => $b->rec_interview_id,
                'interview_title' => $b->interview?->title,
                'applicant_id' => $b->rec_applicant_id,
                'candidate_name' => $b->applicant?->crmContactLinks?->first()?->contact?->full_name,
                'status' => $b->status,
                'notes' => $b->notes,
                'booked_at' => $b->booked_at?->toISOString(),
                'created_at' => $b->created_at?->toISOString(),
            ])->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Buchungen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'interview_bookings', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
