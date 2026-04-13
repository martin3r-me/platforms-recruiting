<?php

namespace Platform\Recruiting\Livewire\InterviewTypes;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecInterviewType;

class Index extends Component
{
    public $showCreateModal = false;
    public $showEditModal = false;

    public $editingId = null;
    public $name = '';
    public $code = '';
    public $description = '';
    public $sort_order = 0;
    public $is_active = true;

    protected $rules = [
        'name' => 'required|string|max:255',
        'code' => 'nullable|string|max:20',
        'description' => 'nullable|string',
        'sort_order' => 'integer|min:0',
        'is_active' => 'boolean',
    ];

    public function render()
    {
        return view('recruiting::livewire.interview-types.index', [
            'items' => $this->interviewTypes,
        ])->layout('platform::layouts.app');
    }

    #[Computed]
    public function interviewTypes()
    {
        return RecInterviewType::where('team_id', auth()->user()->currentTeam->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showCreateModal = true;
    }

    public function openEditModal(int $id): void
    {
        $m = RecInterviewType::findOrFail($id);
        $this->editingId = $m->id;
        $this->name = $m->name;
        $this->code = $m->code;
        $this->description = $m->description;
        $this->sort_order = $m->sort_order;
        $this->is_active = $m->is_active;
        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'team_id' => auth()->user()->currentTeam->id,
        ];

        if ($this->editingId) {
            $m = RecInterviewType::findOrFail($this->editingId);
            $m->update($data);
            session()->flash('success', 'Gesprächsart erfolgreich aktualisiert!');
        } else {
            $data['created_by_user_id'] = auth()->id();
            RecInterviewType::create($data);
            session()->flash('success', 'Gesprächsart erfolgreich erstellt!');
        }

        $this->closeModals();
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $m = RecInterviewType::findOrFail($id);

        if ($m->interviews()->count() > 0) {
            session()->flash('error', 'Gesprächsart kann nicht gelöscht werden, da noch Termine zugeordnet sind!');
            return;
        }

        $m->delete();
        session()->flash('success', 'Gesprächsart erfolgreich gelöscht!');
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
        $this->name = '';
        $this->code = '';
        $this->description = '';
        $this->sort_order = 0;
        $this->is_active = true;
    }
}
