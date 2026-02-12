<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

class RecruitingOverviewTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'recruiting.overview.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/overview - Zeigt Uebersicht ueber Recruiting-Konzepte (Positionen, Ausschreibungen, Bewerber) und die Verknuepfung Richtung CRM. REST-Parameter: keine.';
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
        try {
            return ToolResult::success([
                'module' => 'recruiting',
                'scope' => [
                    'team_scoped' => true,
                    'team_id_source' => 'ToolContext.team bzw. team_id Parameter',
                ],
                'concepts' => [
                    'positions' => [
                        'model' => 'Platform\\Recruiting\\Models\\RecPosition',
                        'table' => 'rec_positions',
                        'key_fields' => ['id', 'uuid', 'title', 'department', 'location', 'is_active', 'team_id'],
                        'note' => 'Eine Position beschreibt eine Stelle (z.B. "Softwareentwickler"). Positionen koennen mehrere Ausschreibungen (Postings) haben.',
                    ],
                    'postings' => [
                        'model' => 'Platform\\Recruiting\\Models\\RecPosting',
                        'table' => 'rec_postings',
                        'key_fields' => ['id', 'uuid', 'title', 'rec_position_id', 'status', 'published_at', 'closes_at', 'is_active', 'team_id'],
                        'note' => 'Eine Ausschreibung (Posting) gehoert zu einer Position und repraesentiert eine konkrete Stellenanzeige. Bewerber bewerben sich auf Postings.',
                    ],
                    'applicants' => [
                        'model' => 'Platform\\Recruiting\\Models\\RecApplicant',
                        'table' => 'rec_applicants',
                        'key_fields' => ['id', 'uuid', 'rec_applicant_status_id', 'progress', 'applied_at', 'is_active', 'team_id'],
                        'note' => 'Ein Bewerber wird ueber crm_contact_links mit CRM Contacts verknuepft und ueber rec_applicant_posting mit Postings.',
                    ],
                ],
                'relationships' => [
                    'position_to_postings' => 'RecPosition 1:n RecPosting (rec_position_id)',
                    'posting_to_applicants' => 'RecPosting m:n RecApplicant (rec_applicant_posting Pivot mit applied_at, notes)',
                    'applicant_to_crm' => 'RecApplicant -> crm_contact_links (polymorph) -> CrmContact',
                ],
                'workflow' => [
                    'step_1' => 'Position erstellen (recruiting.positions.POST)',
                    'step_2' => 'Posting fuer Position erstellen (recruiting.postings.POST)',
                    'step_3' => 'Bewerber erstellen mit posting_id (recruiting.applicants.POST) oder nachtraeglich verknuepfen (recruiting.applicant_postings.POST)',
                    'step_4' => 'Status und Fortschritt pflegen (recruiting.applicants.PUT)',
                ],
                'auto_pilot' => [
                    'note' => 'Bewerber koennen im AutoPilot-Modus sein. Status wird ueber auto_pilot_state_id gesteuert.',
                    'lookup' => 'recruiting.lookup.GET mit lookup=auto_pilot_states',
                ],
                'related_tools' => [
                    'positions' => [
                        'list' => 'recruiting.positions.GET',
                        'get' => 'recruiting.position.GET',
                        'create' => 'recruiting.positions.POST',
                        'update' => 'recruiting.positions.PUT',
                        'delete' => 'recruiting.positions.DELETE',
                    ],
                    'postings' => [
                        'list' => 'recruiting.postings.GET',
                        'get' => 'recruiting.posting.GET',
                        'create' => 'recruiting.postings.POST',
                        'update' => 'recruiting.postings.PUT',
                        'delete' => 'recruiting.postings.DELETE',
                    ],
                    'applicants' => [
                        'list' => 'recruiting.applicants.GET',
                        'get' => 'recruiting.applicant.GET',
                        'create' => 'recruiting.applicants.POST',
                        'update' => 'recruiting.applicants.PUT',
                        'delete' => 'recruiting.applicants.DELETE',
                    ],
                    'applicant_postings' => [
                        'link' => 'recruiting.applicant_postings.POST',
                        'unlink' => 'recruiting.applicant_postings.DELETE',
                    ],
                    'applicant_contacts' => [
                        'link' => 'recruiting.applicant_contacts.POST',
                        'unlink' => 'recruiting.applicant_contacts.DELETE',
                    ],
                    'lookups' => [
                        'list' => 'recruiting.lookups.GET',
                        'get' => 'recruiting.lookup.GET',
                    ],
                    'crm' => [
                        'contacts' => 'crm.contacts.GET',
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Recruiting-Uebersicht: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'overview',
            'tags' => ['overview', 'help', 'recruiting', 'positions', 'postings', 'applicants'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
