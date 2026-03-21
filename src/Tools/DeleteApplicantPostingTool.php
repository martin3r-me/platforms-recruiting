<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class DeleteApplicantPostingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicant_postings.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/applicants/{applicant_id}/postings/{posting_id} - Entfernt die Verknuepfung zwischen Bewerber und Posting. Mindestens 1 Posting muss verknuepft bleiben. Parameter: posting_id (required), applicant_id (optional, default: Kontext-Bewerber).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'posting_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Ausschreibung die entfernt werden soll (ERFORDERLICH).',
                ],
                'applicant_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID des Bewerbers. Default: Bewerber aus Kontext.',
                ],
            ],
            'required' => ['posting_id'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];

            // Resolve applicant: explicit argument or from context
            $applicantId = (int) ($arguments['applicant_id'] ?? $context->getMeta('context_model_id') ?? 0);
            if ($applicantId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'applicant_id ist erforderlich (kein Kontext-Bewerber gefunden).');
            }

            $applicant = RecApplicant::find($applicantId);
            if (!$applicant) {
                return ToolResult::error('NOT_FOUND', 'Bewerber nicht gefunden.');
            }
            if ((int) $applicant->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Du hast keinen Zugriff auf diesen Bewerber.');
            }

            $postingId = (int) ($arguments['posting_id'] ?? 0);
            if ($postingId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'posting_id ist erforderlich.');
            }

            // Safety: at least 1 posting must remain
            $currentCount = $applicant->postings()->count();
            if ($currentCount <= 1) {
                return ToolResult::error('VALIDATION_ERROR', 'Mindestens 1 Posting muss verknuepft bleiben. Entfernung nicht moeglich.');
            }

            // Check the posting is actually linked
            $isLinked = $applicant->postings()->where('rec_postings.id', $postingId)->exists();
            if (!$isLinked) {
                return ToolResult::success([
                    'applicant_id' => $applicant->id,
                    'posting_id' => $postingId,
                    'removed' => false,
                    'message' => 'Verknuepfung existiert nicht (nichts zu tun).',
                ]);
            }

            $applicant->postings()->detach($postingId);

            return ToolResult::success([
                'applicant_id' => $applicant->id,
                'posting_id' => $postingId,
                'removed' => true,
                'remaining_postings' => $currentCount - 1,
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
