<?php

namespace Platform\Recruiting\Livewire\Concerns;

use Livewire\Attributes\Computed;

/**
 * Das Bewertungs-Modal der Schulungsnachbereitung — geteilt zwischen der
 * grossen Buchungsliste (HR/Schulungsleiter) und der schlanken
 * Teamleiter-Ansicht (14.09.2026).
 *
 * Warum geteilt und nicht kopiert: Die Bewertung schreibt je nach Phase auf
 * den Bewerber ODER auf rec_employee_hr_data (Phasenregel Spec §4) und ist
 * die Quelle des Schulungszertifikats. Zwei Kopien dieser Regel wuerden
 * auseinanderlaufen, und der Unterschied faellt erst auf, wenn ein Zertifikat
 * falsche Werte traegt.
 *
 * VERTRAG an die nutzende Komponente: sie muss eine Computed `bookings`
 * anbieten, deren Elemente `id`, `status` und `applicant` tragen. Beim
 * Speichern wird `unset($this->bookings, $this->evaluationValues)` gerufen.
 */
trait HandlesEvaluationModal
{
    public bool $showEvaluationModal = false;
    public ?int $evaluateBookingId = null;

    /**
     * Bewertungs-Modal: die acht Felder aus Spec §1. Ziel ist hrData, wenn ein
     * Mitarbeiter existiert, sonst der Bewerber (Phasenregel §4).
     */
    public array $evaluation = [
        'rating_erscheinungsbild' => null,
        'rating_fachkompetenz'    => null,
        'rating_auffassungsgabe'  => null,
        'rating_auftreten'        => null,
        'rating_teamintegration'  => null,
        'evaluation_note'         => null,
        'linen_package_items'     => [],
        'qualifications'          => [],
    ];

    /**
     * LESESEITE der Phasenregel (Spec §4): die acht Bewertungsfelder aller
     * sichtbaren Bewerber, einmal pro Render aufgeloest.
     *
     * Quelle: hrData, wenn ein Mitarbeiter existiert, sonst der Bewerber.
     * Beides ist bereits eager geladen (Spec F12) — diese Property macht
     * KEINE Query.
     *
     * WICHTIG: hier NICHT ensureHrData() benutzen. Das ist ein firstOrCreate,
     * also ein Schreibzugriff — im Render-Pfad wuerde das blosse Betrachten der
     * Tabelle hrData-Rows erzeugen, einmal pro Zeile. Existiert ein Mitarbeiter
     * ohne hrData-Row, werden hier eben keine Werte geliefert. Angelegt wird die
     * Row ausschliesslich beim Speichern (evaluationWriteTarget).
     *
     * @return array<int, array<string, mixed>>  applicant_id => Feldwerte
     */
    #[Computed]
    public function evaluationValues(): array
    {
        $result = [];

        foreach ($this->bookings as $booking) {
            $applicant = $booking->applicant;
            if (!$applicant) {
                continue;
            }

            $source = $applicant->employee?->hrData ?? $applicant;

            $values = [];
            foreach (\Platform\Recruiting\Support\EvaluationValues::FIELDS as $field) {
                $values[$field] = $source->{$field};
            }

            $result[$applicant->id] = $values;
        }

        return $result;
    }

    /**
     * SCHREIBSEITE der Phasenregel (Spec §4): existiert ein Mitarbeiter, ist
     * hrData die Schreibseite, sonst der Bewerber.
     *
     * Kein Dual-Write: HR pflegt dieselben Felder auf der Mitarbeiterkarte und
     * schreibt dort nur hrData. Wuerden wir beide Seiten schreiben, schoebe das
     * Modal spaeter den alten Bewerber-Wert ueber HRs Korrektur.
     *
     * Hier ist ensureHrData() richtig: der Aufruf erfolgt nur bei einer
     * bewussten Speicher-Aktion, nicht im Render.
     */
    public function evaluationWriteTarget($applicant)
    {
        if (!$applicant) {
            return null;
        }

        $employee = $applicant->employee;

        return $employee ? $employee->ensureHrData() : $applicant;
    }

    public function openEvaluationModal(int $bookingId): void
    {
        $booking = $this->bookings->firstWhere('id', $bookingId);

        if (!\Platform\Recruiting\Support\EvaluationAvailability::isOpen($booking?->status)) {
            session()->flash('error', 'Bewertung erst möglich, wenn die Teilnahme bestätigt ist.');
            return;
        }

        // Null-Safe, obwohl der Guard oben bei $booking === null schon ausgestiegen
        // ist: lokal zugesichert statt an die Reihenfolge zweier Guards gekoppelt.
        $applicant = $booking?->applicant;
        if (!$applicant) {
            session()->flash('error', 'Bewerber nicht gefunden.');
            return;
        }

        // Vorbelegung aus der LESESEITE — legt keine hrData-Row an.
        $values = $this->evaluationValues[$applicant->id] ?? [];

        $this->evaluateBookingId = $bookingId;
        $this->evaluation = [
            'rating_erscheinungsbild' => isset($values['rating_erscheinungsbild']) ? (string) $values['rating_erscheinungsbild'] : null,
            'rating_fachkompetenz'    => isset($values['rating_fachkompetenz']) ? (string) $values['rating_fachkompetenz'] : null,
            'rating_auffassungsgabe'  => isset($values['rating_auffassungsgabe']) ? (string) $values['rating_auffassungsgabe'] : null,
            'rating_auftreten'        => isset($values['rating_auftreten']) ? (string) $values['rating_auftreten'] : null,
            'rating_teamintegration'  => isset($values['rating_teamintegration']) ? (string) $values['rating_teamintegration'] : null,
            'evaluation_note'         => $values['evaluation_note'] ?? null,
            'linen_package_items'     => is_array($values['linen_package_items'] ?? null) ? $values['linen_package_items'] : [],
            'qualifications'          => is_array($values['qualifications'] ?? null) ? $values['qualifications'] : [],
        ];
        $this->showEvaluationModal = true;
    }

    public function closeEvaluationModal(): void
    {
        $this->showEvaluationModal = false;
        $this->evaluateBookingId = null;
        $this->evaluation = [
            'rating_erscheinungsbild' => null,
            'rating_fachkompetenz'    => null,
            'rating_auffassungsgabe'  => null,
            'rating_auftreten'        => null,
            'rating_teamintegration'  => null,
            'evaluation_note'         => null,
            'linen_package_items'     => [],
            'qualifications'          => [],
        ];
    }

    public function saveEvaluation(): void
    {
        if (!$this->evaluateBookingId) {
            return;
        }

        $booking = $this->bookings->firstWhere('id', $this->evaluateBookingId);

        // Server-seitiger Doppel-Schutz: das Modal kann nur ueber attended
        // geoeffnet werden, aber die Methode ist public und ueber das
        // Wire-Protokoll direkt aufrufbar.
        if (!\Platform\Recruiting\Support\EvaluationAvailability::isOpen($booking?->status)) {
            session()->flash('error', 'Bewertung erst möglich, wenn die Teilnahme bestätigt ist.');
            $this->closeEvaluationModal();
            return;
        }

        // SCHREIBSEITE — hier darf ensureHrData() die Row anlegen.
        $target = $this->evaluationWriteTarget($booking?->applicant);
        if (!$target) {
            session()->flash('error', 'Bewerber nicht mehr vorhanden.');
            $this->closeEvaluationModal();
            return;
        }

        foreach (\Platform\Recruiting\Support\RatingCriteria::columns() as $column) {
            $target->{$column} = \Platform\Recruiting\Support\EvaluationValues::normalizeStar($this->evaluation[$column] ?? null);
        }

        $note = trim((string) ($this->evaluation['evaluation_note'] ?? ''));
        $target->evaluation_note = $note === '' ? null : $note;

        $target->linen_package_items = \Platform\Recruiting\Support\EvaluationValues::normalizeList($this->evaluation['linen_package_items'] ?? null);
        $target->qualifications      = \Platform\Recruiting\Support\EvaluationValues::normalizeList($this->evaluation['qualifications'] ?? null);

        $target->save();

        session()->flash('success', 'Bewertung gespeichert.');
        $this->closeEvaluationModal();
        unset($this->bookings, $this->evaluationValues);
    }

    public function contactCandidatesFor($applicant): array
    {
        $candidates = [];
        foreach (($applicant?->crmContactLinks ?? []) as $link) {
            $candidates[] = [
                'contact_id' => $link->contact_id,
                'first_name' => $link->contact?->first_name,
                'last_name'  => $link->contact?->last_name,
                'full_name'  => $link->contact?->full_name,
            ];
        }

        return $candidates;
    }
}
