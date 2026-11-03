<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Component;
use Platform\Recruiting\Jobs\ProcessZasDispoInboundFile;
use Platform\Recruiting\Models\RecZasDispoInboundFile;

/**
 * Disposition → ZAS-Eingang: Liste der eingegangenen Dispo-Dateien.
 *
 * Bewusst ungescoped (Tabelle ist team-los, siehe Migration) und ohne
 * Paginierung (Modul-Konvention, Handvoll Dateien pro Tag).
 */
class Index extends Component
{
    public function render()
    {
        $files = RecZasDispoInboundFile::orderByDesc('created_at')->get();

        return view('recruiting::livewire.dispo.index', [
            'files' => $files,
            // Grenze fuer „in Warteschlange“ vs. „liegengeblieben“ (Spec 2026-11-04).
            'letzteVerarbeitete' => ProcessZasDispoInboundFile::letzteVerarbeitete(),
        ])
            ->layout('platform::layouts.app');
    }
}
