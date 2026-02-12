<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class CreatePositionTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.positions.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/positions - Erstellt eine neue Position (Stelle). Parameter: title (required).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Titel der Position (ERFORDERLICH).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional: Beschreibung der Position.',
                ],
                'department' => [
                    'type' => 'string',
                    'description' => 'Optional: Abteilung.',
                ],
                'location' => [
                    'type' => 'string',
                    'description' => 'Optional: Standort.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status. Default true.',
                    'default' => true,
                ],
            ],
            'required' => ['title'],
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

            $title = trim((string)($arguments['title'] ?? ''));
            if ($title === '') {
                return ToolResult::error('VALIDATION_ERROR', 'title ist erforderlich.');
            }

            $position = RecPosition::create([
                'title' => $title,
                'description' => $arguments['description'] ?? null,
                'department' => $arguments['department'] ?? null,
                'location' => $arguments['location'] ?? null,
                'is_active' => (bool)($arguments['is_active'] ?? true),
                'team_id' => $teamId,
                'created_by_user_id' => $context->user->id,
                'owned_by_user_id' => $context->user->id,
            ]);

            return ToolResult::success([
                'id' => $position->id,
                'uuid' => $position->uuid,
                'title' => $position->title,
                'department' => $position->department,
                'location' => $position->location,
                'is_active' => (bool)$position->is_active,
                'team_id' => $position->team_id,
                'message' => 'Position erfolgreich erstellt.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Erstellen der Position: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'positions', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
