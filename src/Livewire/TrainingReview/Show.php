<?php

namespace Platform\Recruiting\Livewire\TrainingReview;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Livewire\Concerns\HandlesEvaluationModal;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Support\DefaultContractTemplateAssignment;

/**
 * Die schlanke Nachbereitung fuer Teamleiter-Konten (Kundenwunsch 14.09.2026).
 *
 * Wird eine grosse Schulung in zwei Gruppen geteilt, betreut ein Teamleiter
 * die zweite Gruppe und soll sie bewerten koennen. Beide Leiter sehen dieselbe
 * Teilnehmerliste und sprechen sich ab, wer welche Zeile anfasst — harte
 * Gruppen gibt es bewusst nicht.
 *
 * DIESE KOMPONENTE KANN GENAU DREI DINGE: Anwesenheit setzen, bewerten,
 * Klaerung an HR schicken.
 *
 * Sie ist deshalb eine eigene Komponente und kein Satz @if in der grossen
 * Buchungsliste: Dort haengen Lohnfelder, Vertragsvorlage, Empfehlungen und
 * sechs Wege zum Vertragsversand. Jedes @if waere eine Stelle, an der ein
 * spaeter hinzugefuegter Knopf versehentlich fuer Teamleiter sichtbar wird.
 * Hier gilt die umgekehrte Fehlerrichtung: Was nicht drinsteht, kann nicht
 * durchrutschen — es gibt schlicht keine Methode dafuer.
 *
 * NICHTS hier versendet Vertraege oder Portallinks. Wer das aendern will,
 * bringt den Test TrainingReviewHasNoDispatchTest zu Fall, und das ist Absicht.
 */
class Show extends Component
{
    use HandlesEvaluationModal;

    public $interviewId;

    /** Klaerung an HR: Notiz + Name der Person am Sammelkonto. */
    public bool $showClarifyModal = false;
    public ?int $clarifyBookingId = null;
    public string $clarifyNotes = '';
    public string $clarifyReporter = '';

    /** Anwesenheit: bewusst OHNE 'cancelled' — Stornos macht hier niemand. */
    private const ERLAUBTE_STATUS = ['confirmed', 'attended', 'no_show', 'rejected_on_site'];

    public function mount($interview): void
    {
        $this->interviewId = is_object($interview) ? $interview->id : $interview;
    }

    #[Computed]
    public function interview()
    {
        return RecInterview::with('interviewType')->findOrFail($this->interviewId);
    }

    /**
     * Vertrag des Bewertungs-Traits: id, status und applicant pro Zeile.
     * Bewusst schmaler geladen als die grosse Liste — kein contracts, kein
     * legalStatus, kein posting. Was hier nicht ankommt, kann auch nicht
     * versehentlich angezeigt werden.
     */
    #[Computed]
    public function bookings()
    {
        return RecInterviewBooking::where('rec_interview_id', $this->interviewId)
            ->where('status', '!=', 'cancelled')
            ->with([
                'applicant.crmContactLinks.contact',
                'applicant.employee:id,rec_applicant_id',
                'applicant.employee.hrData',
            ])
            ->orderBy('booked_at', 'desc')
            ->get();
    }

    #[Computed]
    public function defaultContractTemplate()
    {
        return RecContractTemplate::where('code', 'AV-default')->where('is_active', true)->first();
    }

    /**
     * Anwesenheit setzen. Loest KEINEN Versand aus — bei 'attended' wird nur
     * die Standardvorlage zugewiesen, dieselbe Regel wie in der grossen
     * Nachbereitung (DefaultContractTemplateAssignment). Der Vertragsversand
     * ist ueberall ein eigener, bewusster Klick, den es hier nicht gibt.
     */
    public function setAttendance(int $bookingId, string $status): void
    {
        if (!in_array($status, self::ERLAUBTE_STATUS, true)) {
            return;
        }

        $booking = $this->bookings->firstWhere('id', $bookingId);
        if (!$booking) {
            return;
        }

        $booking->update(['status' => $status]);

        if ($status === 'attended') {
            $applicant = $booking->applicant;
            $neu = DefaultContractTemplateAssignment::resolve(
                $applicant?->contract_template_id ? (int) $applicant->contract_template_id : null,
                $this->defaultContractTemplate?->id,
            );
            if ($applicant && $neu !== null) {
                $applicant->contract_template_id = $neu;
                $applicant->save();
            }
        }

        unset($this->bookings, $this->evaluationValues);
        session()->flash('success', 'Anwesenheit gespeichert.');
    }

    public function openClarifyModal(int $bookingId): void
    {
        $this->clarifyBookingId = $bookingId;
        $this->clarifyNotes = '';
        $this->showClarifyModal = true;
    }

    public function closeClarifyModal(): void
    {
        $this->showClarifyModal = false;
        $this->clarifyBookingId = null;
        $this->clarifyNotes = '';
    }

    /**
     * Klaerung an HR — derselbe Fall wie aus der grossen Nachbereitung
     * (REASON_TRAINING_CLARIFICATION), idempotent ueber routeIfNotAlreadyOpen:
     * druecken zwei Teamleiter auf dieselbe Person, entsteht nur ein Fall.
     *
     * Der Name wandert in die Notiz, WEIL das Konto ein Sammelkonto ist.
     * Ohne ihn stuende bei HR nur "event@rheingedeck.de" und niemand wuesste,
     * wen man zurueckrufen muss.
     */
    public function submitClarification(): void
    {
        $this->validate([
            'clarifyReporter' => 'required|string|min:2',
            'clarifyNotes'    => 'required|string|min:3',
        ], [
            'clarifyReporter.required' => 'Bitte den eigenen Namen eintragen.',
            'clarifyReporter.min'      => 'Bitte den eigenen Namen eintragen.',
            'clarifyNotes.required'    => 'Bitte kurz notieren, was HR klären soll.',
            'clarifyNotes.min'         => 'Bitte kurz notieren, was HR klären soll.',
        ]);

        $booking = $this->bookings->firstWhere('id', $this->clarifyBookingId);
        $applicant = $booking?->applicant;
        if (!$applicant) {
            return;
        }

        app(HrDeskRoutingService::class)->routeIfNotAlreadyOpen(
            $applicant,
            RecHrDeskCase::REASON_TRAINING_CLARIFICATION,
            auth()->id(),
            trim($this->clarifyReporter) . ' (Teamleiter): ' . trim($this->clarifyNotes),
        );

        $this->closeClarifyModal();
        unset($this->bookings);
        session()->flash('success', 'Zur Klärung an den HR-Schreibtisch übergeben.');
    }

    public function render()
    {
        return view('recruiting::livewire.training-review.show')
            ->layout('platform::layouts.app');
    }
}
