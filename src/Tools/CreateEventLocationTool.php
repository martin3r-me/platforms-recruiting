<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecEventLocation;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class CreateEventLocationTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.event_locations.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/event-locations - Legt einen Veranstaltungsort an. ERFORDERLICH: label (kurz, z.B. Bonn), full_address (vollständige Adresse).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => ['type' => 'integer'],
                'label' => [
                    'type' => 'string',
                    'description' => 'Kurzbezeichnung (max 60 Zeichen), z.B. "Bonn".',
                ],
                'full_address' => [
                    'type' => 'string',
                    'description' => 'Volle Adresse (max 500 Zeichen) — wird beim Versand des Reminder-Templates als interview_location interpoliert.',
                ],
                'is_active' => ['type' => 'boolean', 'description' => 'Optional, default true.'],
                'sort_order' => ['type' => 'integer', 'description' => 'Optional, kleinere Werte stehen im Dropdown oben.'],
            ],
            'required' => ['label', 'full_address'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $resolved = $this->resolveTeam($arguments, $context);
        if ($resolved['error']) {
            return $resolved['error'];
        }
        $teamId = (int) $resolved['team_id'];

        $label = trim((string) ($arguments['label'] ?? ''));
        $fullAddress = trim((string) ($arguments['full_address'] ?? ''));

        if ($label === '' || $fullAddress === '') {
            return ToolResult::error('VALIDATION_ERROR', 'label und full_address sind erforderlich.');
        }

        $loc = RecEventLocation::create([
            'team_id' => $teamId,
            'label' => mb_substr($label, 0, 60),
            'full_address' => mb_substr($fullAddress, 0, 500),
            'is_active' => (bool) ($arguments['is_active'] ?? true),
            'sort_order' => (int) ($arguments['sort_order'] ?? 100),
        ]);

        return ToolResult::success([
            'id' => $loc->id,
            'uuid' => $loc->uuid,
            'label' => $loc->label,
            'full_address' => $loc->full_address,
            'message' => 'Veranstaltungsort erstellt.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'event_locations', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
