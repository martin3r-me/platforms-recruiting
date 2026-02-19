<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkCreatePostingsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.postings.bulk.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/postings/bulk - Erstellt mehrere Ausschreibungen (Postings) gleichzeitig. Jeder Eintrag in items benoetigt title und rec_position_id. Max. 100 Eintraege.';
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
                    'description' => 'Array von Ausschreibungen zum Erstellen (max. 100).',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'Titel der Ausschreibung (ERFORDERLICH).',
                            ],
                            'rec_position_id' => [
                                'type' => 'integer',
                                'description' => 'ID der Position (ERFORDERLICH).',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Optional: Beschreibung.',
                            ],
                            'status' => [
                                'type' => 'string',
                                'description' => 'Optional: Status (draft/published/closed). Default: draft.',
                            ],
                            'published_at' => [
                                'type' => 'string',
                                'description' => 'Optional: Veroeffentlichungsdatum (ISO-Datetime).',
                            ],
                            'closes_at' => [
                                'type' => 'string',
                                'description' => 'Optional: Schlussdatum (ISO-Datetime).',
                            ],
                            'is_active' => [
                                'type' => 'boolean',
                                'description' => 'Optional: Status. Default true.',
                            ],
                        ],
                        'required' => ['title', 'rec_position_id'],
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

                    $positionId = (int)($item['rec_position_id'] ?? 0);
                    if ($positionId <= 0) {
                        $results[] = [
                            'index' => $index,
                            'success' => false,
                            'error' => 'VALIDATION_ERROR',
                            'error_message' => 'rec_position_id ist erforderlich.',
                        ];
                        $failed++;
                        continue;
                    }

                    $position = RecPosition::where('team_id', $teamId)->find($positionId);
                    if (!$position) {
                        $results[] = [
                            'index' => $index,
                            'success' => false,
                            'error' => 'NOT_FOUND',
                            'error_message' => 'Position nicht gefunden (oder kein Zugriff).',
                        ];
                        $failed++;
                        continue;
                    }

                    $posting = RecPosting::create([
                        'title' => $title,
                        'rec_position_id' => $position->id,
                        'description' => $item['description'] ?? null,
                        'status' => $item['status'] ?? 'draft',
                        'published_at' => $item['published_at'] ?? null,
                        'closes_at' => $item['closes_at'] ?? null,
                        'is_active' => (bool)($item['is_active'] ?? true),
                        'team_id' => $teamId,
                        'created_by_user_id' => $context->user->id,
                    ]);

                    $results[] = [
                        'index' => $index,
                        'success' => true,
                        'id' => $posting->id,
                        'uuid' => $posting->uuid,
                        'title' => $posting->title,
                        'status' => $posting->status,
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
            'tags' => ['recruiting', 'postings', 'bulk', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
