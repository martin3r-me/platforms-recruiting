<?php

namespace Platform\Recruiting\Tools;

use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class GetRecruitingLookupTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.lookup.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/lookup - Listet Eintraege aus einer Recruiting-Lookup-Tabelle. Nutze recruiting.lookups.GET fuer verfuegbare lookup keys. Unterstuetzt Suche/Filter/Sort/Pagination.';
    }

    public function getSchema(): array
    {
        return $this->mergeSchemas(
            $this->getStandardGetSchema([
                'lookup',
                'team_id',
                'is_active',
                'code',
            ]),
            [
                'properties' => [
                    'lookup' => [
                        'type' => 'string',
                        'description' => 'ERFORDERLICH. Lookup-Key. Nutze recruiting.lookups.GET um die Keys zu sehen.',
                        'enum' => array_values(self::LOOKUP_KEYS),
                    ],
                    'team_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Team-ID. Fuer team-scoped Lookups erforderlich (Default: Team aus Kontext).',
                    ],
                    'code' => [
                        'type' => 'string',
                        'description' => 'Optional: Exakter code-Filter.',
                    ],
                    'is_active' => [
                        'type' => 'boolean',
                        'description' => 'Optional: Filter nach is_active (falls Feld vorhanden).',
                    ],
                ],
                'required' => ['lookup'],
            ]
        );
    }

    private const LOOKUP_KEYS = [
        'applicant_statuses',
        'auto_pilot_states',
    ];

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $lookup = (string)($arguments['lookup'] ?? '');
            if ($lookup === '') {
                return ToolResult::error('VALIDATION_ERROR', 'lookup ist erforderlich. Nutze recruiting.lookups.GET.');
            }

            $cfg = $this->resolveLookup($lookup);
            if ($cfg === null) {
                return ToolResult::error('VALIDATION_ERROR', 'Unbekannter lookup. Nutze recruiting.lookups.GET.');
            }

            $teamId = null;
            if (($cfg['team_scoped'] ?? false) === true) {
                $resolved = $this->resolveTeam($arguments, $context);
                if ($resolved['error']) {
                    return $resolved['error'];
                }
                $teamId = (int)$resolved['team_id'];
            } elseif (isset($arguments['team_id']) && (int)$arguments['team_id'] > 0) {
                $teamId = (int)$arguments['team_id'];
            } elseif ($context->team?->id) {
                $teamId = (int)$context->team->id;
            }

            /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
            $modelClass = $cfg['model'];
            $table = (new $modelClass())->getTable();

            /** @var Builder $q */
            $q = $modelClass::query();

            if (array_key_exists('code', $arguments) && $arguments['code'] !== null && $arguments['code'] !== '') {
                $q->where('code', trim((string)$arguments['code']));
            }

            if (array_key_exists('is_active', $arguments) && $this->modelHasColumn($modelClass, 'is_active')) {
                $q->where('is_active', (bool)$arguments['is_active']);
            }

            if (($cfg['scope'] ?? null) === 'team_id' && $teamId) {
                $q->where($table . '.team_id', (int)$teamId);
            }

            $this->applyStandardFilters($q, $arguments, ['is_active', 'code', 'team_id']);
            $this->applyStandardSearch($q, $arguments, $cfg['search_fields']);
            $this->applyStandardSort(
                $q,
                $arguments,
                $cfg['sort_fields'],
                $cfg['default_sort_field'],
                $cfg['default_sort_dir']
            );

            $paginationResult = $this->applyStandardPaginationResult($q, $arguments);
            $items = $paginationResult['data']->map(fn ($m) => [
                'id' => $m->id ?? null,
                'name' => $m->name ?? null,
                'code' => $m->code ?? null,
                'description' => $m->description ?? null,
                'is_active' => property_exists($m, 'is_active') ? (bool)($m->is_active ?? true) : true,
            ])->values()->toArray();

            return ToolResult::success([
                'lookup' => $lookup,
                'items' => $items,
                'pagination' => $paginationResult['pagination'],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Lookups: ' . $e->getMessage());
        }
    }

    private function resolveLookup(string $lookup): ?array
    {
        return match ($lookup) {
            'applicant_statuses' => [
                'model' => RecApplicantStatus::class,
                'team_scoped' => true,
                'scope' => 'team_id',
                'search_fields' => ['name', 'code', 'description'],
                'sort_fields' => ['name', 'code', 'created_at'],
                'default_sort_field' => 'name',
                'default_sort_dir' => 'asc',
            ],
            'auto_pilot_states' => [
                'model' => RecAutoPilotState::class,
                'team_scoped' => false,
                'scope' => null,
                'search_fields' => ['name', 'code', 'description'],
                'sort_fields' => ['name', 'code', 'created_at'],
                'default_sort_field' => 'name',
                'default_sort_dir' => 'asc',
            ],
            default => null,
        };
    }

    private function modelHasColumn(string $modelClass, string $column): bool
    {
        try {
            $table = (new $modelClass())->getTable();
            return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['recruiting', 'lookup', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
