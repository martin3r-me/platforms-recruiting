<?php

namespace Platform\Recruiting\Livewire\Applicant;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Core\Livewire\Concerns\WithExtraFields;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactStatus;
use Platform\Core\Livewire\Concerns\ResolvesAutoPilotChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;

class Show extends Component
{
    use WithExtraFields;
    use ResolvesAutoPilotChannel;
    public RecApplicant $applicant;

    public $contactLinkModalShow = false;
    public $contactCreateModalShow = false;

    public bool $templateModalShow = false;
    public ?int $selectedTemplateId = null;
    public array $templateParams = [];
    public array $templateBodyParamDefs = [];

    public $contactForm = [
        'first_name' => '',
        'last_name' => '',
        'middle_name' => '',
        'nickname' => '',
        'birth_date' => '',
        'notes' => '',
        'email' => '',
        'phone' => '',
    ];

    public $contactLinkForm = [
        'contact_id' => null,
    ];

    public $availableContacts = [];

    public $postingLinkModalShow = false;
    public $postingLinkForm = ['posting_id' => null];

    public function mount(RecApplicant $applicant)
    {
        $allowedTeamIds = $this->getAllowedTeamIds($applicant->team_id);

        $this->applicant = $applicant->load([
            'crmContactLinks' => fn ($q) => $q->whereIn('team_id', $allowedTeamIds),
            'crmContactLinks.contact.emailAddresses' => function ($q) {
                $q->active()->orderByDesc('is_primary')->orderBy('id');
            },
            'crmContactLinks.contact.phoneNumbers' => function ($q) {
                $q->active()->orderByDesc('is_primary')->orderBy('id');
            },
            'applicantStatus',
            'autoPilotState',
            'postings.position',
            'preferredCommsChannel',
            'phase',
        ]);

        $this->loadAvailableContacts();
        $this->loadExtraFieldValues($this->applicant);
    }

    public function rules(): array
    {
        return array_merge([
            'applicant.rec_applicant_status_id' => 'nullable|exists:rec_applicant_statuses,id',
            'applicant.owned_by_user_id' => 'nullable|exists:users,id',
            'applicant.notes' => 'nullable|string',
            'applicant.applied_at' => 'nullable|date',
            'applicant.is_active' => 'boolean',
            'applicant.auto_pilot' => 'boolean',
        ], $this->getExtraFieldValidationRules());
    }

    public function messages(): array
    {
        return $this->getExtraFieldValidationMessages();
    }

    public function deleteApplicant(): void
    {
        DB::transaction(function () {
            $this->applicant->crmContactLinks()->delete();
            $this->applicant->delete();
        });

        session()->flash('message', 'Bewerbung erfolgreich gelöscht.');
        $this->redirect(route('recruiting.applicants.index'), navigate: true);
    }

    public function save(): void
    {
        $this->validate();
        $this->applicant->save();
        $this->saveExtraFieldValues($this->applicant);

        $this->applicant->progress = $this->applicant->calculateProgress();
        $this->applicant->save();

        session()->flash('message', 'Bewerber erfolgreich aktualisiert.');
    }

    #[Computed]
    public function publicUrl(): string
    {
        return $this->applicant->getPublicUrl();
    }

    #[Computed]
    public function availableStatuses()
    {
        return RecApplicantStatus::where('team_id', auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function teamUsers()
    {
        return Auth::user()
            ->currentTeam
            ->users()
            ->orderBy('name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->fullname ?? $user->name,
            ]);
    }

    #[Computed]
    public function isDirty()
    {
        return $this->applicant->isDirty() || $this->isExtraFieldsDirty();
    }

    #[Computed]
    public function autoPilotLogs()
    {
        return $this->applicant->autoPilotLogs()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function availablePostings()
    {
        $linkedPostingIds = $this->applicant->postings->pluck('id');
        return RecPosting::with('position')
            ->forTeam(auth()->user()->currentTeam->id)
            ->published()
            ->whereNotIn('id', $linkedPostingIds)
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function teamChannels()
    {
        return CommsChannel::where('team_id', auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->whereIn('type', ['email', 'whatsapp'])
            ->orderBy('type')
            ->get();
    }

    public function toggleAutoPilot(): void
    {
        if ($this->applicant->auto_pilot) {
            $this->applicant->update([
                'auto_pilot' => false,
                'preferred_comms_channel_id' => null,
            ]);
        } else {
            $channel = $this->resolvePreferredChannel($this->applicant);
            if ($channel) {
                $this->applicant->update([
                    'auto_pilot' => true,
                    'preferred_comms_channel_id' => $channel->id,
                    'owned_by_user_id' => auth()->user()->id,
                ]);
            }
        }

        $this->applicant->refresh();
        $this->applicant->load('preferredCommsChannel');
    }

    public function linkPosting(): void
    {
        $this->postingLinkForm = ['posting_id' => null];
        $this->postingLinkModalShow = true;
    }

    public function savePostingLink(): void
    {
        $this->validate(['postingLinkForm.posting_id' => 'required|exists:rec_postings,id']);
        $this->applicant->postings()->attach($this->postingLinkForm['posting_id'], [
            'applied_at' => now(),
        ]);
        $this->postingLinkModalShow = false;
        $this->applicant->load('postings.position');
        session()->flash('message', 'Ausschreibung verknüpft.');
    }

    public function unlinkPosting(int $postingId): void
    {
        $this->applicant->postings()->detach($postingId);
        $this->applicant->load('postings.position');
        session()->flash('message', 'Ausschreibung-Zuordnung entfernt.');
    }

    public function linkContact(): void
    {
        $this->contactLinkForm = ['contact_id' => null];
        $this->loadAvailableContacts();
        $this->contactLinkModalShow = true;
    }

    public function addContact(): void
    {
        $this->contactForm = [
            'first_name' => '', 'last_name' => '', 'middle_name' => '',
            'nickname' => '', 'birth_date' => '', 'notes' => '',
            'email' => '', 'phone' => '',
        ];
        $this->contactCreateModalShow = true;
    }

    public function saveContactLink(): void
    {
        $this->validate(['contactLinkForm.contact_id' => 'required|exists:crm_contacts,id']);
        $contact = CrmContact::find($this->contactLinkForm['contact_id']);
        $this->applicant->linkContact($contact);
        $this->closeContactLinkModal();
        $this->applicant->load(['crmContactLinks.contact']);
        session()->flash('message', 'Kontakt verknüpft.');
    }

    public function saveContact(): void
    {
        $this->validate([
            'contactForm.first_name' => 'required|string|max:255',
            'contactForm.last_name' => 'required|string|max:255',
            'contactForm.middle_name' => 'nullable|string|max:255',
            'contactForm.nickname' => 'nullable|string|max:255',
            'contactForm.birth_date' => 'nullable|date',
            'contactForm.notes' => 'nullable|string|max:1000',
            'contactForm.email' => 'nullable|email|max:255',
            'contactForm.phone' => 'nullable|string|max:50',
        ]);

        $defaultStatus = CrmContactStatus::where('code', 'ACTIVE')->first();

        $contactData = collect($this->contactForm)->except(['email', 'phone'])->toArray();
        $contact = CrmContact::create(array_merge($contactData, [
            'team_id' => $this->applicant->team_id,
            'created_by_user_id' => auth()->id(),
            'contact_status_id' => $defaultStatus?->id,
        ]));

        // Email-Adresse anlegen
        if (!empty($this->contactForm['email'])) {
            $emailTypeId = \Platform\Crm\Models\CrmEmailType::where('code', 'PRIVATE')->first()?->id;
            if ($emailTypeId) {
                $contact->emailAddresses()->create([
                    'email_address' => $this->contactForm['email'],
                    'email_type_id' => $emailTypeId,
                    'is_primary' => true,
                    'is_active' => true,
                ]);
            }
        }

        // Telefonnummer anlegen
        if (!empty($this->contactForm['phone'])) {
            $phoneTypeId = \Platform\Crm\Models\CrmPhoneType::where('code', 'MOBILE')->first()?->id;
            if ($phoneTypeId) {
                $contact->phoneNumbers()->create([
                    'raw_input' => $this->contactForm['phone'],
                    'international' => $this->contactForm['phone'],
                    'phone_type_id' => $phoneTypeId,
                    'is_primary' => true,
                    'is_active' => true,
                    'whatsapp_status' => \Platform\Crm\Models\CrmPhoneNumber::WHATSAPP_UNKNOWN,
                ]);
            }
        }

        $this->applicant->linkContact($contact);
        $this->closeContactCreateModal();
        $this->applicant->load(['crmContactLinks.contact.emailAddresses', 'crmContactLinks.contact.phoneNumbers']);
        session()->flash('message', 'Kontakt erstellt und verknüpft.');
    }

    public function unlinkContact($contactId): void
    {
        $this->applicant->crmContactLinks()
            ->where('contact_id', $contactId)
            ->delete();
        $this->applicant->load('crmContactLinks.contact');
        session()->flash('message', 'Kontakt-Verknüpfung entfernt.');
    }

    public function closeContactLinkModal(): void
    {
        $this->contactLinkModalShow = false;
        $this->contactLinkForm = ['contact_id' => null];
    }

    public function closeContactCreateModal(): void
    {
        $this->contactCreateModalShow = false;
        $this->contactForm = [
            'first_name' => '', 'last_name' => '', 'middle_name' => '',
            'nickname' => '', 'birth_date' => '', 'notes' => '',
            'email' => '', 'phone' => '',
        ];
    }

    private function loadAvailableContacts(): void
    {
        $linkedContactIds = $this->applicant->crmContactLinks->pluck('contact_id');
        $allowedTeamIds = $this->getAllowedTeamIds($this->applicant->team_id);

        $this->availableContacts = CrmContact::active()
            ->whereIn('team_id', $allowedTeamIds)
            ->whereNotIn('id', $linkedContactIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    #[Computed]
    public function availableWhatsAppTemplates(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }

        $teamSettings = \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam(
            auth()->user()->currentTeam->id
        );
        $accountId = $teamSettings->getSetting('auto_pilot_wa_account_id');

        $query = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->with('whatsappAccount:id,title,phone_number')
            ->where('status', 'APPROVED');

        if ($accountId) {
            $query->where('whatsapp_account_id', (int) $accountId);
        }

        return $query->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => "{$t->name} ({$t->language})",
            ])
            ->toArray();
    }

    public function openTemplateModal(): void
    {
        $this->selectedTemplateId = null;
        $this->templateParams = [];
        $this->templateBodyParamDefs = [];
        $this->templateModalShow = true;
    }

    public function updatedSelectedTemplateId($value): void
    {
        $this->templateParams = [];
        $this->templateBodyParamDefs = [];

        if (!$value) {
            return;
        }

        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($value);
        if (!$template) {
            return;
        }

        $this->templateBodyParamDefs = $this->parseTemplateBodyParams($template->components ?? []);

        // Auto-prefill known parameters from CRM contact
        $contact = $this->applicant->getContact();
        $contactName = $contact?->full_name ?? '';

        foreach ($this->templateBodyParamDefs as $param) {
            $this->templateParams[$param['name']] = match (strtolower($param['name'])) {
                '1', 'name', 'vorname' => $contactName,
                default => '',
            };
        }
    }

    public function sendManualTemplate(): void
    {
        if (!$this->selectedTemplateId) {
            session()->flash('error', 'Bitte ein Template auswählen.');
            return;
        }

        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($this->selectedTemplateId);
        if (!$template || $template->status !== 'APPROVED') {
            session()->flash('error', 'Template nicht gefunden oder nicht genehmigt.');
            return;
        }

        // Find applicant's phone number
        $phoneNumber = $this->findApplicantPhoneNumber();
        if (!$phoneNumber) {
            session()->flash('error', 'Kein Kontakt mit Telefonnummer gefunden. Bitte zuerst einen Kontakt mit Telefonnummer verknüpfen.');
            return;
        }

        // Resolve WhatsApp channel
        $teamSettings = \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam(
            auth()->user()->currentTeam->id
        );
        $accountId = $teamSettings->getSetting('auto_pilot_wa_account_id');
        $channel = $this->resolveWhatsAppChannel($accountId);

        if (!$channel) {
            session()->flash('error', 'Kein aktiver WhatsApp-Kanal konfiguriert. Bitte in den Recruiting-Einstellungen einen WhatsApp-Account auswählen.');
            return;
        }

        // Build components
        $components = [];

        // Body parameters
        if (!empty($this->templateBodyParamDefs)) {
            $bodyParameters = [];
            foreach ($this->templateBodyParamDefs as $param) {
                $paramEntry = [
                    'type' => 'text',
                    'text' => $this->templateParams[$param['name']] ?? '',
                ];
                // Named parameters (non-numeric) need parameter_name for Meta API
                if (!is_numeric($param['name'])) {
                    $paramEntry['parameter_name'] = $param['name'];
                }
                $bodyParameters[] = $paramEntry;
            }
            $components[] = [
                'type' => 'body',
                'parameters' => $bodyParameters,
            ];
        }

        // URL button — check if template has any URL button with a dynamic parameter
        $hasUrlButton = false;
        foreach ($template->components ?? [] as $comp) {
            if (($comp['type'] ?? '') === 'BUTTONS') {
                foreach ($comp['buttons'] ?? [] as $btn) {
                    if (($btn['type'] ?? '') === 'URL') {
                        $hasUrlButton = true;
                        break 2;
                    }
                }
            }
        }

        if ($hasUrlButton) {
            $publicUrl = $this->applicant->getPublicUrl();
            $formToken = basename(parse_url($publicUrl, PHP_URL_PATH));

            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => 0,
                'parameters' => [['type' => 'text', 'text' => $formToken]],
            ];
        }

        try {
            $service = app(WhatsAppMetaService::class);
            $message = $service->sendTemplate(
                channel: $channel,
                to: $phoneNumber->international,
                templateName: $template->name,
                components: $components,
                languageCode: $template->language,
                sender: auth()->user(),
            );

            // Link thread to applicant
            $thread = $message->thread ?? null;
            if ($thread) {
                $thread->addContext($this->applicant->getMorphClass(), $this->applicant->id, 'manual_template');
            }

            $this->templateModalShow = false;
            session()->flash('message', "WhatsApp Template \"{$template->name}\" erfolgreich gesendet.");
        } catch (\Throwable $e) {
            session()->flash('error', 'Fehler beim Senden: ' . $e->getMessage());
        }
    }

    private function parseTemplateBodyParams(array $components): array
    {
        $params = [];
        foreach ($components as $component) {
            if (($component['type'] ?? '') !== 'BODY') {
                continue;
            }

            $text = $component['text'] ?? '';

            // Build example lookup from named params or positional params
            $examplesByName = [];
            $namedParams = $component['example']['body_text_named_params'] ?? [];
            foreach ($namedParams as $np) {
                $examplesByName[$np['param_name']] = $np['example'] ?? '';
            }
            $positionalExamples = $component['example']['body_text'][0] ?? [];

            // Match {{1}}, {{2}}, ... or {{name}} patterns
            preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);

            foreach ($matches[1] as $i => $paramName) {
                $params[] = [
                    'name' => $paramName,
                    'example' => $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '',
                ];
            }
        }
        return $params;
    }

    private function findApplicantPhoneNumber(): ?\Platform\Crm\Models\CrmPhoneNumber
    {
        $this->applicant->loadMissing(['crmContactLinks.contact.phoneNumbers']);

        foreach ($this->applicant->crmContactLinks as $link) {
            $contact = $link->contact;
            if (!$contact) continue;

            $primary = $contact->phoneNumbers
                ->where('is_active', true)
                ->where('is_primary', true)
                ->whereNotNull('international')
                ->first();

            if ($primary) return $primary;

            $fallback = $contact->phoneNumbers
                ->where('is_active', true)
                ->whereNotNull('international')
                ->first();

            if ($fallback) return $fallback;
        }

        return null;
    }

    private function resolveWhatsAppChannel($accountId): ?CommsChannel
    {
        if (!$accountId || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
            return null;
        }

        $account = \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($accountId);
        if (!$account || !$account->active) {
            return null;
        }

        return CommsChannel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('sender_identifier', $account->phone_number)
            ->first();
    }

    public function rendered(): void
    {
        $this->dispatch('extrafields', [
            'context_type' => RecPhase::class,
            'context_id' => null,
        ]);

        $this->dispatch('terminal:app:tags');

        $this->dispatch('terminal:app:files');

        $primaryContact = $this->applicant->crmContactLinks->first()?->contact;
        $subject = 'Bewerbung #' . $this->applicant->id;
        if ($primaryContact) {
            $subject .= ' – ' . $primaryContact->full_name;
        }

        $this->dispatch('comms', [
            'model' => $this->applicant->getMorphClass(),
            'modelId' => $this->applicant->id,
            'subject' => $subject,
            'description' => $this->applicant->notes ?? '',
            'url' => route('recruiting.applicants.show', $this->applicant),
            'source' => 'recruiting.applicant.view',
            'recipients' => [],
            'capabilities' => ['manage_channels' => false, 'threads' => true],
            'meta' => [
                'status' => $this->applicant->applicantStatus?->name,
                'progress' => $this->applicant->progress,
                'applied_at' => $this->applicant->applied_at?->toIso8601String(),
                'is_active' => $this->applicant->is_active,
            ],
        ]);
    }

    public function render()
    {
        return view('recruiting::livewire.applicant.show')
            ->layout('platform::layouts.app');
    }

    private function getAllowedTeamIds(int $teamId): array
    {
        $team = Team::find($teamId);
        if (!$team) {
            return [$teamId];
        }
        return array_merge([$teamId], $team->getAllAncestors()->pluck('id')->all());
    }
}
