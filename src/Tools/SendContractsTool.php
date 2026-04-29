<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\SendContractsService;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class SendContractsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicants.send_contracts';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/applicants/{id}/send-contracts - Erstellt + verschickt das Vertragsbündel (AV-Variante laut applicant.contract_template_id + IFSG automatisch). Sets sent_at auf beiden Verträgen, was die phase-completion check contract_sent triggert. Voraussetzung: applicant.contract_template_id muss gesetzt sein. Idempotent — bestehende Verträge werden wiederverwendet.';
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
                    'description' => 'ID des Bewerbers (ERFORDERLICH).',
                ],
            ],
            'required' => ['applicant_id'],
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

            $found = $this->validateAndFindModel(
                $arguments,
                $context,
                'applicant_id',
                RecApplicant::class,
                'NOT_FOUND',
                'Bewerber nicht gefunden.'
            );
            if ($found['error']) {
                return $found['error'];
            }
            /** @var RecApplicant $applicant */
            $applicant = $found['model'];

            if ((int) $applicant->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf diesen Bewerber.');
            }

            $service = app(SendContractsService::class);
            $result = $service->send($applicant, $context->user?->id);

            return ToolResult::success([
                'applicant_id' => $applicant->id,
                'av_contract_id' => $result['av_contract']->id,
                'av_contract_status' => $result['av_contract']->status,
                'av_contract_sent_at' => $result['av_contract']->sent_at?->toISOString(),
                'ifsg_contract_id' => $result['ifsg_contract']?->id,
                'ifsg_contract_status' => $result['ifsg_contract']?->status,
                'ifsg_contract_sent_at' => $result['ifsg_contract']?->sent_at?->toISOString(),
                'created_count' => $result['created'],
                'reused_count' => $result['reused'],
                'phase_id_after' => $applicant->fresh()->rec_phase_id,
                'message' => 'Verträge erstellt und als versandt markiert.',
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error('VALIDATION_ERROR', $e->getMessage());
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Verträge versenden: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'contracts', 'send'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
