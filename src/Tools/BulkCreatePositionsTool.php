<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkCreatePositionsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.positions.bulk.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/positions/bulk - Erstellt mehrere Positionen (Stellen) gleichzeitig. Jeder Eintrag in items benoetigt title. Max. 100 Eintraege.';
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
                    'description' => 'Array von Positionen zum Erstellen (max. 100).',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'Titel der Position (ERFORDERLICH).',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Optional: Beschreibung.',
                            ],
                            'department' => [
                                'type' => 'string',
                                'description' => 'Optional: Abteilung.',
                            ],
                            'location' => [
                                'type' => 'string',
                                'description' => 'Optional: Standort.',
                            ],
                            'is_active' => [
                                'type' => 'boolean',
                                'description' => 'Optional: Status. Default true.',
                            ],
                        ],
                        'required' => ['title'],
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
                    $title = trim((string)($item['title'] ?? ''));
                    if ($title === '') {
                        $results[] = [
                            'index' => $index,
                            'success' => false,
                            'error' => 'VALIDATION_ERROR',
                            'error_message' => 'title ist erforderlich.',
                        ];
                        $failed++;
                        continue;
                    }

                    $position = RecPosition::create([
                        'title' => $title,
                        'description' => $item['description'] ?? null,
                        'department' => $item['department'] ?? null,
                        'location' => $item['location'] ?? null,
                        'is_active' => (bool)($item['is_active'] ?? true),
                        'team_id' => $teamId,
                        'created_by_user_id' => $context->user->id,
                        'owned_by_user_id' => $context->user->id,
                    ]);

                    $results[] = [
                        'index' => $index,
                        'success' => true,
                        'id' => $position->id,
                        'uuid' => $position->uuid,
                        'title' => $position->title,
                    ];
                    $succeeded++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'index' => $index,
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
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei Bulk-Erstellung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'positions', 'bulk', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
