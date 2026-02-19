<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkUpdatePositionsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.positions.bulk.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/positions/bulk - Aktualisiert mehrere Positionen gleichzeitig. Jeder Eintrag in items benoetigt position_id. Max. 100 Eintraege.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'items' => [
                    'type' => 'array',
                    'description' => 'Array von Positionen zum Aktualisieren (max. 100).',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'position_id' => [
                                'type' => 'integer',
                                'description' => 'ID der Position (ERFORDERLICH).',
                            ],
                            'title' => [
                                'type' => 'string',
                                'description' => 'Optional: neuer Titel.',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Optional: neue Beschreibung.',
                            ],
                            'department' => [
                                'type' => 'string',
                                'description' => 'Optional: neue Abteilung.',
                            ],
                            'location' => [
                                'type' => 'string',
                                'description' => 'Optional: neuer Standort.',
                            ],
                            'is_active' => [
                                'type' => 'boolean',
                                'description' => 'Optional: Status.',
                            ],
                            'owned_by_user_id' => [
                                'type' => 'integer',
                                'description' => 'Optional: Owner.',
                            ],
                        ],
                        'required' => ['position_id'],
                    ],
                ],
            ],
            'required' => ['items'],
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

            $items = $arguments['items'] ?? [];
            if (empty($items)) {
                return ToolResult::error('VALIDATION_ERROR', 'items darf nicht leer sein.');
            }
            if (count($items) > 100) {
                return ToolResult::error('VALIDATION_ERROR', 'Maximal 100 Eintraege pro Bulk-Operation.');
            }

            $results = [];
            $succeeded = 0;
            $failed = 0;

            foreach ($items as $index => $item) {
                try {
                    $positionId = (int)($item['position_id'] ?? 0);
                    if ($positionId <= 0) {
                        $results[] = [
                            'index' => $index,
                            'success' => false,
                            'error' => 'VALIDATION_ERROR',
                            'error_message' => 'position_id ist erforderlich.',
                        ];
                        $failed++;
                        continue;
                    }

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

                    $fields = ['title', 'description', 'department', 'location', 'is_active', 'owned_by_user_id'];
                    foreach ($fields as $field) {
                        if (array_key_exists($field, $item)) {
                            $position->{$field} = $item[$field] === '' ? null : $item[$field];
                        }
                    }

                    $position->save();

                    $results[] = [
                        'index' => $index,
                        'position_id' => $position->id,
                        'success' => true,
                    ];
                    $succeeded++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'index' => $index,
                        'position_id' => (int)($item['position_id'] ?? 0),
                        'success' => false,
                        'error' => 'EXECUTION_ERROR',
                        'error_message' => $e->getMessage(),
                    ];
                    $failed++;
                }
            }

            return ToolResult::success([
                'processed' => count($items),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei Bulk-Aktualisierung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'positions', 'bulk', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
