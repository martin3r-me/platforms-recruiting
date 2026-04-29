<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecEventLocation;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdateEventLocationTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.event_locations.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/event-locations/{id} - Aktualisiert einen Veranstaltungsort. ERFORDERLICH: event_location_id.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => ['type' => 'integer'],
                'event_location_id' => ['type' => 'integer', 'description' => 'ID (ERFORDERLICH).'],
                'label' => ['type' => 'string'],
                'full_address' => ['type' => 'string'],
                'is_active' => ['type' => 'boolean'],
                'sort_order' => ['type' => 'integer'],
            ],
            'required' => ['event_location_id'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $resolved = $this->resolveTeam($arguments, $context);
        if ($resolved['error']) {
            return $resolved['error'];
        }
        $teamId = (int) $resolved['team_id'];

        $found = $this->validateAndFindModel(
            $arguments,
            $context,
            'event_location_id',
            RecEventLocation::class,
            'NOT_FOUND',
            'Veranstaltungsort nicht gefunden.'
        );
        if ($found['error']) {
            return $found['error'];
        }
        /** @var RecEventLocation $loc */
        $loc = $found['model'];

        if ((int) $loc->team_id !== $teamId) {
            return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff.');
        }

        if (array_key_exists('label', $arguments)) {
            $loc->label = mb_substr(trim((string) $arguments['label']), 0, 60);
        }
        if (array_key_exists('full_address', $arguments)) {
            $loc->full_address = mb_substr(trim((string) $arguments['full_address']), 0, 500);
        }
        if (array_key_exists('is_active', $arguments)) {
            $loc->is_active = (bool) $arguments['is_active'];
        }
        if (array_key_exists('sort_order', $arguments)) {
            $loc->sort_order = (int) $arguments['sort_order'];
        }
        $loc->save();

        return ToolResult::success([
            'id' => $loc->id,
            'label' => $loc->label,
            'full_address' => $loc->full_address,
            'is_active' => (bool) $loc->is_active,
            'sort_order' => (int) $loc->sort_order,
            'message' => 'Veranstaltungsort aktualisiert.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'event_locations', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
