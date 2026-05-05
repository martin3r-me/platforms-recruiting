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
        return 'PUT /recruiting/positions/{id} - Aktualisiert eine Position inkl. AutoPilot-Settings. Parameter: position_id (required).';
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
                'beschaftigungsort_lookup_value' => [
                    'type' => 'string',
                    'description' => 'Optional: Lookup-Wert (slug) der den Beschäftigungsort dieser Stelle markiert (z.B. "koeln", "duesseldorf"). Wird gegen den Lookup "beschaeftigungsorte" gematcht und entscheidet beim Bewerber-Wunschmapping in Phase 2 (Schulung buchen) ob ein Termin dieser Stelle dem Bewerber angezeigt wird sowie ob Position-Switch beim Booking greift. Leerer String = NICHT geaendert. Zum entfernen: explicit null.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status.',
                ],
                'owned_by_user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Owner der Position.',
                ],
                'auto_pilot_settings' => [
                    'type' => 'object',
                    'description' => 'Optional: AutoPilot-Einstellungen als JSON-Objekt. Keys: auto_pilot_enabled (bool), auto_pilot_channel_priority (string: whatsapp_first|email_first|whatsapp_only|email_only), auto_pilot_wa_account_id (int), auto_pilot_wa_initial_template_id (int), auto_pilot_wa_reminder_template_id (int), auto_pilot_reminder_interval_hours (int), auto_pilot_max_reminders (int), auto_start_auto_pilot (bool), interview_booking_wa_template_id (int). Nutze recruiting.lookup.GET mit lookup=whatsapp_templates um gültige Template-IDs zu finden.',
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

            // beschaftigungsort_lookup_value: leerer String = ignorieren
            // (sonst wuerde jeder Default-Update den Wert nullen). Explizites
            // null im Argument = entfernen.
            if (array_key_exists('beschaftigungsort_lookup_value', $arguments)) {
                $val = $arguments['beschaftigungsort_lookup_value'];
                if ($val === null) {
                    $position->beschaftigungsort_lookup_value = null;
                } elseif (is_string($val) && $val !== '') {
                    $position->beschaftigungsort_lookup_value = $val;
                }
            }

            if (array_key_exists('auto_pilot_settings', $arguments)) {
                $position->auto_pilot_settings = $arguments['auto_pilot_settings'];
            }

            $position->save();

            return ToolResult::success([
                'id' => $position->id,
                'uuid' => $position->uuid,
                'title' => $position->title,
                'department' => $position->department,
                'location' => $position->location,
                'beschaftigungsort_lookup_value' => $position->beschaftigungsort_lookup_value,
                'is_active' => (bool)$position->is_active,
                'auto_pilot_settings' => $position->auto_pilot_settings,
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
