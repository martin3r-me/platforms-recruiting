<?php

namespace Platform\Recruiting\Livewire\InterviewSchedule;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecEventLocation;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewType;
use Platform\Recruiting\Models\RecPosition;

class Index extends Component
{
    public $search = '';
    public $filterType = '';
    public $filterPosition = '';
    public $filterStatus = 'all';

    public $showCreateModal = false;
    public $showEditModal = false;
    public $editingId = null;

    public $title = '';
    public $description = '';
    public $interview_type_id = '';
    public $rec_position_id = '';
    public $location = '';
    public $starts_at = '';
    public $ends_at = '';
    public $min_participants = null;
    public $max_participants = '';
    public $status = 'planned';
    public $language = 'de';
    public $is_active = true;
    public $selectedInterviewers = [];
    public $reminder_wa_template_id = '';
    public $reminder_hours_before = null;
    public $reminder_wa_template_variables = [];

    /** Selected event-location id; '' = freier Eingabe-Modus, '0' = (nicht genutzt) */
    public $selectedEventLocationId = '';

    protected $rules = [
        'title' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'interview_type_id' => 'nullable|integer|exists:rec_interview_types,id',
        'rec_position_id' => 'nullable|integer|exists:rec_positions,id',
        'location' => 'nullable|string|max:255',
        'starts_at' => 'required|date',
        'ends_at' => 'nullable|date|after_or_equal:starts_at',
        'min_participants' => 'nullable|integer|min:0',
        'max_participants' => 'nullable|integer|min:0',
        'status' => 'required|in:planned,confirmed,cancelled,completed',
        'language' => 'required|in:de,en',
        'is_active' => 'boolean',
        'selectedInterviewers' => 'array',
        'reminder_wa_template_id' => 'nullable|integer',
        'reminder_hours_before' => 'nullable|integer|min:1',
        'reminder_wa_template_variables' => 'array',
    ];

    public function render()
    {
        return view('recruiting::livewire.interview-schedule.index')
            ->layout('platform::layouts.app');
    }

    #[Computed]
    public function interviews()
    {
        return RecInterview::where('team_id', auth()->user()->currentTeam->id)
            ->when($this->search, function ($q) {
                $q->where(function ($query) {
                    $query->where('title', 'like', '%' . $this->search . '%')
                        ->orWhere('location', 'like', '%' . $this->search . '%')
                        ->orWhereHas('interviewType', fn($q) => $q->where('name', 'like', '%' . $this->search . '%'))
                        ->orWhereHas('position', fn($q) => $q->where('title', 'like', '%' . $this->search . '%'));
                });
            })
            ->when($this->filterType, fn($q) => $q->where('interview_type_id', $this->filterType))
            ->when($this->filterPosition, fn($q) => $q->where('rec_position_id', $this->filterPosition))
            ->when($this->filterStatus !== 'all', fn($q) => $q->where('status', $this->filterStatus))
            ->with(['interviewType', 'position', 'interviewers', 'bookings'])
            ->orderBy('starts_at', 'desc')
            ->get();
    }

    #[Computed]
    public function interviewTypes()
    {
        return RecInterviewType::where('team_id', auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function positions()
    {
        return RecPosition::where('team_id', auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function availableWhatsAppTemplates()
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return collect();
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::where('status', 'APPROVED')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedTemplateInfo()
    {
        if (!$this->reminder_wa_template_id) {
            return null;
        }

        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return null;
        }

        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($this->reminder_wa_template_id);
        if (!$template) {
            return null;
        }

        $components = $template->components ?? [];
        $bodyText = '';
        $hasUrlButton = false;

        foreach ($components as $comp) {
            if (strtolower((string) ($comp['type'] ?? '')) === 'body') {
                $bodyText = (string) ($comp['text'] ?? '');
            }
            if (($comp['type'] ?? '') === 'BUTTONS') {
                foreach ($comp['buttons'] ?? [] as $btn) {
                    if (($btn['type'] ?? '') === 'URL' && str_contains($btn['url'] ?? '', '{{')) {
                        $hasUrlButton = true;
                    }
                }
            }
        }

        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $numMatches);
        preg_match_all('/\{\{(\w+)\}\}/', $bodyText, $namedMatches);
        $bodyVarCount = !empty($numMatches[1]) ? (int) max($numMatches[1]) : count(array_unique($namedMatches[1] ?? []));

        $paramLabels = [];
        foreach ($components as $comp) {
            if (strtolower((string) ($comp['type'] ?? '')) === 'body' && isset($comp['example']['body_text_named_params'])) {
                foreach ($comp['example']['body_text_named_params'] as $i => $param) {
                    $paramLabels[$i + 1] = $param['param_name'] ?? "Variable " . ($i + 1);
                }
            }
        }

        return [
            'body_text' => $bodyText,
            'body_var_count' => $bodyVarCount,
            'has_url_button' => $hasUrlButton,
            'param_labels' => $paramLabels,
        ];
    }

    public function updatedReminderWaTemplateId(): void
    {
        unset($this->selectedTemplateInfo);
    }

    #[Computed]
    public function teamUsers()
    {
        return auth()->user()->currentTeam->users()->orderBy('name')->get();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEditModal(int $id): void
    {
        $m = RecInterview::with('interviewers')->findOrFail($id);
        $this->editingId = $m->id;
        $this->title = $m->title ?? '';
        $this->description = $m->description ?? '';
        $this->interview_type_id = $m->interview_type_id ?? '';
        $this->rec_position_id = $m->rec_position_id ?? '';
        $this->location = $m->location ?? '';
        $this->starts_at = $m->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->ends_at = $m->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->min_participants = $m->min_participants;
        $this->max_participants = $m->max_participants;
        $this->status = $m->status;
        $this->language = $m->language ?? 'de';
        $this->is_active = $m->is_active;
        $this->selectedInterviewers = $m->interviewers->pluck('id')->toArray();
        $this->reminder_wa_template_id = $m->reminder_wa_template_id ?? '';
        $this->reminder_hours_before = $m->reminder_hours_before;
        $this->reminder_wa_template_variables = $m->reminder_wa_template_variables ?? [];

        // Match existing location string against known event-locations so the
        // dropdown reflects the current selection. Free-text/unknown stays as ''.
        $this->selectedEventLocationId = '';
        if ($m->location) {
            $match = RecEventLocation::forTeam($m->team_id)
                ->where('full_address', $m->location)
                ->first();
            if ($match) {
                $this->selectedEventLocationId = (string) $match->id;
            }
        }

        $this->showEditModal = true;
    }

    /**
     * When the user picks a location from the dropdown, copy its full_address
     * into the location field. Empty selection leaves the location untouched
     * (free-text mode).
     */
    public function updatedSelectedEventLocationId($value): void
    {
        if ($value === '' || $value === null) {
            return;
        }
        $teamId = (int) auth()->user()->currentTeam->id;
        $loc = RecEventLocation::forTeam($teamId)->find((int) $value);
        if ($loc) {
            $this->location = $loc->full_address;
        }
    }

    #[Computed]
    public function availableEventLocations()
    {
        return RecEventLocation::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['id', 'label', 'full_address']);
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'title' => $this->title ?: null,
            'description' => $this->description ?: null,
            'interview_type_id' => $this->interview_type_id ?: null,
            'rec_position_id' => $this->rec_position_id ?: null,
            'location' => $this->location ?: null,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at ?: null,
            'min_participants' => $this->min_participants,
            'max_participants' => $this->max_participants !== null && $this->max_participants !== '' ? (int) $this->max_participants : null,
            'status' => $this->status,
            'language' => $this->language,
            'is_active' => $this->is_active,
            'reminder_wa_template_id' => $this->reminder_wa_template_id ?: null,
            'reminder_hours_before' => $this->reminder_hours_before,
            'reminder_wa_template_variables' => $this->reminder_wa_template_id ? array_filter($this->reminder_wa_template_variables) : null,
            'team_id' => auth()->user()->currentTeam->id,
        ];

        if ($this->editingId) {
            $m = RecInterview::findOrFail($this->editingId);
            $m->update($data);
            $m->interviewers()->sync($this->selectedInterviewers);
            session()->flash('success', 'Termin erfolgreich aktualisiert!');
        } else {
            $data['created_by_user_id'] = auth()->id();
            $m = RecInterview::create($data);
            $m->interviewers()->sync($this->selectedInterviewers);
            session()->flash('success', 'Termin erfolgreich erstellt!');
        }

        $this->closeModals();
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $m = RecInterview::findOrFail($id);
        $m->delete();
        session()->flash('success', 'Termin erfolgreich gelöscht!');
    }

    public function closeModals(): void
    {
        $this->showCreateModal = false;
        $this->showEditModal = false;
        $this->editingId = null;
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->title = '';
        $this->description = '';
        $this->interview_type_id = '';
        $this->rec_position_id = '';
        $this->location = '';
        $this->starts_at = '';
        $this->ends_at = '';
        $this->min_participants = null;
        $this->max_participants = '';
        $this->status = 'planned';
        $this->language = 'de';
        $this->is_active = true;
        $this->selectedInterviewers = [];
        $this->reminder_wa_template_id = '';
        $this->reminder_hours_before = null;
        $this->reminder_wa_template_variables = [];
        $this->selectedEventLocationId = '';
    }
}
