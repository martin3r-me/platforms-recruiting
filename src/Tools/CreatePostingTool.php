<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class CreatePostingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.postings.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/postings - Erstellt eine neue Ausschreibung (Posting). Parameter: title (required), rec_position_id (required).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Titel der Ausschreibung (ERFORDERLICH).',
                ],
                'rec_position_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Position (ERFORDERLICH). Nutze "recruiting.positions.GET" um IDs zu finden.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional: Beschreibung der Ausschreibung.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: Status (z.B. "draft", "published", "closed"). Default: "draft".',
                    'default' => 'draft',
                ],
                'published_at' => [
                    'type' => 'string',
                    'description' => 'Optional: Veroeffentlichungsdatum (ISO-Datetime).',
                ],
                'closes_at' => [
                    'type' => 'string',
                    'description' => 'Optional: Schlussdatum (ISO-Datetime).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status. Default true.',
                    'default' => true,
                ],
            ],
            'required' => ['title', 'rec_position_id'],
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

            $title = trim((string)($arguments['title'] ?? ''));
            if ($title === '') {
                return ToolResult::error('VALIDATION_ERROR', 'title ist erforderlich.');
            }

            $positionId = (int)($arguments['rec_position_id'] ?? 0);
            if ($positionId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'rec_position_id ist erforderlich.');
            }

            $position = RecPosition::where('team_id', $teamId)->find($positionId);
            if (!$position) {
                return ToolResult::error('NOT_FOUND', 'Position nicht gefunden (oder kein Zugriff).');
            }

            $posting = RecPosting::create([
                'title' => $title,
                'rec_position_id' => $position->id,
                'description' => $arguments['description'] ?? null,
                'status' => $arguments['status'] ?? 'draft',
                'published_at' => $arguments['published_at'] ?? null,
                'closes_at' => $arguments['closes_at'] ?? null,
                'is_active' => (bool)($arguments['is_active'] ?? true),
                'team_id' => $teamId,
                'created_by_user_id' => $context->user->id,
            ]);

            return ToolResult::success([
                'id' => $posting->id,
                'uuid' => $posting->uuid,
                'title' => $posting->title,
                'status' => $posting->status,
                'is_active' => (bool)$posting->is_active,
                'position' => [
                    'id' => $position->id,
                    'title' => $position->title,
                ],
                'team_id' => $posting->team_id,
                'message' => 'Ausschreibung erfolgreich erstellt.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Erstellen der Ausschreibung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'postings', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
