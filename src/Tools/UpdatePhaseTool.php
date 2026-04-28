<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdatePhaseTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.phases.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/phases/{id} - Aktualisiert eine Phase. Parameter: phase_id (required).';
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
                'name' => [
                    'type' => 'string',
                    'description' => 'Optional: Name der Phase.',
                ],
                'order' => [
                    'type' => 'integer',
                    'description' => 'Optional: Reihenfolge.',
                ],
                'auto_advance' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Automatisch zur nächsten Phase wechseln.',
                ],
                'auto_pilot_settings' => [
                    'type' => 'object',
                    'description' => 'Optional: AutoPilot-Einstellungen als JSON-Objekt. Keys: auto_pilot_wa_initial_template_id (int), auto_pilot_wa_reminder_template_id (int). Nutze recruiting.lookup.GET mit lookup=whatsapp_templates um gültige Template-IDs zu finden.',
                ],
                'completion_type' => [
                    'type' => 'string',
                    'enum' => ['fields', 'booking', 'manual'],
                    'description' => 'Optional: Wann gilt die Phase als abgeschlossen? "fields" = alle Pflichtfelder ausgefüllt (Default), "booking" = Bewerber hat Interview gebucht, "manual" = nur durch HR/Admin.',
                ],
                'completion_config' => [
                    'type' => 'object',
                    'description' => 'Optional: Phasen-spezifische Konfiguration. Bekannte Keys: switch_position_on_booking (bool, nur sinnvoll mit completion_type=booking), confirm_booking_on_completion (bool, registriertes Interview wird bei Phasen-Abschluss automatisch auf "confirmed" gesetzt).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status.',
                ],
            ],
            'required' => ['phase_id'],
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

            $found = $this->validateAndFindModel($arguments, $context, 'phase_id', RecPhase::class, 'NOT_FOUND', 'Phase nicht gefunden.');
            if ($found['error']) {
                return $found['error'];
            }
            $phase = $found['model'];

            if ((int)$phase->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf diese Phase.');
            }

            $fields = ['name', 'order', 'auto_advance', 'is_active'];
            foreach ($fields as $field) {
                if (array_key_exists($field, $arguments)) {
                    $phase->{$field} = $arguments[$field] === '' ? null : $arguments[$field];
                }
            }

            if (array_key_exists('auto_pilot_settings', $arguments)) {
                $phase->auto_pilot_settings = $arguments['auto_pilot_settings'];
            }

            if (array_key_exists('completion_type', $arguments)) {
                $value = $arguments['completion_type'];
                $phase->completion_type = ($value === '' || $value === null) ? 'fields' : $value;
            }

            if (array_key_exists('completion_config', $arguments)) {
                $phase->completion_config = $arguments['completion_config'];
            }

            $phase->save();

            return ToolResult::success([
                'id' => $phase->id,
                'uuid' => $phase->uuid,
                'name' => $phase->name,
                'order' => $phase->order,
                'auto_pilot_settings' => $phase->auto_pilot_settings,
                'completion_type' => $phase->completion_type,
                'completion_config' => $phase->completion_config,
                'message' => 'Phase erfolgreich aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren der Phase: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'phases', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
