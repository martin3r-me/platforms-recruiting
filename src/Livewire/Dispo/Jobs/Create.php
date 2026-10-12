<?php

namespace Platform\Recruiting\Livewire\Dispo\Jobs;

use Livewire\Attributes\Url;
use Livewire\Component;

/** Platzhalter — wird in Task 10 gefuellt. */
class Create extends Component
{
    #[Url]
    public ?int $event = null;

    public function render()
    {
        return view('recruiting::livewire.dispo.jobs._platzhalter')->layout('platform::layouts.app');
    }
}
