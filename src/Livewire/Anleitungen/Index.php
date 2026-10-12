<?php

namespace Platform\Recruiting\Livewire\Anleitungen;

use Livewire\Component;
use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Uebersicht aller Anleitungen, gruppiert nach Bereich (Spec 2026-10-12). */
class Index extends Component
{
    public function render()
    {
        $katalog = AnleitungKatalog::standard();

        return view('recruiting::livewire.anleitungen.index', [
            'gruppen'    => $katalog->nachBereich(),
            'fehlerhaft' => $katalog->fehlerhaft(),
        ])->layout('platform::layouts.app');
    }
}
