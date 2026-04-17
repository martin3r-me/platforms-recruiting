<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class DeletePhaseTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.phases.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/phases/{id} - Löscht eine Phase. Parameter: phase_id (required), confirm (required=true). WARNUNG: Bewerber in dieser Phase werden nicht automatisch verschoben.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'phase_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Phase (ERFORDERLICH).',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'ERFORDERLICH: Setze confirm=true um wirklich zu löschen.',
                ],
            ],
            'required' => ['phase_id', 'confirm'],
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

            if (!($arguments['confirm'] ?? false)) {
                return ToolResult::error('CONFIRMATION_REQUIRED', 'Bitte bestätige mit confirm: true.');
            }

            $found = $this->validateAndFindModel($arguments, $context, 'phase_id', RecPhase::class, 'NOT_FOUND', 'Phase nicht gefunden.');
            if ($found['error']) {
                return $found['error'];
            }
            $phase = $found['model'];

            if ((int)$phase->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf diese Phase.');
            }

            $applicantCount = $phase->applicants()->count();
            if ($applicantCount > 0) {
                return ToolResult::error('VALIDATION_ERROR', "Phase kann nicht gelöscht werden: {$applicantCount} Bewerber sind noch in dieser Phase. Verschiebe sie zuerst.");
            }

            $phaseId = $phase->id;
            $phaseName = $phase->name;
            $phase->delete();

            return ToolResult::success([
                'phase_id' => $phaseId,
                'name' => $phaseName,
                'message' => 'Phase gelöscht.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Löschen der Phase: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'phases', 'delete'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
