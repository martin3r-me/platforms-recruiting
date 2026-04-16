<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListContractTemplatesTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.contract_templates.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/contract-templates - Listet Vertragsvorlagen (z.B. Arbeitsvertrag, Infektionsschutzgesetz). Parameter: team_id (optional), is_active (optional), filters/search/sort/limit/offset (optional).';
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
                        'description' => 'Optional: nur aktive/inaktive Vorlagen.',
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

            $query = RecContractTemplate::query()
                ->where('team_id', $teamId)
                ->withCount('contracts');

            if (isset($arguments['is_active'])) {
                $query->where('is_active', (bool)$arguments['is_active']);
            }

            $this->applyStandardFilters($query, $arguments, ['name', 'code', 'is_active', 'requires_signature', 'created_at']);
            $this->applyStandardSearch($query, $arguments, ['name', 'code', 'description']);
            $this->applyStandardSort($query, $arguments, ['name', 'sort_order', 'created_at'], 'sort_order', 'asc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $data = collect($result['data'])->map(fn(RecContractTemplate $t) => [
                'id' => $t->id,
                'uuid' => $t->uuid,
                'name' => $t->name,
                'code' => $t->code,
                'description' => $t->description,
                'field_mappings' => $t->field_mappings,
                'requires_signature' => (bool)$t->requires_signature,
                'sort_order' => $t->sort_order,
                'is_active' => (bool)$t->is_active,
                'contracts_count' => $t->contracts_count,
                'created_at' => $t->created_at?->toISOString(),
            ])->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Vertragsvorlagen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'contract_templates', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
