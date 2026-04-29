<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Recruiting\Models\RecEventLocation;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListEventLocationsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.event_locations.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/event-locations - Listet vordefinierte Veranstaltungsorte (Schulungen, Vorstellungsgespräche etc.). Pro Eintrag label (kurz, z.B. Bonn) und full_address (volle Adresse, wird in Templates verwendet).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: nur aktive/inaktive Locations.',
                ],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $resolved = $this->resolveTeam($arguments, $context);
        if ($resolved['error']) {
            return $resolved['error'];
        }
        $teamId = (int) $resolved['team_id'];

        $query = RecEventLocation::where('team_id', $teamId);
        if (isset($arguments['is_active'])) {
            $query->where('is_active', (bool) $arguments['is_active']);
        }

        $rows = $query->orderBy('sort_order')->orderBy('label')->get()->map(fn (RecEventLocation $r) => [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'label' => $r->label,
            'full_address' => $r->full_address,
            'is_active' => (bool) $r->is_active,
            'sort_order' => (int) $r->sort_order,
        ]);

        return ToolResult::success([
            'data' => $rows->values()->toArray(),
            'team_id' => $teamId,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'event_locations', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
