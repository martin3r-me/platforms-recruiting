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

            $fields = [
                'progress',
                'notes',
                'applied_at',
                'is_active',
            ];

            foreach ($fields as $field) {
                if (array_key_exists($field, $arguments)) {
                    $applicant->{$field} = $arguments[$field] === '' ? null : $arguments[$field];
                }
            }

            // Handle auto_pilot_state_id separately — only update if valid FK
            if (array_key_exists('auto_pilot_state_id', $arguments)) {
                $stateId = $arguments['auto_pilot_state_id'];
                if (is_numeric($stateId) && (int) $stateId > 0) {
                    $stateExists = \Platform\Recruiting\Models\RecAutoPilotState::where('id', (int) $stateId)->exists();
                    if ($stateExists) {
                        $applicant->auto_pilot_state_id = (int) $stateId;
                    }
                } elseif ($stateId === null || $stateId === '' || $stateId === 0) {
                    $applicant->auto_pilot_state_id = null;
                }
            }

            // Handle rec_applicant_status_id separately - only update if valid
            if (array_key_exists('rec_applicant_status_id', $arguments)) {
                $statusId = $arguments['rec_applicant_status_id'];
                if ($statusId && $statusId > 0) {
                    // Verify status exists
                    $statusExists = \Platform\Recruiting\Models\RecApplicantStatus::where('id', $statusId)->exists();
                    if ($statusExists) {
                        $applicant->rec_applicant_status_id = $statusId;
                    }
                    // If status doesn't exist, silently ignore (don't break the update)
                }
            }

            // Handle owned_by_user_id - only update if valid, NEVER set to null (would break AutoPilot)
            if (array_key_exists('owned_by_user_id', $arguments)) {
                $ownerId = $arguments['owned_by_user_id'];
                // Only set if it's a valid positive integer - ignore 0/null/empty
                if (is_numeric($ownerId) && (int)$ownerId > 0) {
                    $applicant->owned_by_user_id = (int)$ownerId;
                }
                // Silently ignore 0/null/empty - don't clear the owner
            }

            if (array_key_exists('auto_pilot_completed_at', $arguments)) {
                $val = $arguments['auto_pilot_completed_at'];
                if ($val === 'now') {
                    $applicant->auto_pilot_completed_at = now();
                } elseif ($val === '' || $val === null) {
                    $applicant->auto_pilot_completed_at = null;
                } else {
                    $applicant->auto_pilot_completed_at = $val;
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
