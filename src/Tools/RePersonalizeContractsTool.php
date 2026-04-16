<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class RePersonalizeContractsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.contracts.repersonalize';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/contracts/repersonalize - Re-personalisiert den Vertragstext anhand der aktuellen Extra-Field-Werte und Kontaktdaten. Parameter: applicant_id (optional, alle Verträge dieses Bewerbers) ODER contract_id (optional, einzelner Vertrag). Mindestens einer erforderlich. Bereits unterschriebene (completed) Verträge werden übersprungen, es sei denn include_completed=true.';
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
                    'description' => 'Optional: Bewerber-ID. Alle Verträge dieses Bewerbers werden re-personalisiert.',
                ],
                'contract_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Einzelner Vertrag re-personalisieren.',
                ],
                'include_completed' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Auch bereits unterschriebene Verträge re-personalisieren. Default: false.',
                ],
            ],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];

            $applicantId = $arguments['applicant_id'] ?? null;
            $contractId = $arguments['contract_id'] ?? null;
            $includeCompleted = $arguments['include_completed'] ?? false;

            if (!$applicantId && !$contractId) {
                return ToolResult::error('VALIDATION_ERROR', 'Mindestens applicant_id oder contract_id ist erforderlich.');
            }

            $query = RecContract::with(['contractTemplate', 'applicant'])
                ->where('team_id', $teamId);

            if ($contractId) {
                $query->where('id', $contractId);
            } elseif ($applicantId) {
                $query->where('rec_applicant_id', $applicantId);
            }

            $contracts = $query->get();

            if ($contracts->isEmpty()) {
                return ToolResult::error('NOT_FOUND', 'Keine Verträge gefunden.');
            }

            $results = [];
            foreach ($contracts as $contract) {
                if (!$contract->contractTemplate) {
                    $results[] = [
                        'contract_id' => $contract->id,
                        'status' => 'skipped',
                        'reason' => 'Keine Vertragsvorlage zugeordnet.',
                    ];
                    continue;
                }

                // Skip completed (signed) contracts unless explicitly requested
                if ($contract->status === 'completed' && !$includeCompleted) {
                    $results[] = [
                        'contract_id' => $contract->id,
                        'template' => $contract->contractTemplate->name,
                        'status' => 'skipped',
                        'reason' => 'Bereits unterschrieben. Nutze include_completed=true um trotzdem zu re-personalisieren.',
                    ];
                    continue;
                }

                $contract->personalized_content = $contract->contractTemplate->personalizeContent(
                    $contract->applicant,
                    $contract
                );

                // For completed contracts: re-embed pre-signing data at correct positions
                if ($contract->status === 'completed' && !empty($contract->pre_signing_data)) {
                    $contract->personalized_content = RecContract::embedPreSigningData(
                        $contract->personalized_content,
                        $contract->pre_signing_data
                    );
                }

                $contract->save();

                $results[] = [
                    'contract_id' => $contract->id,
                    'template' => $contract->contractTemplate->name,
                    'status' => 'updated',
                ];
            }

            return ToolResult::success([
                'updated_count' => collect($results)->where('status', 'updated')->count(),
                'total' => count($results),
                'contracts' => $results,
                'message' => collect($results)->where('status', 'updated')->count() . ' Vertrag/Verträge re-personalisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'contracts', 'repersonalize', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
