<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class GetPostingTool implements ToolContract, ToolMetadataContract
{
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.posting.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/postings/{id} - Ruft eine einzelne Ausschreibung ab (inkl. Position, Bewerber). Parameter: posting_id (required), team_id (optional).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'posting_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Ausschreibung (ERFORDERLICH). Nutze "recruiting.postings.GET" um IDs zu finden.',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
            ],
            'required' => ['posting_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];
            $allowedTeamIds = $this->getAllowedTeamIds($teamId);

            $postingId = (int)($arguments['posting_id'] ?? 0);
            if ($postingId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'posting_id ist erforderlich.');
            }

            $posting = RecPosting::query()
                ->with([
                    'position',
                    'applicants.crmContactLinks' => fn ($q) => $q->whereIn('team_id', $allowedTeamIds),
                    'applicants.crmContactLinks.contact',
                    'applicants.applicantStatus',
                ])
                ->where('team_id', $teamId)
                ->find($postingId);

            if (!$posting) {
                return ToolResult::error('NOT_FOUND', 'Posting nicht gefunden (oder kein Zugriff).');
            }

            $applicants = $posting->applicants->map(function ($a) {
                $contact = $a->crmContactLinks->first()?->contact;
                return [
                    'id' => $a->id,
                    'uuid' => $a->uuid,
                    'contact_name' => $contact?->full_name,
                    'applicant_status' => $a->applicantStatus ? [
                        'id' => $a->applicantStatus->id,
                        'name' => $a->applicantStatus->name,
                    ] : null,
                    'applied_at' => $a->pivot?->applied_at?->toDateString(),
                    'is_active' => (bool)$a->is_active,
                ];
            })->values()->toArray();

            return ToolResult::success([
                'id' => $posting->id,
                'uuid' => $posting->uuid,
                'title' => $posting->title,
                'description' => $posting->description,
                'status' => $posting->status,
                'is_active' => (bool)$posting->is_active,
                'published_at' => $posting->published_at?->toISOString(),
                'closes_at' => $posting->closes_at?->toISOString(),
                'position' => $posting->position ? [
                    'id' => $posting->position->id,
                    'title' => $posting->position->title,
                ] : null,
                'applicants' => $applicants,
                'team_id' => $posting->team_id,
                'created_at' => $posting->created_at?->toISOString(),
                'updated_at' => $posting->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Postings: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'posting', 'get'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
