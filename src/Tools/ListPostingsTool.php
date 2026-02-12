<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListPostingsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.postings.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/postings - Listet Ausschreibungen (Postings). Parameter: team_id (optional), is_active (optional), status (optional), rec_position_id (optional), search, sort, pagination.';
    }

    public function getSchema(): array
    {
        return $this->mergeSchemas(
            $this->getStandardGetSchema(),
            [
                'properties' => [
                    'team_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                    ],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Optional: nur aktive/inaktive Postings.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Optional: Filter nach Status (z.B. "draft", "published", "closed").',
                    ],
                    'rec_position_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Filter nach Position. Nutze "recruiting.positions.GET" um IDs zu finden.',
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

            $query = RecPosting::query()
                ->with('position')
                ->withCount('applicants')
                ->forTeam($teamId);

            if (isset($arguments['is_active'])) {
                $query->where('is_active', (bool)$arguments['is_active']);
            }
            if (isset($arguments['status'])) {
                $query->where('status', (string)$arguments['status']);
            }
            if (isset($arguments['rec_position_id'])) {
                $query->where('rec_position_id', (int)$arguments['rec_position_id']);
            }

            $this->applyStandardFilters($query, $arguments, ['is_active', 'status', 'rec_position_id']);
            $this->applyStandardSearch($query, $arguments, ['title', 'description']);
            $this->applyStandardSort($query, $arguments, [
                'title', 'status', 'published_at', 'closes_at', 'created_at',
            ], 'created_at', 'desc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $data = collect($result['data'])->map(fn (RecPosting $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'title' => $p->title,
                'description' => $p->description ? mb_substr($p->description, 0, 200) : null,
                'status' => $p->status,
                'is_active' => (bool)$p->is_active,
                'position' => $p->position ? [
                    'id' => $p->position->id,
                    'title' => $p->position->title,
                ] : null,
                'applicants_count' => $p->applicants_count,
                'published_at' => $p->published_at?->toISOString(),
                'closes_at' => $p->closes_at?->toISOString(),
            ])->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Postings: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'postings', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
