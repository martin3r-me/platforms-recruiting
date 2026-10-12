<?php

namespace Platform\Recruiting\Livewire\Anleitungen;

use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Eine Anleitung als Folien-Karten mit Inhaltsverzeichnis (Spec 2026-10-12). */
class Show extends Component
{
    #[Locked]
    public string $slug = '';

    public function mount(string $slug): void
    {
        abort_if(AnleitungKatalog::standard()->finde($slug) === null, 404);
        $this->slug = $slug;
    }

    public function render()
    {
        return view('recruiting::livewire.anleitungen.show', [
            'anleitung' => AnleitungKatalog::standard()->finde($this->slug),
        ])->layout('platform::layouts.app');
    }
}
