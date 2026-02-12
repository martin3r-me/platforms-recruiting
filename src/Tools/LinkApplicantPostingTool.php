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

class LinkApplicantPostingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicant_postings.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/applicants/{applicant_id}/postings - Verknuepft einen Bewerber mit einer Ausschreibung (Posting). Parameter: applicant_id (required), posting_id (required), applied_at (optional).';
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
                'posting_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Ausschreibung (ERFORDERLICH). Nutze "recruiting.postings.GET".',
                ],
                'applied_at' => [
                    'type' => 'string',
                    'description' => 'Optional: Bewerbungsdatum (YYYY-MM-DD). Default: heute.',
                ],
            ],
            'required' => ['applicant_id', 'posting_id'],
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

            $postingId = (int)($arguments['posting_id'] ?? 0);
            if ($postingId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'posting_id ist erforderlich.');
            }

            $posting = RecPosting::where('team_id', $teamId)->find($postingId);
            if (!$posting) {
                return ToolResult::error('NOT_FOUND', 'Posting nicht gefunden (oder kein Zugriff).');
            }

            // Duplikat-Check
            if ($applicant->postings()->where('rec_postings.id', $posting->id)->exists()) {
                return ToolResult::success([
                    'applicant_id' => $applicant->id,
                    'posting_id' => $posting->id,
                    'posting_title' => $posting->title,
                    'already_linked' => true,
                    'message' => 'Bewerber ist bereits mit diesem Posting verknuepft.',
                ]);
            }

            $applicant->postings()->attach($posting->id, [
                'applied_at' => $arguments['applied_at'] ?? now()->toDateString(),
            ]);

            return ToolResult::success([
                'applicant_id' => $applicant->id,
                'posting_id' => $posting->id,
                'posting_title' => $posting->title,
                'already_linked' => false,
                'message' => 'Bewerber mit Posting verknuepft.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Verknuepfen: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'applicant', 'posting', 'link', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
