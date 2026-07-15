<?php

namespace Platform\Recruiting\Livewire\Waitlist;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecInterviewWaitlist;

class Index extends Component
{
    public ?string $selectedOrt = null;

    private function teamId(): int
    {
        return auth()->user()->currentTeam->id;
    }

    /**
     * Zähler pro Ort: ein Wartender zählt in jedem seiner Wunschorte.
     */
    #[Computed]
    public function countsByOrt(): array
    {
        $counts = [];
        // Nur Ort-Einträge: Termin-Wartende warten auf einen konkreten
        // Termin, nicht auf "irgendeinen Termin am Ort" — sie würden die
        // Ort-Nachfrage-Zähler verfälschen.
        RecInterviewWaitlist::forTeam($this->teamId())->open()->ortBased()->get()
            ->each(function (RecInterviewWaitlist $entry) use (&$counts) {
                foreach (($entry->wunschorte ?? []) as $ort) {
                    $counts[$ort] = ($counts[$ort] ?? 0) + 1;
                }
            });
        arsort($counts);
        return $counts;
    }

    #[Computed]
    public function entries()
    {
        $query = RecInterviewWaitlist::forTeam($this->teamId())->open()
            ->with(['applicant', 'interview'])
            ->orderBy('enrolled_at');

        if ($this->selectedOrt) {
            $query->whereJsonContains('wunschorte', $this->selectedOrt);
        }

        return $query->get();
    }

    public function selectOrt(?string $ort): void
    {
        $this->selectedOrt = $ort;
    }

    public function render()
    {
        return view('recruiting::livewire.waitlist.index')
            ->layout('platform::layouts.app');
    }
}
