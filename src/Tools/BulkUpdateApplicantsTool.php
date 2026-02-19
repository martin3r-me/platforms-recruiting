<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkUpdateApplicantsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicants.bulk.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/applicants/bulk - Aktualisiert mehrere Bewerber gleichzeitig. Jeder Eintrag in items benoetigt applicant_id. Max. 100 Eintraege.';
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
                    'description' => 'Array von Bewerbern zum Aktualisieren (max. 100).',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'applicant_id' => [
                                'type' => 'integer',
                                'description' => 'ID des Bewerbers (ERFORDERLICH).',
                            ],
                            'rec_applicant_status_id' => [
                                'type' => 'integer',
                                'description' => 'Optional: neuer Bewerbungsstatus.',
                            ],
                            'progress' => [
                                'type' => 'integer',
                                'description' => 'Optional: Fortschritt (0-100).',
                            ],
                            'notes' => [
                                'type' => 'string',
                                'description' => 'Optional: Notizen.',
                            ],
                            'applied_at' => [
                                'type' => 'string',
                                'description' => 'Optional: Bewerbungsdatum (YYYY-MM-DD).',
                            ],
                            'is_active' => [
                                'type' => 'boolean',
                                'description' => 'Optional: Status.',
                            ],
                            'owned_by_user_id' => [
                                'type' => 'integer',
                                'description' => 'Optional: Owner.',
                            ],
                            'auto_pilot_state_id' => [
                                'type' => 'integer',
                                'description' => 'Optional: AutoPilot-State-ID.',
                            ],
                            'auto_pilot_completed_at' => [
                                'type' => 'string',
                                'description' => 'Optional: ISO-Datetime oder "now".',
                            ],
                        ],
                        'required' => ['applicant_id'],
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
                    $applicantId = (int)($item['applicant_id'] ?? 0);
                    if ($applicantId <= 0) {
                        $results[] = [
                            'index' => $index,
                            'success' => false,
                            'error' => 'VALIDATION_ERROR',
                            'error_message' => 'applicant_id ist erforderlich.',
                        ];
                        $failed++;
                        continue;
                    }

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

                    $fields = [
                        'rec_applicant_status_id',
                        'progress',
                        'notes',
                        'applied_at',
                        'is_active',
                        'owned_by_user_id',
                        'auto_pilot_state_id',
                    ];

                    foreach ($fields as $field) {
                        if (array_key_exists($field, $item)) {
                            $applicant->{$field} = $item[$field] === '' ? null : $item[$field];
                        }
                    }

                    if (array_key_exists('auto_pilot_completed_at', $item)) {
                        $val = $item['auto_pilot_completed_at'];
                        if ($val === 'now') {
                            $applicant->auto_pilot_completed_at = now();
                        } elseif ($val === '' || $val === null) {
                            $applicant->auto_pilot_completed_at = null;
                        } else {
                            $applicant->auto_pilot_completed_at = $val;
                        }
                    }

                    $applicant->save();

                    $results[] = [
                        'index' => $index,
                        'applicant_id' => $applicant->id,
                        'success' => true,
                    ];
                    $succeeded++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'index' => $index,
                        'applicant_id' => (int)($item['applicant_id'] ?? 0),
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
            'tags' => ['recruiting', 'applicants', 'bulk', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
