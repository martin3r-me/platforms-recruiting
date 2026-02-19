<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkUpdatePostingsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.postings.bulk.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/postings/bulk - Aktualisiert mehrere Ausschreibungen gleichzeitig. Jeder Eintrag in items benoetigt posting_id. Max. 100 Eintraege.';
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
                    'description' => 'Array von Ausschreibungen zum Aktualisieren (max. 100).',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'posting_id' => [
                                'type' => 'integer',
                                'description' => 'ID der Ausschreibung (ERFORDERLICH).',
                            ],
                            'title' => [
                                'type' => 'string',
                                'description' => 'Optional: neuer Titel.',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Optional: neue Beschreibung.',
                            ],
                            'status' => [
                                'type' => 'string',
                                'description' => 'Optional: neuer Status (draft/published/closed).',
                            ],
                            'published_at' => [
                                'type' => 'string',
                                'description' => 'Optional: Veroeffentlichungsdatum (ISO-Datetime oder "now").',
                            ],
                            'closes_at' => [
                                'type' => 'string',
                                'description' => 'Optional: Schlussdatum (ISO-Datetime).',
                            ],
                            'is_active' => [
                                'type' => 'boolean',
                                'description' => 'Optional: Status.',
                            ],
                            'rec_position_id' => [
                                'type' => 'integer',
                                'description' => 'Optional: Position aendern.',
                            ],
                        ],
                        'required' => ['posting_id'],
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
                    $postingId = (int)($item['posting_id'] ?? 0);
                    if ($postingId <= 0) {
                        $results[] = [
                            'index' => $index,
                            'success' => false,
                            'error' => 'VALIDATION_ERROR',
                            'error_message' => 'posting_id ist erforderlich.',
                        ];
                        $failed++;
                        continue;
                    }

                    $posting = RecPosting::find($postingId);
                    if (!$posting) {
                        $results[] = [
                            'index' => $index,
                            'posting_id' => $postingId,
                            'success' => false,
                            'error' => 'NOT_FOUND',
                            'error_message' => 'Posting nicht gefunden.',
                        ];
                        $failed++;
                        continue;
                    }

                    if ((int)$posting->team_id !== $teamId) {
                        $results[] = [
                            'index' => $index,
                            'posting_id' => $postingId,
                            'success' => false,
                            'error' => 'ACCESS_DENIED',
                            'error_message' => 'Kein Zugriff auf dieses Posting.',
                        ];
                        $failed++;
                        continue;
                    }

                    $fields = ['title', 'description', 'status', 'is_active', 'rec_position_id'];
                    foreach ($fields as $field) {
                        if (array_key_exists($field, $item)) {
                            $posting->{$field} = $item[$field] === '' ? null : $item[$field];
                        }
                    }

                    if (array_key_exists('published_at', $item)) {
                        $val = $item['published_at'];
                        if ($val === 'now') {
                            $posting->published_at = now();
                        } elseif ($val === '' || $val === null) {
                            $posting->published_at = null;
                        } else {
                            $posting->published_at = $val;
                        }
                    }

                    if (array_key_exists('closes_at', $item)) {
                        $val = $item['closes_at'];
                        if ($val === '' || $val === null) {
                            $posting->closes_at = null;
                        } else {
                            $posting->closes_at = $val;
                        }
                    }

                    $posting->save();

                    $results[] = [
                        'index' => $index,
                        'posting_id' => $posting->id,
                        'success' => true,
                    ];
                    $succeeded++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'index' => $index,
                        'posting_id' => (int)($item['posting_id'] ?? 0),
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
            'tags' => ['recruiting', 'postings', 'bulk', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
