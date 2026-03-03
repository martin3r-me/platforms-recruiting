<?php

namespace Platform\Recruiting\Livewire\Position;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Core\Livewire\Concerns\WithExtraFields;
use Illuminate\Support\Facades\Auth;
use Platform\Hcm\Models\HcmJobTitle;
use Platform\Recruiting\Models\RecPosition;

class Show extends Component
{
    use WithExtraFields;

    public RecPosition $position;

    public function mount(RecPosition $position)
    {
        $this->position = $position->load(['postings', 'ownedByUser', 'createdByUser', 'jobTitle']);
        $this->loadExtraFieldValues($this->position);
    }

    public function rules(): array
    {
        return array_merge([
            'position.title' => 'required|string|max:255',
            'position.description' => 'nullable|string',
            'position.department' => 'nullable|string|max:255',
            'position.location' => 'nullable|string|max:255',
            'position.hcm_job_title_id' => 'nullable|exists:hcm_job_titles,id',
            'position.is_active' => 'boolean',
            'position.owned_by_user_id' => 'nullable|exists:users,id',
        ], $this->getExtraFieldValidationRules());
    }

    public function save(): void
    {
        $this->validate();
        $this->position->save();
        $this->saveExtraFieldValues($this->position);
        session()->flash('message', 'Stelle erfolgreich aktualisiert.');
    }

    public function deletePosition(): void
    {
        $this->position->delete();
        session()->flash('message', 'Stelle erfolgreich gelöscht.');
        $this->redirect(route('recruiting.positions.index'), navigate: true);
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
    public function jobTitles()
    {
        return HcmJobTitle::where('team_id', Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($jt) => [
                'id' => $jt->id,
                'name' => $jt->name,
            ]);
    }

    #[Computed]
    public function isDirty()
    {
        return $this->position->isDirty() || $this->isExtraFieldsDirty();
    }

    public function rendered(): void
    {
        $this->dispatch('extrafields', [
            'context_type' => RecPosition::class,
            'context_id' => $this->position->id,
        ]);
    }

    public function render()
    {
        return view('recruiting::livewire.position.show')
            ->layout('platform::layouts.app');
    }
}
