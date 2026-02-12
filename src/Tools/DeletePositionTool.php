<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class DeletePositionTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.positions.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/positions/{id} - Loescht eine Position. Parameter: position_id (required), confirm (required=true). Warnt wenn aktive Postings existieren.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'position_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Position (ERFORDERLICH).',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'ERFORDERLICH: Setze confirm=true um wirklich zu loeschen.',
                ],
            ],
            'required' => ['position_id', 'confirm'],
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
                return ToolResult::error('CONFIRMATION_REQUIRED', 'Bitte bestaetige mit confirm: true.');
            }

            $found = $this->validateAndFindModel(
                $arguments, $context, 'position_id', RecPosition::class, 'NOT_FOUND', 'Position nicht gefunden.'
            );
            if ($found['error']) {
                return $found['error'];
            }
            /** @var RecPosition $position */
            $position = $found['model'];

            if ((int)$position->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Du hast keinen Zugriff auf diese Position.');
            }

            $activePostings = $position->activePostings()->count();
            if ($activePostings > 0) {
                return ToolResult::error('HAS_ACTIVE_POSTINGS', "Diese Position hat {$activePostings} aktive Ausschreibung(en). Deaktiviere oder loesche diese zuerst.");
            }

            $positionId = (int)$position->id;
            $position->delete();

            return ToolResult::success([
                'position_id' => $positionId,
                'message' => 'Position geloescht.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Loeschen der Position: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'positions', 'delete'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
