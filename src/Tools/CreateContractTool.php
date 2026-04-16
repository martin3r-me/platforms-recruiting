<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class CreateContractTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.contracts.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/contracts - Weist einem Bewerber einen Vertrag zu. ERFORDERLICH: applicant_id, contract_template_id. Platzhalter im Template werden automatisch mit echten Werten ersetzt (field_mappings), außer personalized_content wird explizit gesetzt.';
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
                    'description' => 'ID des Bewerbers (ERFORDERLICH). Nutze "recruiting.applicants.GET".',
                ],
                'contract_template_id' => [
                    'type' => 'integer',
                    'description' => 'ID der Vertragsvorlage (ERFORDERLICH). Nutze "recruiting.contract_templates.GET".',
                ],
                'personalized_content' => [
                    'type' => 'string',
                    'description' => 'Optional: Personalisierter Vertragstext. Wenn nicht gesetzt, wird der Template-Content kopiert.',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Optional: Notizen zum Vertrag.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: Status (pending/sent). Default: pending.',
                ],
            ],
            'required' => ['applicant_id', 'contract_template_id'],
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

            $applicantId = (int)($arguments['applicant_id'] ?? 0);
            $templateId = (int)($arguments['contract_template_id'] ?? 0);

            if ($applicantId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'applicant_id ist erforderlich.');
            }
            if ($templateId <= 0) {
                return ToolResult::error('VALIDATION_ERROR', 'contract_template_id ist erforderlich.');
            }

            $applicant = RecApplicant::where('team_id', $teamId)->find($applicantId);
            if (!$applicant) {
                return ToolResult::error('NOT_FOUND', 'Bewerber nicht gefunden.');
            }

            $template = RecContractTemplate::where('team_id', $teamId)->find($templateId);
            if (!$template) {
                return ToolResult::error('NOT_FOUND', 'Vertragsvorlage nicht gefunden.');
            }

            $personalizedContent = $arguments['personalized_content'] ?? $template->personalizeContent($applicant);

            $status = $arguments['status'] ?? 'pending';
            $validStatuses = ['pending', 'sent'];
            if (!in_array($status, $validStatuses)) {
                return ToolResult::error('VALIDATION_ERROR', 'Ungültiger Status für Erstellung. Erlaubt: ' . implode(', ', $validStatuses));
            }

            $contract = RecContract::create([
                'rec_applicant_id' => $applicantId,
                'rec_contract_template_id' => $templateId,
                'team_id' => $teamId,
                'status' => $status,
                'personalized_content' => $personalizedContent,
                'notes' => $arguments['notes'] ?? null,
                'sent_at' => $status === 'sent' ? now() : null,
                'created_by_user_id' => $context->user?->id,
            ]);

            return ToolResult::success([
                'id' => $contract->id,
                'uuid' => $contract->uuid,
                'applicant_id' => $applicantId,
                'contract_template_id' => $templateId,
                'status' => $contract->status,
                'message' => 'Vertrag erfolgreich zugewiesen.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Erstellen des Vertrags: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'contracts', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
