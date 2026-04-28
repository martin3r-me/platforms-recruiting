<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class CreatePhaseTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.phases.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/phases - Erstellt eine Phase für eine Position. ERFORDERLICH: position_id, name.';
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
                'name' => [
                    'type' => 'string',
                    'description' => 'Name der Phase (ERFORDERLICH), z.B. "Bewerbung", "Schulung", "Vertrag".',
                ],
                'order' => [
                    'type' => 'integer',
                    'description' => 'Optional: Reihenfolge. Default: nächste freie Nummer.',
                ],
                'auto_advance' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Automatisch zur nächsten Phase wechseln wenn alle Extra-Felder ausgefüllt. Default: false.',
                ],
                'auto_pilot_settings' => [
                    'type' => 'object',
                    'description' => 'Optional: AutoPilot-Einstellungen als JSON-Objekt.',
                ],
                'completion_type' => [
                    'type' => 'string',
                    'enum' => ['fields', 'booking', 'manual'],
                    'description' => 'Optional: "fields" (Default) = alle Pflichtfelder, "booking" = Interview gebucht, "manual" = nur HR.',
                ],
                'completion_config' => [
                    'type' => 'object',
                    'description' => 'Optional: Phasen-Konfig. Keys: switch_position_on_booking (bool), confirm_booking_on_completion (bool).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status. Default: true.',
                ],
            ],
            'required' => ['position_id', 'name'],
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

            $positionId = (int)($arguments['position_id'] ?? 0);
            if ($positionId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'position_id ist erforderlich.');
            }

            $position = RecPosition::where('team_id', $teamId)->find($positionId);
            if (!$position) {
                return ToolResult::error('NOT_FOUND', 'Position nicht gefunden (oder kein Zugriff).');
            }

            $name = trim((string)($arguments['name'] ?? ''));
            if ($name === '') {
                return ToolResult::error('VALIDATION_ERROR', 'name ist erforderlich.');
            }

            // Auto-calculate order if not provided
            $order = $arguments['order'] ?? null;
            if ($order === null) {
                $maxOrder = RecPhase::where('rec_position_id', $positionId)->max('order') ?? 0;
                $order = $maxOrder + 1;
            }

            $phase = RecPhase::create([
                'team_id' => $teamId,
                'rec_position_id' => $positionId,
                'name' => $name,
                'order' => (int)$order,
                'auto_advance' => (bool)($arguments['auto_advance'] ?? false),
                'auto_pilot_settings' => $arguments['auto_pilot_settings'] ?? null,
                'completion_type' => $arguments['completion_type'] ?? 'fields',
                'completion_config' => $arguments['completion_config'] ?? null,
                'is_active' => (bool)($arguments['is_active'] ?? true),
            ]);

            return ToolResult::success([
                'id' => $phase->id,
                'uuid' => $phase->uuid,
                'name' => $phase->name,
                'order' => $phase->order,
                'completion_type' => $phase->completion_type,
                'completion_config' => $phase->completion_config,
                'position_id' => $positionId,
                'message' => 'Phase erfolgreich erstellt.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Erstellen der Phase: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'phases', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
