<?php

namespace Platform\Recruiting\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkDeleteApplicantsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicants.bulk.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/applicants/bulk - Loescht mehrere Bewerber gleichzeitig. Parameter: ids (required, Array von Bewerber-IDs), confirm (required=true). Entfernt auch crm_contact_links. Max. 100 Eintraege.';
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
                    'description' => 'Array von Bewerber-IDs zum Loeschen (max. 100).',
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
                    $applicantId = (int)$id;
                    $applicant = RecApplicant::find($applicantId);

                    if (!$applicant) {
                        $results[] = [
                            'index' => $index,
                            'applicant_id' => $applicantId,
                            'success' => false,
                            'error' => 'NOT_FOUND',
                            'error_message' => 'Bewerber nicht gefunden.',
                        ];
                        $failed++;
                        continue;
                    }

                    if ((int)$applicant->team_id !== $teamId) {
                        $results[] = [
                            'index' => $index,
                            'applicant_id' => $applicantId,
                            'success' => false,
                            'error' => 'ACCESS_DENIED',
                            'error_message' => 'Kein Zugriff auf diesen Bewerber.',
                        ];
                        $failed++;
                        continue;
                    }

                    DB::transaction(function () use ($applicant) {
                        CrmContactLink::query()
                            ->where('linkable_type', $applicant->getMorphClass())
                            ->where('linkable_id', $applicant->id)
                            ->delete();

                        $applicant->delete();
                    });

                    $results[] = [
                        'index' => $index,
                        'applicant_id' => $applicantId,
                        'success' => true,
                    ];
                    $succeeded++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'index' => $index,
                        'applicant_id' => (int)$id,
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
            'tags' => ['recruiting', 'applicants', 'bulk', 'delete'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
