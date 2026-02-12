<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class GetPositionTool implements ToolContract, ToolMetadataContract
{
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.position.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/positions/{id} - Ruft eine einzelne Position ab (inkl. Postings, Bewerber-Anzahl). Parameter: position_id (required), team_id (optional).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'position_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Position (ERFORDERLICH). Nutze "recruiting.positions.GET" um IDs zu finden.',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
            ],
            'required' => ['position_id'],
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

            $positionId = (int)($arguments['position_id'] ?? 0);
            if ($positionId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'position_id ist erforderlich.');
            }

            $position = RecPosition::query()
                ->with(['postings'])
                ->where('team_id', $teamId)
                ->find($positionId);

            if (!$position) {
                return ToolResult::error('NOT_FOUND', 'Position nicht gefunden (oder kein Zugriff).');
            }

            return ToolResult::success([
                'id' => $position->id,
                'uuid' => $position->uuid,
                'title' => $position->title,
                'description' => $position->description,
                'department' => $position->department,
                'location' => $position->location,
                'is_active' => (bool)$position->is_active,
                'postings' => $position->postings->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'status' => $p->status,
                    'is_active' => (bool)$p->is_active,
                    'published_at' => $p->published_at?->toISOString(),
                    'closes_at' => $p->closes_at?->toISOString(),
                ])->values()->toArray(),
                'applicant_count' => $position->applicantCount(),
                'team_id' => $position->team_id,
                'created_at' => $position->created_at?->toISOString(),
                'updated_at' => $position->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Position: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'position', 'get'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
