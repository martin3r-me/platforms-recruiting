<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListPositionsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.positions.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/positions - Listet Positionen (Stellen). Parameter: team_id (optional), is_active (optional), search, sort, pagination.';
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
                        'description' => 'Optional: nur aktive/inaktive Positionen.',
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

            $query = RecPosition::query()
                ->withCount('postings')
                ->forTeam($teamId);

            if (isset($arguments['is_active'])) {
                $query->where('is_active', (bool)$arguments['is_active']);
            }

            $this->applyStandardFilters($query, $arguments, ['is_active']);
            $this->applyStandardSearch($query, $arguments, ['title', 'description', 'department', 'location']);
            $this->applyStandardSort($query, $arguments, [
                'title', 'department', 'location', 'created_at',
            ], 'title', 'asc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $data = collect($result['data'])->map(fn (RecPosition $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'title' => $p->title,
                'description' => $p->description ? mb_substr($p->description, 0, 200) : null,
                'department' => $p->department,
                'location' => $p->location,
                'is_active' => (bool)$p->is_active,
                'postings_count' => $p->postings_count,
            ])->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Positionen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'positions', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
