<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkDeletePositionsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.positions.bulk.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/positions/bulk - Loescht mehrere Positionen gleichzeitig. Parameter: ids (required, Array von Position-IDs), confirm (required=true). Positionen mit aktiven Postings werden uebersprungen. Max. 100 Eintraege.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'ids' => [
                    'type' => 'array',
                    'description' => 'Array von Position-IDs zum Loeschen (max. 100).',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'integer',
                    ],
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'ERFORDERLICH: Setze confirm=true um wirklich zu loeschen.',
                ],
            ],
            'required' => ['ids', 'confirm'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            if (!$context->user) {
                return ToolResult::error('AUTH_ERROR', 'Kein User im Kontext gefunden.');
            }

            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            if (!($arguments['confirm'] ?? false)) {
                return ToolResult::error('CONFIRMATION_REQUIRED', 'Bitte bestaetige mit confirm: true.');
            }

            $ids = $arguments['ids'] ?? [];
            if (empty($ids)) {
                return ToolResult::error('VALIDATION_ERROR', 'ids darf nicht leer sein.');
            }
            if (count($ids) > 100) {
                return ToolResult::error('VALIDATION_ERROR', 'Maximal 100 Eintraege pro Bulk-Operation.');
            }

            $results = [];
            $succeeded = 0;
            $failed = 0;

            foreach ($ids as $index => $id) {
                try {
                    $positionId = (int)$id;
                    $position = RecPosition::find($positionId);

                    if (!$position) {
                        $results[] = [
                            'index' => $index,
                            'position_id' => $positionId,
                            'success' => false,
                            'error' => 'NOT_FOUND',
                            'error_message' => 'Position nicht gefunden.',
                        ];
                        $failed++;
                        continue;
                    }

                    if ((int)$position->team_id !== $teamId) {
                        $results[] = [
                            'index' => $index,
                            'position_id' => $positionId,
                            'success' => false,
                            'error' => 'ACCESS_DENIED',
                            'error_message' => 'Kein Zugriff auf diese Position.',
                        ];
                        $failed++;
                        continue;
                    }

                    $activePostings = $position->activePostings()->count();
                    if ($activePostings > 0) {
                        $results[] = [
                            'index' => $index,
                            'position_id' => $positionId,
                            'success' => false,
                            'error' => 'HAS_ACTIVE_POSTINGS',
                            'error_message' => "Position hat {$activePostings} aktive Ausschreibung(en).",
                        ];
                        $failed++;
                        continue;
                    }

                    $position->delete();

                    $results[] = [
                        'index' => $index,
                        'position_id' => $positionId,
                        'success' => true,
                    ];
                    $succeeded++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'index' => $index,
                        'position_id' => (int)$id,
                        'success' => false,
                        'error' => 'EXECUTION_ERROR',
                        'error_message' => $e->getMessage(),
                    ];
                    $failed++;
                }
            }

            return ToolResult::success([
                'processed' => count($ids),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei Bulk-Loeschung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'positions', 'bulk', 'delete'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
