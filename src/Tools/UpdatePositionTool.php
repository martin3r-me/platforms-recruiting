<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdatePositionTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.positions.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/positions/{id} - Aktualisiert eine Position. Parameter: position_id (required).';
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
                    'description' => 'ID der Position (ERFORDERLICH). Nutze "recruiting.positions.GET".',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional: neuer Titel.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional: neue Beschreibung.',
                ],
                'department' => [
                    'type' => 'string',
                    'description' => 'Optional: neue Abteilung.',
                ],
                'location' => [
                    'type' => 'string',
                    'description' => 'Optional: neuer Standort.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status.',
                ],
                'owned_by_user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Owner der Position.',
                ],
            ],
            'required' => ['position_id'],
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

            $fields = ['title', 'description', 'department', 'location', 'is_active', 'owned_by_user_id'];
            foreach ($fields as $field) {
                if (array_key_exists($field, $arguments)) {
                    $position->{$field} = $arguments[$field] === '' ? null : $arguments[$field];
                }
            }

            $position->save();

            return ToolResult::success([
                'id' => $position->id,
                'uuid' => $position->uuid,
                'title' => $position->title,
                'department' => $position->department,
                'location' => $position->location,
                'is_active' => (bool)$position->is_active,
                'team_id' => $position->team_id,
                'message' => 'Position erfolgreich aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren der Position: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'positions', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
