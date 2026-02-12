<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class DeletePostingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.postings.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /recruiting/postings/{id} - Loescht eine Ausschreibung. Parameter: posting_id (required), confirm (required=true). Warnt wenn Bewerber verknuepft sind.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'posting_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Ausschreibung (ERFORDERLICH).',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'ERFORDERLICH: Setze confirm=true um wirklich zu loeschen.',
                ],
            ],
            'required' => ['posting_id', 'confirm'],
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

            if (!($arguments['confirm'] ?? false)) {
                return ToolResult::error('CONFIRMATION_REQUIRED', 'Bitte bestaetige mit confirm: true.');
            }

            $found = $this->validateAndFindModel(
                $arguments, $context, 'posting_id', RecPosting::class, 'NOT_FOUND', 'Posting nicht gefunden.'
            );
            if ($found['error']) {
                return $found['error'];
            }
            /** @var RecPosting $posting */
            $posting = $found['model'];

            if ((int)$posting->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Du hast keinen Zugriff auf dieses Posting.');
            }

            $applicantCount = $posting->applicants()->count();
            if ($applicantCount > 0) {
                return ToolResult::error('HAS_APPLICANTS', "Dieses Posting hat {$applicantCount} verknuepfte(n) Bewerber. Entferne die Verknuepfungen zuerst (recruiting.applicant_postings.DELETE).");
            }

            $postingId = (int)$posting->id;
            $posting->delete();

            return ToolResult::success([
                'posting_id' => $postingId,
                'message' => 'Ausschreibung geloescht.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Loeschen der Ausschreibung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'postings', 'delete'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
