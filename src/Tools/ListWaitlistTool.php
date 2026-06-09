<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListWaitlistTool implements ToolContract, ToolMetadataContract
{
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.waitlist.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/waitlist - Listet Schulung-Warteliste-Einträge. Zeigt Wunschorte (Snapshot), notified_at ("benachrichtigt"-Zeitpunkt), fulfilled_at (gebucht) und cancelled_at. Default nur offene Einträge (weder gebucht noch storniert). Parameter: applicant_id (optional), include_closed (optional, default false).';
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
                    'description' => 'Optional: nur Einträge dieses Bewerbers.',
                ],
                'include_closed' => [
                    'type' => 'boolean',
                    'description' => 'Optional: auch erfüllte/stornierte Einträge zeigen. Default false (nur offene).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional: Max. Anzahl. Default 50, Max 200.',
                ],
            ],
            'required' => [],
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

            $limit = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
            $includeClosed = (bool) ($arguments['include_closed'] ?? false);

            $query = RecInterviewWaitlist::forTeam($teamId)
                ->with('applicant')
                ->orderByDesc('id');

            if (!$includeClosed) {
                $query->open();
            }

            if (!empty($arguments['applicant_id'])) {
                $query->where('rec_applicant_id', (int) $arguments['applicant_id']);
            }

            $rows = $query->limit($limit)->get()->map(function (RecInterviewWaitlist $entry) {
                return [
                    'id' => $entry->id,
                    'rec_applicant_id' => $entry->rec_applicant_id,
                    'applicant_name' => $entry->applicant?->getContact()?->full_name,
                    'wunschorte' => $entry->wunschorte,
                    'enrolled_at' => $entry->enrolled_at?->toIso8601String(),
                    'notified_at' => $entry->notified_at?->toIso8601String(),
                    'fulfilled_at' => $entry->fulfilled_at?->toIso8601String(),
                    'cancelled_at' => $entry->cancelled_at?->toIso8601String(),
                    'status' => $entry->fulfilled_at ? 'gebucht'
                        : ($entry->cancelled_at ? 'storniert'
                        : ($entry->notified_at ? 'benachrichtigt' : 'wartet')),
                ];
            })->toArray();

            return ToolResult::success([
                'count' => count($rows),
                'waitlist' => $rows,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Warteliste: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'waitlist', 'list'],
            'risk_level' => 'read',
        ];
    }
}
