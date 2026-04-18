<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewType;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class CreateInterviewTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.interviews.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/interviews - Erstellt einen Interview-Termin. ERFORDERLICH: starts_at.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID.',
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
                    'description' => 'Start-Zeitpunkt (ERFORDERLICH). Format: YYYY-MM-DD HH:MM oder ISO 8601.',
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
                    'description' => 'Optional: Status (planned/confirmed). Default: planned.',
                ],
                'interviewer_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Optional: Array von User-IDs als Interviewer.',
                ],
                'reminder_wa_template_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: WhatsApp-Template-ID für Erinnerung.',
                ],
                'reminder_hours_before' => [
                    'type' => 'integer',
                    'description' => 'Optional: Stunden vor dem Termin für Erinnerung.',
                ],
            ],
            'required' => ['starts_at'],
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

            $startsAt = $arguments['starts_at'] ?? null;
            if (!$startsAt) {
                return ToolResult::error('VALIDATION_ERROR', 'starts_at ist erforderlich.');
            }

            if (isset($arguments['interview_type_id'])) {
                $typeExists = RecInterviewType::where('team_id', $teamId)->where('id', (int)$arguments['interview_type_id'])->exists();
                if (!$typeExists) {
                    return ToolResult::error('NOT_FOUND', 'Interview-Typ nicht gefunden.');
                }
            }

            if (isset($arguments['position_id'])) {
                $posExists = RecPosition::where('team_id', $teamId)->where('id', (int)$arguments['position_id'])->exists();
                if (!$posExists) {
                    return ToolResult::error('NOT_FOUND', 'Position nicht gefunden.');
                }
            }

            $interview = RecInterview::create([
                'interview_type_id' => $arguments['interview_type_id'] ?? null,
                'rec_position_id' => $arguments['position_id'] ?? null,
                'title' => $arguments['title'] ?? null,
                'description' => $arguments['description'] ?? null,
                'location' => $arguments['location'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $arguments['ends_at'] ?? null,
                'min_participants' => $arguments['min_participants'] ?? null,
                'max_participants' => $arguments['max_participants'] ?? null,
                'status' => $arguments['status'] ?? 'planned',
                'is_active' => true,
                'team_id' => $teamId,
                'reminder_wa_template_id' => $arguments['reminder_wa_template_id'] ?? null,
                'reminder_hours_before' => $arguments['reminder_hours_before'] ?? null,
                'created_by_user_id' => $context->user->id,
                'owned_by_user_id' => $context->user->id,
            ]);

            if (!empty($arguments['interviewer_ids'])) {
                $interview->interviewers()->sync($arguments['interviewer_ids']);
            }

            return ToolResult::success([
                'id' => $interview->id,
                'uuid' => $interview->uuid,
                'title' => $interview->title,
                'starts_at' => $interview->starts_at?->toISOString(),
                'message' => 'Interview-Termin erfolgreich erstellt.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Erstellen des Interviews: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'interviews', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
