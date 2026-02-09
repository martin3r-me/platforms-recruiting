<?php

namespace Platform\Recruiting\Tools;

use Illuminate\Support\Facades\Gate;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class LinkApplicantContactTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicant_contacts.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/applicants/{applicant_id}/contacts - Verknuepft einen bestehenden CRM Contact mit einem Bewerber. Parameter: applicant_id (required), contact_id (required).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'applicant_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Bewerbers (ERFORDERLICH). Nutze "recruiting.applicants.GET".',
                ],
                'contact_id' => [
                    'type' => 'integer',
                    'description' => 'ID des CRM Contacts (ERFORDERLICH). Nutze "crm.contacts.GET".',
                ],
            ],
            'required' => ['applicant_id', 'contact_id'],
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

            $found = $this->validateAndFindModel($arguments, $context, 'applicant_id', RecApplicant::class, 'NOT_FOUND', 'Bewerber nicht gefunden.');
            if ($found['error']) {
                return $found['error'];
            }
            /** @var RecApplicant $applicant */
            $applicant = $found['model'];
            if ((int)$applicant->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Du hast keinen Zugriff auf diesen Bewerber.');
            }

            $contactId = (int)($arguments['contact_id'] ?? 0);
            if ($contactId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'contact_id ist erforderlich.');
            }

            $contact = CrmContact::find($contactId);
            if (!$contact) {
                return ToolResult::error('CONTACT_NOT_FOUND', 'CRM Contact nicht gefunden.');
            }
            Gate::forUser($context->user)->authorize('view', $contact);

            // Team-Hierarchie pruefen
            $contactTeamId = (int)$contact->team_id;
            $applicantTeamId = (int)$teamId;

            if ($contactTeamId !== $applicantTeamId) {
                $contactTeam = Team::find($contactTeamId);
                $applicantTeam = Team::find($applicantTeamId);

                if (!$contactTeam || !$applicantTeam) {
                    return ToolResult::error('VALIDATION_ERROR', "Team nicht gefunden (Contact: {$contactTeamId}, Applicant: {$applicantTeamId}).");
                }

                if (!$applicantTeam->isChildOf($contactTeam)) {
                    return ToolResult::error('VALIDATION_ERROR', "CRM Contact gehoert nicht zum Team {$teamId} oder einem Elternteam davon.");
                }
            }

            $link = CrmContactLink::firstOrCreate(
                [
                    'contact_id' => $contact->id,
                    'linkable_type' => RecApplicant::class,
                    'linkable_id' => $applicant->id,
                ],
                [
                    'team_id' => $teamId,
                    'created_by_user_id' => $context->user->id,
                ]
            );

            return ToolResult::success([
                'applicant_id' => $applicant->id,
                'contact_id' => $contact->id,
                'contact_name' => $contact->full_name,
                'already_linked' => !$link->wasRecentlyCreated,
                'message' => 'CRM Contact mit Bewerber verknuepft.',
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf den CRM Contact.');
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Verknuepfen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'applicant', 'crm', 'link', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
