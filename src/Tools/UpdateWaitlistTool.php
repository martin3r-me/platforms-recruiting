<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdateWaitlistTool implements ToolContract, ToolMetadataContract
{
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.waitlist.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/waitlist - Aktualisiert offene Warteliste-Einträge eines Bewerbers. action="reset_notification" setzt notified_at zurück (Bewerber wird beim nächsten passenden Termin wieder benachrichtigt — z.B. nach Fehlversand). action="cancel" schließt den Eintrag (cancelled_at). Parameter: applicant_id (required), action (required).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'applicant_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Bewerbers (ERFORDERLICH).',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['reset_notification', 'cancel'],
                    'description' => 'reset_notification = notified_at auf NULL (erneut benachrichtigbar). cancel = Eintrag schließen (cancelled_at = jetzt).',
                ],
            ],
            'required' => ['applicant_id', 'action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];

            $applicantId = (int) ($arguments['applicant_id'] ?? 0);
            if ($applicantId <= 0) {
                return ToolResult::error('MISSING_PARAM', 'applicant_id ist erforderlich.');
            }

            $action = $arguments['action'] ?? null;
            if (!in_array($action, ['reset_notification', 'cancel'], true)) {
                return ToolResult::error('INVALID_ACTION', 'action muss "reset_notification" oder "cancel" sein.');
            }

            $query = RecInterviewWaitlist::forTeam($teamId)
                ->where('rec_applicant_id', $applicantId)
                ->open();

            $update = $action === 'reset_notification'
                ? ['notified_at' => null]
                : ['cancelled_at' => now()];

            $affected = $query->update($update);

            return ToolResult::success([
                'applicant_id' => $applicantId,
                'action' => $action,
                'affected' => $affected,
                'message' => $affected > 0
                    ? ($action === 'reset_notification'
                        ? "notified_at für {$affected} offene(n) Eintrag/Einträge zurückgesetzt."
                        : "{$affected} Eintrag/Einträge storniert.")
                    : 'Kein offener Warteliste-Eintrag für diesen Bewerber gefunden.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren der Warteliste: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'waitlist', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
