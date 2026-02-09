<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UnlinkApplicantContactTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicant_contacts.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/applicants/{applicant_id}/contacts/{contact_id} - Entfernt die Verknuepfung zwischen Bewerber und CRM Contact. Parameter: applicant_id (required), contact_id (required), confirm (optional).';
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
                    'description' => 'ID des Bewerbers (ERFORDERLICH).',
                ],
                'contact_id' => [
                    'type' => 'integer',
                    'description' => 'ID des CRM Contacts (ERFORDERLICH).',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Bestätigung. Wenn dadurch kein Contact mehr uebrig bleibt, solltest du confirm=true setzen.',
                ],
            ],
            'required' => ['applicant_id', 'contact_id'],
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

            $linksQuery = CrmContactLink::query()
                ->where('linkable_type', RecApplicant::class)
                ->where('linkable_id', $applicant->id)
                ->where('contact_id', $contactId);

            $existing = $linksQuery->first();
            if (!$existing) {
                return ToolResult::success([
                    'applicant_id' => $applicant->id,
                    'contact_id' => $contactId,
                    'removed' => false,
                    'message' => 'Verknuepfung existiert nicht (nichts zu tun).',
                ]);
            }

            // Warnung wenn das die letzte Contact-Verknuepfung waere
            $remainingCount = CrmContactLink::query()
                ->where('linkable_type', RecApplicant::class)
                ->where('linkable_id', $applicant->id)
                ->count();

            if ($remainingCount <= 1 && !($arguments['confirm'] ?? false)) {
                return ToolResult::error('CONFIRMATION_REQUIRED', 'Das ist die letzte CRM-Contact-Verknuepfung dieses Bewerbers. Bitte bestaetige mit confirm: true.');
            }

            $existing->delete();

            return ToolResult::success([
                'applicant_id' => $applicant->id,
                'contact_id' => $contactId,
                'removed' => true,
                'message' => 'Verknuepfung entfernt.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Entfernen der Verknuepfung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'applicant', 'crm', 'link', 'delete'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
