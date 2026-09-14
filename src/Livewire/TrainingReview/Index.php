<?php

namespace Platform\Recruiting\Livewire\TrainingReview;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Support\InterviewPastness;

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

    /** Ergebnis der EINEN Abfrage, pro Request gemerkt — sonst laeuft sie je Gruppe erneut. */
    private $loadedInterviews = null;

    #[Computed]
    public function interviews()
    {
        return $this->loadedInterviews ??= RecInterview::query()
            ->where('starts_at', '>=', now()->subWeeks(self::WOCHEN_RUECKWIRKEND)->startOfDay())
            ->withCount(['bookings as teilnehmer_count' => function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            }])
            ->with('interviewType')
            ->orderBy('starts_at', 'desc')
            ->get();
    }

    /**
     * Anstehende Schulungen — offen. Eine laufende zaehlt dazu, siehe
     * InterviewPastness.
     */
    #[Computed]
    public function upcomingInterviews()
    {
        return $this->interviews->reject(fn ($i) => $this->istVorbei($i))->values();
    }

    /**
     * Vergangene Schulungen — eingeklappt, damit die Liste nicht mit
     * Altbestand zulaeuft. Gleiche Darstellung wie in der Terminuebersicht.
     */
    #[Computed]
    public function pastInterviews()
    {
        return $this->interviews->filter(fn ($i) => $this->istVorbei($i))->values();
    }

    private function istVorbei(RecInterview $interview): bool
    {
        return InterviewPastness::isPast($interview->starts_at, $interview->ends_at, now());
    }

    public function render()
    {
        return view('recruiting::livewire.training-review.index')
            ->layout('platform::layouts.app');
    }
}
