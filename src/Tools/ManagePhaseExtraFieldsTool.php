<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ManagePhaseExtraFieldsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.phase_extra_fields.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/phases/{id}/extra-fields - Erstellt oder listet Extra-Feld-Definitionen für eine Phase. '
            . 'Aktion "list" zeigt alle Felder, "create" erstellt ein neues Feld, "update" aktualisiert ein Feld, "delete" löscht ein Feld. '
            . 'Verfügbare Typen: ' . implode(', ', array_keys(CoreExtraFieldDefinition::TYPES)) . '. '
            . 'Diese Felder werden Bewerbern in der jeweiligen Phase angezeigt und vom AutoPilot abgefragt.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID.',
                ],
                'phase_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Phase (ERFORDERLICH).',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'create', 'update', 'delete'],
                    'description' => 'Aktion: "list" (Standard), "create", "update" oder "delete".',
                ],
                'field_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Extra-Feldes (ERFORDERLICH für update/delete).',
                ],
                'label' => [
                    'type' => 'string',
                    'description' => 'Anzeigename des Feldes (ERFORDERLICH für create).',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Optional: Technischer Name (wird aus label generiert wenn leer).',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Feldtyp (ERFORDERLICH für create): ' . implode(', ', array_keys(CoreExtraFieldDefinition::TYPES)),
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional: Beschreibung/Hilfetext.',
                ],
                'is_required' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Pflichtfeld? Default: false.',
                ],
                'is_mandatory' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Zwingend (kann nicht übersprungen werden)? Default: false.',
                ],
                'order' => [
                    'type' => 'integer',
                    'description' => 'Optional: Reihenfolge.',
                ],
                'options' => [
                    'type' => 'object',
                    'description' => 'Optional: Typ-spezifische Optionen. Select: {"choices": ["A","B"]}. Lookup: {"lookup_id": 123}. Regex: {"pattern": "..."}. Date: {"year_range": 50}.',
                ],
                'verify_by_llm' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Soll der Wert durch KI verifiziert werden?',
                ],
                'verify_instructions' => [
                    'type' => 'string',
                    'description' => 'Optional: Anweisungen für die KI-Verifizierung.',
                ],
                'auto_fill_source' => [
                    'type' => 'string',
                    'description' => 'Optional: Auto-Fill-Quelle: "llm" oder "websearch".',
                ],
                'auto_fill_prompt' => [
                    'type' => 'string',
                    'description' => 'Optional: Prompt für Auto-Fill.',
                ],
                'visibility_config' => [
                    'type' => 'object',
                    'description' => 'Optional: Bedingte Sichtbarkeit. Format: {"enabled": true, "logic": "and", "conditions": [{"field": "name", "operator": "eq", "value": "..."}]}.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'ERFORDERLICH für delete: Setze confirm=true.',
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

            $phaseId = (int)($arguments['phase_id'] ?? 0);
            if ($phaseId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'phase_id ist erforderlich.');
            }

            $phase = RecPhase::where('team_id', $teamId)->find($phaseId);
            if (!$phase) {
                return ToolResult::error('NOT_FOUND', 'Phase nicht gefunden (oder kein Zugriff).');
            }

            $action = $arguments['action'] ?? 'list';

            return match ($action) {
                'list' => $this->listFields($phase),
                'create' => $this->createField($phase, $arguments, $context),
                'update' => $this->updateField($phase, $arguments, $teamId),
                'delete' => $this->deleteField($phase, $arguments, $teamId),
                default => ToolResult::error('VALIDATION_ERROR', 'Ungültige Aktion. Erlaubt: list, create, update, delete.'),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    private function listFields(RecPhase $phase): ToolResult
    {
        $definitions = $phase->getExtraFieldDefinitions();

        $data = $definitions->map(fn(CoreExtraFieldDefinition $def) => [
            'id' => $def->id,
            'name' => $def->name,
            'label' => $def->label,
            'description' => $def->description,
            'type' => $def->type,
            'is_required' => (bool)$def->is_required,
            'is_mandatory' => (bool)$def->is_mandatory,
            'is_encrypted' => (bool)$def->is_encrypted,
            'order' => $def->order,
            'options' => $def->options,
            'verify_by_llm' => (bool)$def->verify_by_llm,
            'verify_instructions' => $def->verify_instructions,
            'auto_fill_source' => $def->auto_fill_source,
            'auto_fill_prompt' => $def->auto_fill_prompt,
            'visibility_config' => $def->visibility_config,
            'is_inherited' => $def->context_type !== RecPhase::class,
        ])->values()->toArray();

        return ToolResult::success([
            'phase_id' => $phase->id,
            'phase_name' => $phase->name,
            'fields' => $data,
            'count' => count($data),
        ]);
    }

    private function createField(RecPhase $phase, array $arguments, ToolContext $context): ToolResult
    {
        $label = trim((string)($arguments['label'] ?? ''));
        if ($label === '') {
            return ToolResult::error('VALIDATION_ERROR', 'label ist erforderlich für create.');
        }

        $type = $arguments['type'] ?? '';
        if (!array_key_exists($type, CoreExtraFieldDefinition::TYPES)) {
            return ToolResult::error('VALIDATION_ERROR', 'Ungültiger Typ. Erlaubt: ' . implode(', ', array_keys(CoreExtraFieldDefinition::TYPES)));
        }

        // Generate name from label if not provided
        $name = $arguments['name'] ?? null;
        if (!$name) {
            $name = \Illuminate\Support\Str::slug($label, '_');
        }

        // Check uniqueness
        $exists = CoreExtraFieldDefinition::query()
            ->where('team_id', $phase->team_id)
            ->where('context_type', RecPhase::class)
            ->where('context_id', $phase->id)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            return ToolResult::error('VALIDATION_ERROR', "Ein Feld mit dem Namen '{$name}' existiert bereits in dieser Phase.");
        }

        // Validate type-specific options
        $options = $arguments['options'] ?? null;
        if ($type === 'select' && (!$options || empty($options['choices']))) {
            return ToolResult::error('VALIDATION_ERROR', 'Typ "select" benötigt options.choices Array.');
        }
        if ($type === 'lookup' && (!$options || empty($options['lookup_id']))) {
            return ToolResult::error('VALIDATION_ERROR', 'Typ "lookup" benötigt options.lookup_id.');
        }
        if ($type === 'regex' && (!$options || empty($options['pattern']))) {
            return ToolResult::error('VALIDATION_ERROR', 'Typ "regex" benötigt options.pattern.');
        }

        $maxOrder = CoreExtraFieldDefinition::query()
            ->where('team_id', $phase->team_id)
            ->where('context_type', RecPhase::class)
            ->where('context_id', $phase->id)
            ->max('order') ?? 0;

        $definition = CoreExtraFieldDefinition::create([
            'team_id' => $phase->team_id,
            'created_by_user_id' => $context->user->id,
            'context_type' => RecPhase::class,
            'context_id' => $phase->id,
            'name' => $name,
            'label' => $label,
            'description' => $arguments['description'] ?? null,
            'type' => $type,
            'is_required' => (bool)($arguments['is_required'] ?? false),
            'is_mandatory' => (bool)($arguments['is_mandatory'] ?? false),
            'order' => (int)($arguments['order'] ?? ($maxOrder + 1)),
            'options' => $options,
            'verify_by_llm' => (bool)($arguments['verify_by_llm'] ?? false),
            'verify_instructions' => $arguments['verify_instructions'] ?? null,
            'auto_fill_source' => $arguments['auto_fill_source'] ?? null,
            'auto_fill_prompt' => $arguments['auto_fill_prompt'] ?? null,
            'visibility_config' => $arguments['visibility_config'] ?? null,
        ]);

        $phase->clearExtraFieldDefinitionsCache();

        return ToolResult::success([
            'id' => $definition->id,
            'name' => $definition->name,
            'label' => $definition->label,
            'type' => $definition->type,
            'phase_id' => $phase->id,
            'message' => "Extra-Feld '{$label}' erfolgreich erstellt.",
        ]);
    }

    private function updateField(RecPhase $phase, array $arguments, int $teamId): ToolResult
    {
        $fieldId = (int)($arguments['field_id'] ?? 0);
        if ($fieldId <= 0) {
            return ToolResult::error('VALIDATION_ERROR', 'field_id ist erforderlich für update.');
        }

        $definition = CoreExtraFieldDefinition::query()
            ->where('team_id', $teamId)
            ->where('context_type', RecPhase::class)
            ->where('context_id', $phase->id)
            ->find($fieldId);

        if (!$definition) {
            return ToolResult::error('NOT_FOUND', 'Extra-Feld nicht gefunden in dieser Phase.');
        }

        $fields = ['label', 'description', 'is_required', 'is_mandatory', 'order',
                    'verify_by_llm', 'verify_instructions', 'auto_fill_source', 'auto_fill_prompt'];
        foreach ($fields as $field) {
            if (array_key_exists($field, $arguments)) {
                $definition->{$field} = $arguments[$field] === '' ? null : $arguments[$field];
            }
        }

        if (array_key_exists('options', $arguments)) {
            $definition->options = $arguments['options'];
        }
        if (array_key_exists('visibility_config', $arguments)) {
            $definition->visibility_config = $arguments['visibility_config'];
        }

        $definition->save();
        $phase->clearExtraFieldDefinitionsCache();

        return ToolResult::success([
            'id' => $definition->id,
            'name' => $definition->name,
            'label' => $definition->label,
            'message' => "Extra-Feld '{$definition->label}' aktualisiert.",
        ]);
    }

    private function deleteField(RecPhase $phase, array $arguments, int $teamId): ToolResult
    {
        if (!($arguments['confirm'] ?? false)) {
            return ToolResult::error('CONFIRMATION_REQUIRED', 'Bitte bestätige mit confirm: true.');
        }

        $fieldId = (int)($arguments['field_id'] ?? 0);
        if ($fieldId <= 0) {
            return ToolResult::error('VALIDATION_ERROR', 'field_id ist erforderlich für delete.');
        }

        $definition = CoreExtraFieldDefinition::query()
            ->where('team_id', $teamId)
            ->where('context_type', RecPhase::class)
            ->where('context_id', $phase->id)
            ->find($fieldId);

        if (!$definition) {
            return ToolResult::error('NOT_FOUND', 'Extra-Feld nicht gefunden in dieser Phase.');
        }

        $label = $definition->label;

        // Delete associated values
        $definition->values()->delete();
        $definition->delete();

        $phase->clearExtraFieldDefinitionsCache();

        return ToolResult::success([
            'field_id' => $fieldId,
            'label' => $label,
            'message' => "Extra-Feld '{$label}' und alle Werte gelöscht.",
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'phases', 'extra_fields', 'manage'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
