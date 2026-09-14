<?php

namespace Platform\Recruiting\Livewire\TrainingReview;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecInterview;

/**
 * Schulungsliste der Teamleiter-Ansicht (Kundenwunsch 14.09.2026).
 *
 * Bewusst KEIN Filter auf "meine Schulungen": das Konto ist ein Sammelkonto
 * fuer mehrere Teamleiter (event@rheingedeck.de) und bei keiner Schulung als
 * Schulungsleiter eingetragen — ein solcher Filter haette immer eine leere
 * Liste ergeben. Stattdessen ein Zeitfenster, damit das Konto nicht die
 * gesamte Historie aufgeblaettert bekommt.
 *
 * Diese Seite zeigt NUR an. Anlegen, Aendern und Loeschen von Schulungen
 * gibt es hier nicht.
 */
class Index extends Component
{
    /** Wie weit zurueck Schulungen sichtbar bleiben. */
    private const WOCHEN_RUECKWIRKEND = 4;

    #[Computed]
    public function interviews()
    {
        return RecInterview::query()
            ->where('starts_at', '>=', now()->subWeeks(self::WOCHEN_RUECKWIRKEND)->startOfDay())
            ->withCount(['bookings as teilnehmer_count' => function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            }])
            ->with('interviewType')
            ->orderBy('starts_at', 'desc')
            ->get();
    }

    public function render()
    {
        return view('recruiting::livewire.training-review.index')
            ->layout('platform::layouts.app');
    }
}
