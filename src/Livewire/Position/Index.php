<?php

namespace Platform\Recruiting\Livewire\Position;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecPosition;

class Index extends Component
{
    public $modalShow = false;
    public $search = '';
    public $sortField = 'created_at';
    public $sortDirection = 'desc';

    // Form
    public $title = '';
    public $description = '';
    public $department = '';
    public $location = '';

    protected $rules = [
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'department' => 'nullable|string|max:255',
        'location' => 'nullable|string|max:255',
    ];

    #[Computed]
    public function positions()
    {
        $query = RecPosition::query()
            ->withCount('postings')
            ->forTeam(auth()->user()->currentTeam->id);

        if (!empty($this->search)) {
            $searchTerm = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', $searchTerm)
                    ->orWhere('department', 'like', $searchTerm)
                    ->orWhere('location', 'like', $searchTerm);
            });
        }

        return $query->orderBy($this->sortField, $this->sortDirection)->get();
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

    public function createPosition()
    {
        $this->validate();

        RecPosition::create([
            'title' => $this->title,
            'description' => $this->description,
            'department' => $this->department,
            'location' => $this->location,
            'team_id' => auth()->user()->currentTeam->id,
            'created_by_user_id' => auth()->id(),
            'is_active' => true,
        ]);

        $this->resetForm();
        $this->modalShow = false;
        session()->flash('message', 'Stelle erfolgreich erstellt.');
    }

    public function resetForm()
    {
        $this->reset(['title', 'description', 'department', 'location']);
    }

    public function openCreateModal()
    {
        $this->resetForm();
        $this->modalShow = true;
    }

    public function render()
    {
        return view('recruiting::livewire.position.index')
            ->layout('platform::layouts.app');
    }
}
