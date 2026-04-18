<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdateInterviewTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.interviews.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/interviews/{id} - Aktualisiert einen Interview-Termin. Parameter: interview_id (required).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID.',
                ],
                'interview_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Interviews (ERFORDERLICH).',
                ],
                'interview_type_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Interview-Typ-ID.',
                ],
                'position_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Position-ID.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Optional: Titel.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional: Beschreibung.',
                ],
                'location' => [
                    'type' => 'string',
                    'description' => 'Optional: Ort.',
                ],
                'starts_at' => [
                    'type' => 'string',
                    'description' => 'Optional: Start-Zeitpunkt.',
                ],
                'ends_at' => [
                    'type' => 'string',
                    'description' => 'Optional: End-Zeitpunkt.',
                ],
                'min_participants' => [
                    'type' => 'integer',
                    'description' => 'Optional: Min. Teilnehmer.',
                ],
                'max_participants' => [
                    'type' => 'integer',
                    'description' => 'Optional: Max. Teilnehmer (0 = unbegrenzt).',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: Status (planned/confirmed/cancelled/completed).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Aktiv.',
                ],
                'interviewer_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Optional: Neue Interviewer-IDs (ersetzt bestehende).',
                ],
            ],
            'required' => ['interview_id'],
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

            $found = $this->validateAndFindModel($arguments, $context, 'interview_id', RecInterview::class, 'NOT_FOUND', 'Interview nicht gefunden.');
            if ($found['error']) {
                return $found['error'];
            }
            $interview = $found['model'];

            if ((int)$interview->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf dieses Interview.');
            }

            $fields = ['title', 'description', 'location', 'starts_at', 'ends_at', 'min_participants', 'max_participants', 'status', 'is_active', 'interview_type_id', 'reminder_wa_template_id', 'reminder_hours_before'];
            foreach ($fields as $field) {
                if (array_key_exists($field, $arguments)) {
                    $interview->{$field} = $arguments[$field] === '' ? null : $arguments[$field];
                }
            }

            if (array_key_exists('position_id', $arguments)) {
                $interview->rec_position_id = $arguments['position_id'] ?: null;
            }

            $interview->save();

            if (array_key_exists('interviewer_ids', $arguments)) {
                $interview->interviewers()->sync($arguments['interviewer_ids'] ?? []);
            }

            return ToolResult::success([
                'id' => $interview->id,
                'uuid' => $interview->uuid,
                'title' => $interview->title,
                'status' => $interview->status,
                'message' => 'Interview erfolgreich aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren des Interviews: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'interviews', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
