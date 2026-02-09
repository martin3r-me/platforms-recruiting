<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class GetApplicantTool implements ToolContract, ToolMetadataContract
{
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicant.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/applicants/{id} - Ruft einen einzelnen Bewerber ab (inkl. Status, CRM-Verknüpfungen). Parameter: applicant_id (required), team_id (optional).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'applicant_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Bewerbers (ERFORDERLICH). Nutze "recruiting.applicants.GET" um IDs zu finden.',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'include_contacts' => [
                    'type' => 'boolean',
                    'description' => 'Optional: CRM-Kontaktdaten mitladen. Default: true.',
                    'default' => true,
                ],
            ],
            'required' => ['applicant_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            $applicantId = (int)($arguments['applicant_id'] ?? 0);
            if ($applicantId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'applicant_id ist erforderlich.');
            }

            $includeContacts = (bool)($arguments['include_contacts'] ?? true);

            $allowedTeamIds = $this->getAllowedTeamIds($teamId);

            $with = ['applicantStatus', 'autoPilotState'];
            if ($includeContacts) {
                $with['crmContactLinks'] = fn ($q) => $q->whereIn('team_id', $allowedTeamIds);
                $with[] = 'crmContactLinks.contact';
                $with[] = 'crmContactLinks.contact.emailAddresses';
                $with[] = 'crmContactLinks.contact.phoneNumbers';
                $with[] = 'crmContactLinks.contact.postalAddresses';
            }

            $applicant = RecApplicant::query()
                ->with($with)
                ->where('team_id', $teamId)
                ->find($applicantId);

            if (!$applicant) {
                return ToolResult::error('NOT_FOUND', 'Bewerber nicht gefunden (oder kein Zugriff).');
            }

            $contacts = [];
            if ($includeContacts) {
                $contacts = $applicant->crmContactLinks->map(function ($link) {
                    $c = $link->contact;
                    return [
                        'contact_id' => $c?->id,
                        'full_name' => $c?->full_name,
                        'display_name' => $c?->display_name,
                        'emails' => $c?->emailAddresses?->map(fn ($e) => [
                            'email' => $e->email_address,
                            'is_primary' => (bool)$e->is_primary,
                        ])->values()->toArray() ?? [],
                        'phones' => $c?->phoneNumbers?->map(fn ($p) => [
                            'number' => $p->international,
                            'is_primary' => (bool)$p->is_primary,
                        ])->values()->toArray() ?? [],
                    ];
                })->filter(fn ($x) => $x['contact_id'])->values()->toArray();
            }

            $extraFields = [];
            if (method_exists($applicant, 'getExtraFieldsWithLabels')) {
                try {
                    $extraFields = $applicant->getExtraFieldsWithLabels();
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            return ToolResult::success([
                'id' => $applicant->id,
                'uuid' => $applicant->uuid,
                'applicant_status' => $applicant->applicantStatus ? [
                    'id' => $applicant->applicantStatus->id,
                    'name' => $applicant->applicantStatus->name,
                ] : null,
                'progress' => $applicant->progress,
                'notes' => $applicant->notes,
                'applied_at' => $applicant->applied_at?->toDateString(),
                'is_active' => (bool)$applicant->is_active,
                'auto_pilot' => (bool)$applicant->auto_pilot,
                'auto_pilot_state' => $applicant->autoPilotState ? [
                    'id' => $applicant->autoPilotState->id,
                    'code' => $applicant->autoPilotState->code,
                    'name' => $applicant->autoPilotState->name,
                ] : null,
                'auto_pilot_completed_at' => $applicant->auto_pilot_completed_at?->toISOString(),
                'extra_fields' => $extraFields,
                'crm_contacts' => $contacts,
                'team_id' => $applicant->team_id,
                'created_at' => $applicant->created_at?->toISOString(),
                'updated_at' => $applicant->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden des Bewerbers: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'applicant', 'get'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
