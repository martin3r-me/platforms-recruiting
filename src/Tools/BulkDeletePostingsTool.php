<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkDeletePostingsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.postings.bulk.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/postings/bulk - Loescht mehrere Ausschreibungen gleichzeitig. Parameter: ids (required, Array von Posting-IDs), confirm (required=true). Postings mit verknuepften Bewerbern werden uebersprungen. Max. 100 Eintraege.';
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
                    'description' => 'Array von Posting-IDs zum Loeschen (max. 100).',
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
                    $postingId = (int)$id;
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

                    $applicantCount = $posting->applicants()->count();
                    if ($applicantCount > 0) {
                        $results[] = [
                            'index' => $index,
                            'posting_id' => $postingId,
                            'success' => false,
                            'error' => 'HAS_APPLICANTS',
                            'error_message' => "Posting hat {$applicantCount} verknuepfte(n) Bewerber.",
                        ];
                        $failed++;
                        continue;
                    }

                    $posting->delete();

                    $results[] = [
                        'index' => $index,
                        'posting_id' => $postingId,
                        'success' => true,
                    ];
                    $succeeded++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'index' => $index,
                        'posting_id' => (int)$id,
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
            'tags' => ['recruiting', 'postings', 'bulk', 'delete'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
