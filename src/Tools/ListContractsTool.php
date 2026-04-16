<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardGetOperations;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class ListContractsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardGetOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.contracts.GET';
    }

    public function getDescription(): string
    {
        return 'GET /recruiting/contracts - Listet Bewerber-Verträge. Parameter: team_id (optional), applicant_id (optional), contract_template_id (optional), status (optional: pending/sent/in_progress/completed/needs_review), filters/search/sort/limit/offset (optional).';
    }

    public function getSchema(): array
    {
        return $this->mergeSchemas(
            $this->getStandardGetSchema(),
            [
                'properties' => [
                    'team_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                    ],
                    'applicant_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Filter nach Bewerber.',
                    ],
                    'contract_template_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Filter nach Vertragsvorlage.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Optional: Filter nach Status (pending/sent/in_progress/completed/needs_review).',
                    ],
                ],
            ]
        );
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int)$resolved['team_id'];

            $query = RecContract::query()
                ->with(['contractTemplate', 'applicant.crmContactLinks.contact'])
                ->where('team_id', $teamId);

            if (isset($arguments['applicant_id'])) {
                $query->where('rec_applicant_id', (int)$arguments['applicant_id']);
            }
            if (isset($arguments['contract_template_id'])) {
                $query->where('rec_contract_template_id', (int)$arguments['contract_template_id']);
            }
            if (isset($arguments['status'])) {
                $query->where('status', (string)$arguments['status']);
            }

            $this->applyStandardFilters($query, $arguments, ['status', 'sent_at', 'completed_at', 'created_at']);
            $this->applyStandardSearch($query, $arguments, ['notes']);
            $this->applyStandardSort($query, $arguments, ['status', 'sent_at', 'completed_at', 'created_at'], 'created_at', 'desc');

            $result = $this->applyStandardPaginationResult($query, $arguments);

            $data = collect($result['data'])->map(fn(RecContract $c) => [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'applicant_id' => $c->rec_applicant_id,
                'candidate_name' => $c->applicant?->crmContactLinks?->first()?->contact?->full_name,
                'contract_template' => $c->contractTemplate ? [
                    'id' => $c->contractTemplate->id,
                    'name' => $c->contractTemplate->name,
                    'code' => $c->contractTemplate->code,
                ] : null,
                'status' => $c->status,
                'has_signature' => !empty($c->signature_data),
                'signed_at' => $c->signed_at?->toISOString(),
                'sent_at' => $c->sent_at?->toISOString(),
                'completed_at' => $c->completed_at?->toISOString(),
                'notes' => $c->notes,
                'created_at' => $c->created_at?->toISOString(),
            ])->values()->toArray();

            return ToolResult::success([
                'data' => $data,
                'pagination' => $result['pagination'] ?? null,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Verträge: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => true,
            'category' => 'read',
            'tags' => ['recruiting', 'contracts', 'list'],
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
