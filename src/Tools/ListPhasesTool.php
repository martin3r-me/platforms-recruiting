<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListPhasesTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.phases.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/phases - Listet Phasen einer Position. Parameter: position_id (required), is_active (optional), filters/search/sort/limit/offset.';
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
                    'position_id' => [
                        'type' => 'integer',
                        'description' => 'ID der Position (ERFORDERLICH). Nutze "recruiting.positions.GET" um IDs zu finden.',
                    ],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Optional: nur aktive/inaktive Phasen.',
                    ],
                ],
                'required' => ['position_id'],
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

            $positionId = (int)($arguments['position_id'] ?? 0);
            if ($positionId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'position_id ist erforderlich.');
            }

            $query = RecPhase::query()
                ->withCount('applicants')
                ->forTeam($teamId)
                ->where('rec_position_id', $positionId);

            if (isset($arguments['is_active'])) {
                $query->where('is_active', (bool)$arguments['is_active']);
            }

            $this->applyStandardFilters($query, $arguments, ['name', 'order', 'is_active', 'auto_advance']);
            $this->applyStandardSearch($query, $arguments, ['name']);
            $this->applyStandardSort($query, $arguments, ['name', 'order', 'created_at'], 'order', 'asc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $data = collect($result['data'])->map(fn(RecPhase $phase) => [
                'id' => $phase->id,
                'uuid' => $phase->uuid,
                'name' => $phase->name,
                'order' => $phase->order,
                'is_active' => (bool)$phase->is_active,
                'auto_advance' => (bool)$phase->auto_advance,
                'auto_pilot_settings' => $phase->auto_pilot_settings,
                'completion_type' => $phase->completion_type,
                'completion_config' => $phase->completion_config,
                'show_in_dashboard' => (bool) $phase->show_in_dashboard,
                'applicants_count' => $phase->applicants_count,
                'extra_fields' => $phase->getExtraFieldDefinitions()->map(fn($def) => [
                    'id' => $def->id,
                    'name' => $def->name,
                    'label' => $def->label,
                    'type' => $def->type,
                    'is_required' => (bool)$def->is_required,
                    'order' => $def->order,
                ])->values()->toArray(),
                'created_at' => $phase->created_at?->toISOString(),
            ])->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
                'position_id' => $positionId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Phasen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'phases', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
