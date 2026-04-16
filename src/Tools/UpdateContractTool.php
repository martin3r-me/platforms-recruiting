<?php

namespace Platform\Recruiting\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class UpdateContractTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.contracts.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /recruiting/contracts/{id} - Aktualisiert einen Bewerber-Vertrag (Status, Content, Notizen). Parameter: contract_id (required). Status-Workflow: pending → sent → in_progress → completed / needs_review. Auto-setzt sent_at/completed_at bei Statuswechsel.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'contract_id' => [
                    'type' => 'integer',
                    'description' => 'ID des Vertrags (ERFORDERLICH).',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: Neuer Status (pending/sent/in_progress/completed/needs_review).',
                ],
                'personalized_content' => [
                    'type' => 'string',
                    'description' => 'Optional: Aktualisierter Vertragstext.',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Optional: Notizen.',
                ],
                'signature_data' => [
                    'type' => 'string',
                    'description' => 'Optional: Unterschrift als base64-PNG.',
                ],
            ],
            'required' => ['contract_id'],
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

            $found = $this->validateAndFindModel($arguments, $context, 'contract_id', RecContract::class, 'NOT_FOUND', 'Vertrag nicht gefunden.');
            if ($found['error']) {
                return $found['error'];
            }
            $contract = $found['model'];

            if ((int)$contract->team_id !== $teamId) {
                return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf diesen Vertrag.');
            }

            if (isset($arguments['status'])) {
                $validStatuses = ['pending', 'sent', 'in_progress', 'completed', 'needs_review'];
                if (!in_array($arguments['status'], $validStatuses)) {
                    return ToolResult::error('VALIDATION_ERROR', 'Ungültiger Status. Erlaubt: ' . implode(', ', $validStatuses));
                }

                $oldStatus = $contract->status;
                $newStatus = $arguments['status'];
                $contract->status = $newStatus;

                if ($oldStatus !== 'sent' && $newStatus === 'sent' && !$contract->sent_at) {
                    $contract->sent_at = now();
                }
                if ($oldStatus !== 'completed' && $newStatus === 'completed' && !$contract->completed_at) {
                    $contract->completed_at = now();
                }
            }

            if (array_key_exists('personalized_content', $arguments)) {
                $contract->personalized_content = $arguments['personalized_content'];
            }

            if (array_key_exists('notes', $arguments)) {
                $contract->notes = $arguments['notes'] === '' ? null : $arguments['notes'];
            }

            if (array_key_exists('signature_data', $arguments)) {
                $contract->signature_data = $arguments['signature_data'];
                if ($arguments['signature_data'] && !$contract->signed_at) {
                    $contract->signed_at = now();
                }
            }

            $contract->save();

            return ToolResult::success([
                'id' => $contract->id,
                'uuid' => $contract->uuid,
                'status' => $contract->status,
                'message' => 'Vertrag erfolgreich aktualisiert.',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Aktualisieren des Vertrags: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'contracts', 'update'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => true,
        ];
    }
}
