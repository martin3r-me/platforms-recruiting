<?php

namespace Platform\Recruiting\Livewire\Posting;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPosition;

class Index extends Component
{
    public $modalShow = false;
    public $search = '';
    public $statusFilter = '';

    // Form
    public $rec_position_id = null;
    public $title = '';
    public $description = '';

    protected $rules = [
        'rec_position_id' => 'required|exists:rec_positions,id',
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
    ];

    #[Computed]
    public function postings()
    {
        $query = RecPosting::query()
            ->with(['position'])
            ->forTeam(auth()->user()->currentTeam->id);

        if (!empty($this->search)) {
            $searchTerm = '%' . $this->search . '%';
            $query->where('title', 'like', $searchTerm);
        }

        if (!empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        }

        return $query->orderByDesc('created_at')->get();
    }

    #[Computed]
    public function availablePositions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->orderBy('title')
            ->get();
    }

    public function createPosting()
    {
        $this->validate();

        RecPosting::create([
            'rec_position_id' => $this->rec_position_id,
            'title' => $this->title,
            'description' => $this->description,
            'team_id' => auth()->user()->currentTeam->id,
            'created_by_user_id' => auth()->id(),
            'status' => 'draft',
            'is_active' => true,
        ]);

        $this->resetForm();
        $this->modalShow = false;
        session()->flash('message', 'Ausschreibung erfolgreich erstellt.');
    }

    public function resetForm()
    {
        $this->reset(['rec_position_id', 'title', 'description']);
    }

    public function openCreateModal()
    {
        $this->resetForm();
        $this->modalShow = true;
    }

    public function render()
    {
        return view('recruiting::livewire.posting.index')
            ->layout('platform::layouts.app');
    }
}
