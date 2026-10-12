<?php

namespace Platform\Recruiting\Livewire\Dispo\Jobs;

use Livewire\Attributes\Locked;
use Livewire\Component;

/** Platzhalter — wird in Task 10 gefuellt. */
class Show extends Component
{
    #[Locked]
    public int $requestId = 0;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.jobs._platzhalter')->layout('platform::layouts.app');
    }
}
