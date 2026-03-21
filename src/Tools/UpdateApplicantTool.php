<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdateApplicantTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicants.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/applicants/{id} - Aktualisiert einen Bewerber. Parameter: applicant_id (required). Hinweis: CRM-Contact-Link wird ueber recruiting.applicant_contacts.* Tools verwaltet.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'applicant_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Bewerbers (ERFORDERLICH). Nutze "recruiting.applicants.GET".',
                ],
                'rec_applicant_status_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: neuer Bewerbungsstatus. Nutze "recruiting.lookup.GET" mit lookup=applicant_statuses.',
                ],
                'progress' => [
                    'type' => 'integer',
                    'description' => 'Optional: Fortschritt (0-100).',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Optional: Notizen zur Bewerbung.',
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
                    'description' => 'Optional: Owner des Bewerber-Datensatzes.',
                ],
                'auto_pilot_state_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: AutoPilot-State-ID. Nutze "recruiting.lookup.GET" mit lookup=auto_pilot_states.',
                ],
                'auto_pilot_completed_at' => [
                    'type' => 'string',
                    'description' => 'Optional: ISO-Datetime oder "now" um auto_pilot_completed_at zu setzen.',
                ],
            ],
            'required' => ['applicant_id'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            $found = $this->validateAndFindModel(
                $arguments,
                $context,
                'applicant_id',
                RecApplicant::class,
                'NOT_FOUND',
                'Bewerber nicht gefunden.'
            );
            if ($found['error']) {
                return $found['error'];
            }
            /** @var RecApplicant $applicant */
            $applicant = $found['model'];

            if ((int)$applicant->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Du hast keinen Zugriff auf diesen Bewerber.');
            }

            // Simple text fields
            if (array_key_exists('notes', $arguments)) {
                $applicant->notes = $arguments['notes'] === '' ? null : $arguments['notes'];
            }

            if (array_key_exists('is_active', $arguments)) {
                $applicant->is_active = (bool) $arguments['is_active'];
            }

            // Progress: clamp to 0-100
            if (array_key_exists('progress', $arguments)) {
                $val = $arguments['progress'];
                if (is_numeric($val)) {
                    $applicant->progress = max(0, min(100, (int) $val));
                }
            }

            // Date fields: validate format, empty/null → null
            if (array_key_exists('applied_at', $arguments)) {
                $val = trim((string) ($arguments['applied_at'] ?? ''));
                if ($val === '') {
                    $applicant->applied_at = null;
                } else {
                    try {
                        $applicant->applied_at = \Carbon\Carbon::parse($val)->toDateString();
                    } catch (\Throwable $e) {
                        // Invalid date format — ignore silently
                    }
                }
            }

            if (array_key_exists('auto_pilot_completed_at', $arguments)) {
                $val = trim((string) ($arguments['auto_pilot_completed_at'] ?? ''));
                if ($val === 'now') {
                    $applicant->auto_pilot_completed_at = now();
                } elseif ($val === '') {
                    $applicant->auto_pilot_completed_at = null;
                } else {
                    try {
                        $applicant->auto_pilot_completed_at = \Carbon\Carbon::parse($val);
                    } catch (\Throwable $e) {
                        // Invalid datetime — ignore silently
                    }
                }
            }

            // FK fields: validate existence before setting, 0/null/empty → null
            $fkFields = [
                'auto_pilot_state_id' => \Platform\Recruiting\Models\RecAutoPilotState::class,
                'rec_applicant_status_id' => \Platform\Recruiting\Models\RecApplicantStatus::class,
                'owned_by_user_id' => \Platform\Core\Models\User::class,
            ];

            foreach ($fkFields as $field => $modelClass) {
                if (!array_key_exists($field, $arguments)) {
                    continue;
                }
                $val = $arguments[$field];
                if (is_numeric($val) && (int) $val > 0) {
                    if ($modelClass::where('id', (int) $val)->exists()) {
                        $applicant->{$field} = (int) $val;
                    }
                    // Invalid FK — ignore silently, don't break the update
                } elseif ($field !== 'owned_by_user_id') {
                    // Allow nulling FK fields except owned_by_user_id (would break AutoPilot)
                    $applicant->{$field} = null;
                }
            }

            $applicant->save();

            return ToolResult::success([
                'id' => $applicant->id,
                'uuid' => $applicant->uuid,
                'rec_applicant_status_id' => $applicant->rec_applicant_status_id,
                'progress' => $applicant->progress,
                'team_id' => $applicant->team_id,
                'is_active' => (bool)$applicant->is_active,
                'auto_pilot' => (bool)$applicant->auto_pilot,
                'auto_pilot_state_id' => $applicant->auto_pilot_state_id,
                'auto_pilot_completed_at' => $applicant->auto_pilot_completed_at?->toISOString(),
                'message' => 'Bewerber erfolgreich aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren des Bewerbers: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'applicants', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
