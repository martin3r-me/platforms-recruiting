<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UnlinkApplicantPostingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicant_postings.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/applicants/{applicant_id}/postings/{posting_id} - Entfernt die Verknuepfung zwischen Bewerber und Posting. Parameter: applicant_id (required), posting_id (required).';
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
                'posting_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Ausschreibung (ERFORDERLICH).',
                ],
            ],
            'required' => ['applicant_id', 'posting_id'],
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

            $postingId = (int)($arguments['posting_id'] ?? 0);
            if ($postingId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'posting_id ist erforderlich.');
            }

            $detached = $applicant->postings()->detach($postingId);

            if ($detached === 0) {
                return ToolResult::success([
                    'applicant_id' => $applicant->id,
                    'posting_id' => $postingId,
                    'removed' => false,
                    'message' => 'Verknuepfung existiert nicht (nichts zu tun).',
                ]);
            }

            return ToolResult::success([
                'applicant_id' => $applicant->id,
                'posting_id' => $postingId,
                'removed' => true,
                'message' => 'Posting-Verknuepfung entfernt.',
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
            'tags' => ['recruiting', 'applicant', 'posting', 'link', 'delete'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
