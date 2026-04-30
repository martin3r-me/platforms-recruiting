<?php

namespace Platform\Recruiting\Livewire\Applicant;

use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Services\ImportApplicantsCsvService;

class Index extends Component
{
    use WithFileUploads;

    // Modal State
    public $modalShow = false;

    // CSV-Import-Modal
    public bool $showImportModal = false;
    public $importFile = null;          // Livewire-TemporaryUploadedFile
    public bool $importDryRun = true;
    public ?array $importResult = null; // Stats nach Run
    public bool $importRunning = false;

    // Search & Filters
    public $search = '';
    public $positionFilter = null;
    public $statusFilter = null;
    public $autoPilotStateFilter = null;
    public $activeFilter = null;
    public $activityFilter = null;
    public $appliedFromFilter = null;
    public $appliedToFilter = null;
    public $sourcePlatformFilter = null;

    // Sorting
    public $sortField = 'created_at';
    public $sortDirection = 'desc';

    // Form Data
    public $contact_id = null;
    public $posting_id = null;
    public $rec_applicant_status_id = null;
    public $applied_at = null;
    public $notes = '';

    protected $rules = [
        'posting_id' => 'nullable|exists:rec_postings,id',
        'rec_applicant_status_id' => 'nullable|exists:rec_applicant_statuses,id',
        'applied_at' => 'nullable|date',
        'notes' => 'nullable|string',
    ];

    #[Computed]
    public function applicants()
    {
        $teamId = auth()->user()->currentTeam->id;
        $allowedTeamIds = $this->getAllowedTeamIds($teamId);

        $query = RecApplicant::with([
            'crmContactLinks' => fn ($q) => $q->whereIn('team_id', $allowedTeamIds),
            'crmContactLinks.contact.emailAddresses' => function ($q) {
                $q->active()->orderByDesc('is_primary')->orderBy('id');
            },
            'crmContactLinks.contact.phoneNumbers',
            'applicantStatus',
            'autoPilotState',
            'ownedByUser',
            'postings.position',
            'phase',
        ])->forTeam($teamId)->routed();

        // Search
        if (!empty($this->search)) {
            $searchTerm = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->whereHas('crmContactLinks.contact', function ($contactQuery) use ($searchTerm) {
                    $contactQuery->where('last_name', 'like', $searchTerm)
                        ->orWhere('first_name', 'like', $searchTerm)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$searchTerm]);
                });
            });
        }

        // Position filter
        if ($this->positionFilter) {
            $query->whereHas('postings', function ($q) {
                $q->where('rec_position_id', $this->positionFilter);
            });
        }

        // Activity filter (Tätigkeit am Posting)
        if ($this->activityFilter) {
            $query->whereHas('postings', function ($q) {
                $q->where('activity', $this->activityFilter);
            });
        }

        // Beworben am - Datumsbereich (rec_applicants.applied_at = Erstbewerbungsdatum)
        if ($this->appliedFromFilter) {
            $query->where('applied_at', '>=', $this->appliedFromFilter);
        }
        if ($this->appliedToFilter) {
            $query->where('applied_at', '<=', $this->appliedToFilter);
        }

        // Status filter
        if ($this->statusFilter) {
            $query->where('rec_applicant_status_id', $this->statusFilter);
        }

        // AutoPilot state filter
        if ($this->autoPilotStateFilter) {
            $query->where('auto_pilot_state_id', $this->autoPilotStateFilter);
        }

        // Active filter
        if ($this->activeFilter !== null && $this->activeFilter !== '') {
            $query->where('is_active', (bool) $this->activeFilter);
        }

        // Source platform filter
        if ($this->sourcePlatformFilter) {
            $query->where('source_platform_id', $this->sourcePlatformFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        return $query->get();
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
    public function availablePositions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function availablePostings()
    {
        return RecPosting::with('position')
            ->forTeam(auth()->user()->currentTeam->id)
            ->published()
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function availableActivities()
    {
        return RecPosting::forTeam(auth()->user()->currentTeam->id)
            ->whereNotNull('activity')
            ->where('activity', '!=', '')
            ->distinct()
            ->orderBy('activity')
            ->pluck('activity')
            ->values();
    }

    #[Computed]
    public function availableSourcePlatforms()
    {
        return \Platform\Recruiting\Models\RecSourcePlatform::where('team_id', auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function availableAutoPilotStates()
    {
        return RecAutoPilotState::where(function ($q) {
            $q->whereNull('team_id')
                ->orWhere('team_id', auth()->user()->currentTeam->id);
        })->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function availableContacts()
    {
        $alreadyLinkedContactIds = \Platform\Crm\Models\CrmContactLink::query()
            ->where('linkable_type', 'rec_applicant')
            ->whereHas('linkable', function ($q) {
                $q->where('team_id', auth()->user()->currentTeam->id);
            })
            ->pluck('contact_id');

        return CrmContact::active()
            ->where('team_id', auth()->user()->currentTeam->id)
            ->whereNotIn('id', $alreadyLinkedContactIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function sortBy($field)
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function getAutoPilotColor(RecApplicant $applicant): string
    {
        $code = $applicant->autoPilotState?->code;
        return match ($code) {
            'completed' => 'green',
            'waiting_for_applicant', 'data_collection', 'contact_check' => 'yellow',
            'review_needed' => 'red',
            default => 'gray',
        };
    }

    public function rendered(): void
    {
        $this->dispatch('extrafields', [
            'context_type' => RecPhase::class,
            'context_id' => null,
        ]);
    }

    public function render()
    {
        return view('recruiting::livewire.applicant.index')
            ->layout('platform::layouts.app');
    }

    public function createApplicant()
    {
        $this->validate();

        $applicant = RecApplicant::create([
            'rec_applicant_status_id' => $this->rec_applicant_status_id,
            'applied_at' => $this->applied_at ?: now()->toDateString(),
            'notes' => $this->notes,
            'team_id' => auth()->user()->currentTeam->id,
            'created_by_user_id' => auth()->id(),
            'is_active' => true,
        ]);

        if ($this->contact_id) {
            $contact = CrmContact::find($this->contact_id);
            if ($contact) {
                $applicant->linkContact($contact);
            }
        }

        if ($this->posting_id) {
            $applicant->postings()->attach($this->posting_id, [
                'applied_at' => $this->applied_at,
            ]);
        }

        $this->resetForm();
        $this->modalShow = false;
        session()->flash('message', 'Bewerber erfolgreich erstellt.');
    }

    public function resetForm()
    {
        $this->reset(['contact_id', 'posting_id', 'rec_applicant_status_id', 'applied_at', 'notes']);
    }

    public function openCreateModal()
    {
        $this->resetForm();
        $this->modalShow = true;
    }

    public function closeCreateModal()
    {
        $this->modalShow = false;
        $this->resetForm();
    }

    #[Computed]
    public function whatsAppThreadMap(): array
    {
        $applicantIds = $this->applicants->pluck('id')->unique()->all();
        if (empty($applicantIds)) {
            return [];
        }

        $morphClass = (new RecApplicant)->getMorphClass();
        $fullClass = RecApplicant::class;

        $threads = CommsWhatsAppThread::query()
            ->where(function ($q) use ($morphClass, $fullClass, $applicantIds) {
                $q->where(function ($q2) use ($morphClass, $applicantIds) {
                    $q2->where('context_model', $morphClass)
                        ->whereIn('context_model_id', $applicantIds);
                })->orWhere(function ($q2) use ($fullClass, $applicantIds) {
                    $q2->where('context_model', $fullClass)
                        ->whereIn('context_model_id', $applicantIds);
                });
            })
            ->get();

        $map = [];
        foreach ($threads as $thread) {
            $oid = $thread->context_model_id;
            if (!isset($map[$oid]) || ($thread->last_inbound_at && $thread->last_inbound_at > ($map[$oid]->last_inbound_at ?? null))) {
                $map[$oid] = $thread;
            }
        }

        $threadIds = collect($map)->pluck('id')->all();
        if (!empty($threadIds)) {
            $allMessages = \Platform\Crm\Models\CommsWhatsAppMessage::query()
                ->whereIn('comms_whatsapp_thread_id', $threadIds)
                ->select(['id', 'comms_whatsapp_thread_id', 'direction', 'body', 'sent_at'])
                ->orderByDesc('sent_at')
                ->get()
                ->groupBy('comms_whatsapp_thread_id');

            foreach ($map as $oid => $thread) {
                $msgs = ($allMessages->get($thread->id) ?? collect())->take(2)->reverse()->values();
                $thread->setRelation('recentMessages', $msgs);
            }
        }

        return $map;
    }

    public function getWhatsAppStatus(RecApplicant $applicant): array
    {
        $phoneNumber = null;
        $whatsappStatus = CrmPhoneNumber::WHATSAPP_UNKNOWN;

        foreach ($applicant->crmContactLinks as $link) {
            foreach ($link->contact?->phoneNumbers ?? [] as $phone) {
                if (!$phone->is_active) continue;
                $phoneNumber = $phone->international ?: $phone->raw_input;
                $whatsappStatus = $phone->whatsapp_status ?? CrmPhoneNumber::WHATSAPP_UNKNOWN;
                if ($whatsappStatus !== CrmPhoneNumber::WHATSAPP_UNKNOWN) {
                    break 2;
                }
            }
        }

        if (!$phoneNumber) {
            return ['color' => 'none', 'status' => 'no_phone', 'window_open' => false, 'last_message' => null, 'recent_messages' => []];
        }

        $isWhatsAppAvailable = in_array($whatsappStatus, [
            CrmPhoneNumber::WHATSAPP_AVAILABLE,
            CrmPhoneNumber::WHATSAPP_OPTED_IN,
        ]);

        if (!$isWhatsAppAvailable) {
            return [
                'color' => 'gray',
                'status' => $whatsappStatus,
                'window_open' => false,
                'last_message' => null,
                'recent_messages' => [],
            ];
        }

        $windowOpen = false;
        $thread = $this->whatsAppThreadMap[$applicant->id] ?? null;

        $lastMessage = null;
        $recentMessages = [];
        if ($thread) {
            if ($thread->isWindowOpen()) {
                $windowOpen = true;
            }
            $lastMessage = $thread->last_message_preview;

            $recentMessages = ($thread->recentMessages ?? collect())
                ->map(fn ($m) => [
                    'direction' => $m->direction,
                    'body' => Str::limit($m->body ?? '', 60),
                    'at' => $m->sent_at?->format('d.m. H:i'),
                ])
                ->values()
                ->all();
        }

        return [
            'color' => $windowOpen ? 'green' : 'yellow',
            'status' => $whatsappStatus,
            'window_open' => $windowOpen,
            'last_message' => $lastMessage,
            'recent_messages' => $recentMessages,
        ];
    }

    private function getAllowedTeamIds(int $teamId): array
    {
        $team = Team::find($teamId);
        if (!$team) {
            return [$teamId];
        }
        return array_merge([$teamId], $team->getAllAncestors()->pluck('id')->all());
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->importDryRun = true;
        $this->importResult = null;
        $this->importRunning = false;
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        $this->showImportModal = false;
        $this->importFile = null;
        $this->importResult = null;
        $this->importRunning = false;
    }

    public function runImport(ImportApplicantsCsvService $service): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt|max:10240', // 10 MB
        ]);

        $teamId = (int) auth()->user()->current_team_id;
        if ($teamId <= 0) {
            session()->flash('error', 'Kein aktives Team gefunden.');
            return;
        }

        $this->importRunning = true;
        try {
            $path = $this->importFile->getRealPath();
            $this->importResult = $service->importFromFile($path, $teamId, $this->importDryRun);
        } catch (\Throwable $e) {
            $this->importResult = [
                'parsed'           => 0,
                'imported'         => 0,
                'skipped_dup'      => 0,
                'skipped_existing' => 0,
                'skipped_incompl'  => 0,
                'errors'           => [],
                'fatal'            => 'Unerwarteter Fehler: ' . $e->getMessage(),
            ];
        } finally {
            $this->importRunning = false;
        }

        // Liste nach erfolgreichem echten Run aktualisieren
        if (!$this->importDryRun && empty($this->importResult['fatal']) && ($this->importResult['imported'] ?? 0) > 0) {
            unset($this->applicants);
        }
    }
}
