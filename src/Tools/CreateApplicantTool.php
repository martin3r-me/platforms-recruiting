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

class CreateApplicantTool implements ToolContract, ToolMetadataContract
{
    use HasStandardizedWriteOperations;
    use ResolvesRecruitingTeam;

    public function getName(): string
    {
        return 'recruiting.applicants.POST';
    }

    public function getDescription(): string
    {
        return 'POST /recruiting/applicants - Erstellt einen Bewerber. Ein CRM-Contact MUSS verknüpft werden (entweder contact_id oder create_contact).';
    }

    public function getSchema(): array
    {
        return $this->mergeWriteSchema([
            'properties' => [
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID. Default: aktuelles Team aus Kontext.',
                ],
                'rec_applicant_status_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Bewerbungsstatus. Nutze "recruiting.lookup.GET" mit lookup=applicant_statuses.',
                ],
                'applied_at' => [
                    'type' => 'string',
                    'description' => 'Optional: Bewerbungsdatum (YYYY-MM-DD). Default: heute.',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Optional: Notizen zur Bewerbung.',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Optional: Status. Default true.',
                    'default' => true,
                ],
                'owned_by_user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Owner des Bewerber-Datensatzes. Default: current user.',
                ],
                'posting_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Posting (Ausschreibung), auf die sich der Bewerber bewirbt. Nutze "recruiting.postings.GET" um IDs zu finden.',
                ],
                'contact_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Existierender CRM Contact, der verknüpft werden soll. MUSS gesetzt sein, wenn create_contact nicht angegeben ist.',
                ],
                'create_contact' => [
                    'type' => 'object',
                    'description' => 'Optional: Erstellt einen neuen CRM Contact und verknüpft ihn. MUSS gesetzt sein, wenn contact_id nicht angegeben ist.',
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
            ],
            'required' => [],
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

            $contactId = isset($arguments['contact_id']) ? (int)$arguments['contact_id'] : null;
            $createContact = $arguments['create_contact'] ?? null;

            if (!$contactId && !$createContact) {
                return ToolResult::error('VALIDATION_ERROR', 'Es muss ein CRM Contact verknüpft werden: setze contact_id oder create_contact.');
            }

            $isActive = (bool)($arguments['is_active'] ?? true);
            $ownedByUserId = isset($arguments['owned_by_user_id']) ? (int)$arguments['owned_by_user_id'] : (int)$context->user->id;

            $result = DB::transaction(function () use ($teamId, $context, $contactId, $createContact, $isActive, $ownedByUserId, $arguments) {
                $applicant = RecApplicant::create([
                    'rec_applicant_status_id' => isset($arguments['rec_applicant_status_id']) ? (int)$arguments['rec_applicant_status_id'] : null,
                    'applied_at' => $arguments['applied_at'] ?? now()->toDateString(),
                    'notes' => $arguments['notes'] ?? null,
                    'progress' => 0,
                    'team_id' => $teamId,
                    'created_by_user_id' => $context->user->id,
                    'owned_by_user_id' => $ownedByUserId,
                    'is_active' => $isActive,
                ]);

                $contact = null;
                if ($contactId) {
                    $contact = CrmContact::find($contactId);
                    if (!$contact) {
                        throw new \RuntimeException('CRM Contact nicht gefunden.');
                    }
                    Gate::forUser($context->user)->authorize('view', $contact);

                    // Team-Hierarchie pruefen
                    $contactTeamId = (int)$contact->team_id;
                    $applicantTeamId = (int)$teamId;

                    if ($contactTeamId !== $applicantTeamId) {
                        $contactTeam = Team::find($contactTeamId);
                        $applicantTeam = Team::find($applicantTeamId);

                        if (!$contactTeam || !$applicantTeam) {
                            throw new \RuntimeException("Team nicht gefunden (Contact: {$contactTeamId}, Applicant: {$applicantTeamId}).");
                        }

                        if (!$applicantTeam->isChildOf($contactTeam)) {
                            throw new \RuntimeException("CRM Contact gehoert nicht zum Team {$teamId} oder einem Elternteam davon.");
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
                        'linkable_type' => RecApplicant::class,
                        'linkable_id' => $applicant->id,
                    ],
                    [
                        'team_id' => $teamId,
                        'created_by_user_id' => $context->user->id,
                    ]
                );

                // Posting verknüpfen (optional)
                $posting = null;
                if (!empty($arguments['posting_id'])) {
                    $posting = RecPosting::where('team_id', $teamId)->find((int)$arguments['posting_id']);
                    if (!$posting) {
                        throw new \RuntimeException('Posting nicht gefunden (oder kein Zugriff).');
                    }
                    $applicant->postings()->attach($posting->id, [
                        'applied_at' => $arguments['applied_at'] ?? now()->toDateString(),
                    ]);
                }

                return [$applicant, $contact, $posting];
            });

            /** @var RecApplicant $applicant */
            /** @var CrmContact $contact */
            /** @var RecPosting|null $posting */
            [$applicant, $contact, $posting] = $result;

            $response = [
                'id' => $applicant->id,
                'uuid' => $applicant->uuid,
                'rec_applicant_status_id' => $applicant->rec_applicant_status_id,
                'applied_at' => $applicant->applied_at?->toDateString(),
                'team_id' => $applicant->team_id,
                'is_active' => (bool)$applicant->is_active,
                'crm_contact' => [
                    'contact_id' => $contact->id,
                    'full_name' => $contact->full_name,
                    'display_name' => $contact->display_name,
                ],
                'message' => 'Bewerber erfolgreich erstellt und mit CRM Contact verknuepft.',
            ];

            if ($posting) {
                $response['posting'] = [
                    'id' => $posting->id,
                    'title' => $posting->title,
                ];
            }

            return ToolResult::success($response);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf den CRM Contact.');
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Erstellen des Bewerbers: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'read_only' => false,
            'category' => 'action',
            'tags' => ['recruiting', 'applicants', 'create'],
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'idempotent' => false,
        ];
    }
}
