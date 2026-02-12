<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdatePostingTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.postings.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/postings/{id} - Aktualisiert eine Ausschreibung. Parameter: posting_id (required).';
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
                    'description' => 'ID der Ausschreibung (ERFORDERLICH). Nutze "recruiting.postings.GET".',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional: neuer Titel.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional: neue Beschreibung.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: neuer Status (z.B. "draft", "published", "closed").',
                ],
                'published_at' => [
                    'type' => 'string',
                    'description' => 'Optional: Veroeffentlichungsdatum (ISO-Datetime oder "now").',
                ],
                'closes_at' => [
                    'type' => 'string',
                    'description' => 'Optional: Schlussdatum (ISO-Datetime).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status.',
                ],
                'rec_position_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Position aendern. Nutze "recruiting.positions.GET" um IDs zu finden.',
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
            $teamId = (int)$resolved['team_id'];

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

            $fields = ['title', 'description', 'status', 'is_active', 'rec_position_id'];
            foreach ($fields as $field) {
                if (array_key_exists($field, $arguments)) {
                    $posting->{$field} = $arguments[$field] === '' ? null : $arguments[$field];
                }
            }

            if (array_key_exists('published_at', $arguments)) {
                $val = $arguments['published_at'];
                if ($val === 'now') {
                    $posting->published_at = now();
                } elseif ($val === '' || $val === null) {
                    $posting->published_at = null;
                } else {
                    $posting->published_at = $val;
                }
            }

            if (array_key_exists('closes_at', $arguments)) {
                $val = $arguments['closes_at'];
                if ($val === '' || $val === null) {
                    $posting->closes_at = null;
                } else {
                    $posting->closes_at = $val;
                }
            }

            $posting->save();

            return ToolResult::success([
                'id' => $posting->id,
                'uuid' => $posting->uuid,
                'title' => $posting->title,
                'status' => $posting->status,
                'is_active' => (bool)$posting->is_active,
                'published_at' => $posting->published_at?->toISOString(),
                'closes_at' => $posting->closes_at?->toISOString(),
                'rec_position_id' => $posting->rec_position_id,
                'team_id' => $posting->team_id,
                'message' => 'Ausschreibung erfolgreich aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren der Ausschreibung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'postings', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
