<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Recruiting\Models\RecAutoPilotLog;

class GetEnrichmentLogsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'recruiting.enrichment_logs.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/enrichment_logs - Zeigt Enrichment-/AutoPilot-Logs für einen Bewerber oder die letzten Logs insgesamt. Enthält Tool-Call-Details, Fehler und Ergebnisse.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'applicant_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Logs für einen bestimmten Bewerber.',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Optional: Log-Typ filtern (z.B. "run_started", "run_completed", "error", "auto_linked", "no_contact", "whatsapp_template_sent").',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional: Max. Anzahl Logs. Default: 20, Max: 100.',
                ],
                'include_details' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Details-JSON (Tool-Calls etc.) mitliefern. Default: true.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            if (!$context->user) {
                return ToolResult::error('AUTH_ERROR', 'Kein User im Kontext gefunden.');
            }

            $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));
            $includeDetails = (bool) ($arguments['include_details'] ?? true);

            $query = RecAutoPilotLog::query()->orderByDesc('id');

            if (!empty($arguments['applicant_id'])) {
                $query->where('rec_applicant_id', (int) $arguments['applicant_id']);
            }

            if (!empty($arguments['type'])) {
                $query->where('type', $arguments['type']);
            }

            $logs = $query->limit($limit)->get();

            $result = $logs->map(function (RecAutoPilotLog $log) use ($includeDetails) {
                $entry = [
                    'id' => $log->id,
                    'applicant_id' => $log->rec_applicant_id,
                    'type' => $log->type,
                    'summary' => $log->summary,
                    'created_at' => $log->created_at?->toIso8601String(),
                ];

                if ($includeDetails && $log->details) {
                    $entry['details'] = $log->details;
                }

                return $entry;
            })->toArray();

            return ToolResult::success([
                'count' => count($result),
                'logs' => $result,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Logs: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'logs', 'enrichment'],
            'risk_level' => 'read',
        ];
    }
}
