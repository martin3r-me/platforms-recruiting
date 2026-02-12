<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

class RecruitingLookupsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'recruiting.lookups.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/lookups - Listet alle Recruiting-Lookup-Typen (Keys) auf. Nutze danach "recruiting.lookup.GET" mit lookup=<typ> um Eintraege zu suchen (IDs nie raten).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        return ToolResult::success([
            'lookups' => [
                [
                    'key' => 'applicant_statuses',
                    'description' => 'Bewerbungsstatus (team-scoped)',
                    'tool' => 'recruiting.lookup.GET',
                ],
                [
                    'key' => 'auto_pilot_states',
                    'description' => 'AutoPilot-Zustaende fuer Bewerbungen (global/team-optional)',
                    'tool' => 'recruiting.lookup.GET',
                ],
            ],
            'how_to' => [
                'step_1' => 'Nutze recruiting.lookups.GET um den passenden lookup-key zu finden.',
                'step_2' => 'Nutze recruiting.lookup.GET mit lookup=<key> und search=<text> oder code=<code>.',
                'step_3' => 'Verwende die gefundene id in Write-Tools. Niemals raten.',
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['recruiting', 'lookup', 'help', 'overview'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
