<?php

namespace Platform\Recruiting\Livewire\Posting;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPosition;

class Show extends Component
{
    public RecPosting $posting;
    public string $description = '';

    public function mount(RecPosting $posting)
    {
        $this->posting = $posting->load(['position', 'applicants.crmContactLinks.contact']);
        $this->description = $posting->description ?? '';
    }

    public function rules(): array
    {
        return [
            'posting.title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'posting.status' => 'required|in:draft,published,closed',
            'posting.is_active' => 'boolean',
            'posting.published_at' => 'nullable|date',
            'posting.closes_at' => 'nullable|date',
        ];
    }

    public function save(): void
    {
        $this->validate();
        $this->posting->description = $this->description;
        $this->posting->save();
        session()->flash('message', 'Ausschreibung erfolgreich aktualisiert.');
    }

    public function publish(): void
    {
        $this->posting->status = 'published';
        $this->posting->published_at = now();
        $this->posting->save();
        session()->flash('message', 'Ausschreibung veröffentlicht.');
    }

    public function close(): void
    {
        $this->posting->status = 'closed';
        $this->posting->save();
        session()->flash('message', 'Ausschreibung geschlossen.');
    }

    public function deletePosting(): void
    {
        $this->posting->delete();
        session()->flash('message', 'Ausschreibung gelöscht.');
        $this->redirect(route('recruiting.postings.index'), navigate: true);
    }

    #[Computed]
    public function isDirty()
    {
        return $this->posting->isDirty()
            || $this->description !== ($this->posting->getOriginal('description') ?? '');
    }

    public function render()
    {
        return view('recruiting::livewire.posting.show')
            ->layout('platform::layouts.app');
    }
}
