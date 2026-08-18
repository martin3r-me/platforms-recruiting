<?php

namespace Platform\Recruiting\Tools;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\Core\Tools\Concerns\HasStandardizedWriteOperations;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Tools\Concerns\ResolvesRecruitingTeam;

class BulkCreateApplicantsTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicants.bulk.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/applicants/bulk - Erstellt mehrere Bewerber gleichzeitig. Jeder Eintrag in items benoetigt einen CRM-Contact (contact_id oder create_contact). Max. 100 Eintraege.';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'items' => [
                    'type' => 'array',
                    'description' => 'Array von Bewerbern zum Erstellen (max. 100).',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'contact_id' => [
                                'type' => 'integer',
                                'description' => 'Existierender CRM Contact. MUSS gesetzt sein, wenn create_contact nicht angegeben ist.',
                            ],
                            'create_contact' => [
                                'type' => 'object',
                                'description' => 'Neuen CRM Contact erstellen. MUSS gesetzt sein, wenn contact_id nicht angegeben ist.',
                                'properties' => [
                                    'first_name' => ['type' => 'string'],
                                    'last_name' => ['type' => 'string'],
                                    'middle_name' => ['type' => 'string'],
                                    'nickname' => ['type' => 'string'],
                                    'birth_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                                    'notes' => ['type' => 'string'],
                                ],
                                'required' => ['first_name', 'last_name'],
                            ],
                            'rec_applicant_status_id' => [
                                'type' => 'integer',
                                'description' => 'Optional: Bewerbungsstatus.',
                            ],
                            'applied_at' => [
                                'type' => 'string',
                                'description' => 'Optional: Bewerbungsdatum (YYYY-MM-DD). Default: heute.',
                            ],
                            'notes' => [
                                'type' => 'string',
                                'description' => 'Optional: Notizen.',
                            ],
                            'is_active' => [
                                'type' => 'boolean',
                                'description' => 'Optional: Status. Default true.',
                            ],
                            'owned_by_user_id' => [
                                'type' => 'integer',
                                'description' => 'Optional: Owner. Default: current user.',
                            ],
                            'posting_id' => [
                                'type' => 'integer',
                                'description' => 'Optional: Posting-ID fuer Verknuepfung.',
                            ],
                        ],
                    ],
                ],
            ],
            'required' => ['items'],
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

            $items = $arguments['items'] ?? [];
            if (empty($items)) {
                return ToolResult::error('VALIDATION_ERROR', 'items darf nicht leer sein.');
            }
            if (count($items) > 100) {
                return ToolResult::error('VALIDATION_ERROR', 'Maximal 100 Eintraege pro Bulk-Operation.');
            }

            $results = [];
            $succeeded = 0;
            $failed = 0;

            foreach ($items as $index => $item) {
                try {
                    $contactId = isset($item['contact_id']) ? (int)$item['contact_id'] : null;
                    $createContact = $item['create_contact'] ?? null;

                    if (!$contactId && !$createContact) {
                        $results[] = [
                            'index' => $index,
                            'success' => false,
                            'error' => 'VALIDATION_ERROR',
                            'error_message' => 'contact_id oder create_contact ist erforderlich.',
                        ];
                        $failed++;
                        continue;
                    }

                    $result = DB::transaction(function () use ($teamId, $context, $contactId, $createContact, $item) {
                        $applicant = RecApplicant::create([
                            'rec_applicant_status_id' => isset($item['rec_applicant_status_id']) ? (int)$item['rec_applicant_status_id'] : null,
                            'applied_at' => $item['applied_at'] ?? now()->toDateString(),
                            'notes' => $item['notes'] ?? null,
                            'progress' => 0,
                            'team_id' => $teamId,
                            'created_by_user_id' => $context->user->id,
                            'owned_by_user_id' => isset($item['owned_by_user_id']) ? (int)$item['owned_by_user_id'] : (int)$context->user->id,
                            'is_active' => (bool)($item['is_active'] ?? true),
                        ]);

                        $contact = null;
                        if ($contactId) {
                            $contact = CrmContact::find($contactId);
                            if (!$contact) {
                                throw new \RuntimeException('CRM Contact nicht gefunden.');
                            }
                            Gate::forUser($context->user)->authorize('view', $contact);

                            $contactTeamId = (int)$contact->team_id;
                            if ($contactTeamId !== $teamId) {
                                $contactTeam = Team::find($contactTeamId);
                                $applicantTeam = Team::find($teamId);
                                if (!$contactTeam || !$applicantTeam) {
                                    throw new \RuntimeException("Team nicht gefunden.");
                                }
                                if (!$applicantTeam->isChildOf($contactTeam)) {
                                    throw new \RuntimeException("CRM Contact gehoert nicht zum Team oder einem Elternteam.");
                                }
                            }
                        } else {
                            Gate::forUser($context->user)->authorize('create', CrmContact::class);
                            $contact = CrmContact::create(array_merge($createContact, [
                                'team_id' => $teamId,
                                'created_by_user_id' => $context->user->id,
                            ]));
                        }

                        CrmContactLink::firstOrCreate(
                            [
                                'contact_id' => $contact->id,
                                'linkable_type' => $applicant->getMorphClass(),
                                'linkable_id' => $applicant->id,
                            ],
                            [
                                'team_id' => $teamId,
                                'created_by_user_id' => $context->user->id,
                            ]
                        );

                        if (!empty($item['posting_id'])) {
                            $posting = RecPosting::where('team_id', $teamId)->find((int)$item['posting_id']);
                            if (!$posting) {
                                throw new \RuntimeException('Posting nicht gefunden (oder kein Zugriff).');
                            }
                            $applicant->postings()->attach($posting->id, [
                                'applied_at' => $item['applied_at'] ?? now()->toDateString(),
                            ]);
                            $applicant->stelleAusAnzeigeUebernehmen();
                        }

                        return [
                            'id' => $applicant->id,
                            'uuid' => $applicant->uuid,
                            'contact_id' => $contact->id,
                            'contact_name' => $contact->full_name,
                        ];
                    });

                    $results[] = array_merge(['index' => $index, 'success' => true], $result);
                    $succeeded++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'index' => $index,
                        'success' => false,
                        'error' => 'EXECUTION_ERROR',
                        'error_message' => $e->getMessage(),
                    ];
                    $failed++;
                }
            }

            return ToolResult::success([
                'processed' => count($items),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler bei Bulk-Erstellung: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'applicants', 'bulk', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
