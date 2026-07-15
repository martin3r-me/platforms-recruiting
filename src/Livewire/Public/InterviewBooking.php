<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\WaitlistEnrollmentPlanner;

class InterviewBooking extends Component
{
    public string $publicToken = '';
    public string $state = 'loading';
    public ?int $applicantId = null;
    public string $applicantName = '';
    public ?int $teamId = null;

    public function mount(string $publicToken): void
    {
        $this->publicToken = $publicToken;

        // Lookup via CorePublicFormLink — gleiches Pattern wie
        // ContractSigning und ApplicantPortal. Linkable wird per morph
        // aufgeloest, instanceof-Check umgeht morph-map-Konfiguration
        // (linkable_type ist als Kurzkey 'rec_applicant' gespeichert,
        // nicht als vollqualifizierter Klassenname).
        $link = CorePublicFormLink::where('token', $publicToken)->first();

        if (!$link) {
            $this->state = 'notFound';
            return;
        }

        if (!$link->isValid()) {
            $this->state = 'notActive';
            return;
        }

        $applicant = $link->linkable;

        if (!$applicant instanceof RecApplicant) {
            $this->state = 'notFound';
            return;
        }

        if (!$applicant->is_active) {
            $this->state = 'notActive';
            return;
        }

        $contact = $applicant->getContact();
        $this->applicantName = $contact->full_name ?? 'Bewerber';
        $this->applicantId = $applicant->id;
        $this->teamId = $applicant->team_id;

        // Kein eigener "waitlisted"-State: Buchen läuft IMMER über die normale
        // Auswahl. Ob jemand auf der Warteliste steht, leitet die Empty-Box am
        // Render aus waitlistEntry ab — so kann der gespeicherte State nie mit
        // der echten Verfügbarkeit auseinanderlaufen.
        $this->state = $this->existingBooking ? 'booked' : 'selection';
    }

    #[Computed]
    public function existingBooking(): ?RecInterviewBooking
    {
        if (!$this->applicantId) {
            return null;
        }

        return RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->with('interview')
            ->first();
    }

    #[Computed]
    public function waitlistEnabled(): bool
    {
        if (!$this->applicantId) {
            return false;
        }
        $applicant = RecApplicant::with('phase')->find($this->applicantId);
        $config = $applicant?->phase?->completion_config ?? [];
        return ($config['waitlist_enabled'] ?? false) === true;
    }

    #[Computed]
    public function waitlistEntry(): ?RecInterviewWaitlist
    {
        if (!$this->applicantId) {
            return null;
        }
        // Nur Ort-Einträge: Termin-Einträge (rec_interview_id gesetzt)
        // haben eigene UI-Zustände an der Termin-Karte und dürfen die
        // Empty-Box nicht als "steht auf der Warteliste" erscheinen lassen.
        return RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->ortBased()
            ->open()
            ->first();
    }

    /**
     * Offene Termin-Warteliste-Einträge des Bewerbers, keyed by
     * rec_interview_id — fürs Blade (Glocken-Zustand pro Termin-Karte).
     */
    #[Computed]
    public function interviewWaitlistEntries(): array
    {
        if (!$this->applicantId) {
            return [];
        }

        return RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->whereNotNull('rec_interview_id')
            ->open()
            ->get()
            ->keyBy('rec_interview_id')
            ->all();
    }

    #[Computed]
    public function visibleInterviews(): array
    {
        if (!$this->applicantId) {
            return [];
        }

        $applicant = RecApplicant::with('postings.position', 'phase')->find($this->applicantId);
        if (!$applicant) {
            return [];
        }

        $positionIds = $this->resolvePositionIdsForApplicant($applicant);

        if (empty($positionIds)) {
            return [];
        }

        // Volle Termine bleiben sichtbar (Badge "Ausgebucht" + Termin-
        // Warteliste-Glocke im Blade) — deshalb KEIN Kapazitäts-Filter
        // mehr. Zeit-/Status-/Aktiv-Filter unverändert: vergangene oder
        // abgesagte Termine sieht ein Bewerber weiterhin nie.
        return RecInterview::forTeam($this->teamId)
            ->with('position')
            ->active()
            ->where('starts_at', '>', now())
            ->whereIn('status', ['planned', 'confirmed'])
            ->whereIn('rec_position_id', $positionIds)
            ->withCount(['bookings' => function ($query) {
                $query->whereNotIn('status', ['cancelled']);
            }])
            ->get()
            ->sortBy('starts_at')
            ->values()
            ->all();
    }

    /**
     * Resolves the list of position IDs whose interviews the applicant is
     * allowed to see.
     *
     * Multi-Standort-Logik:
     *  - Committed (in Phase >=3 oder hat aktives Booking): nur primary-Stelle
     *  - Sonst: Wunsch-Mapping (`beschaftigungsort` → Stelle via Mapping-Spalte)
     *           plus primary als Fallback
     *
     * Falls Mapping nirgends gepflegt ist, fällt der Filter auf den heutigen
     * Effekt zurück (primary-Stelle = ihre Termine).
     */
    private function resolvePositionIdsForApplicant(RecApplicant $applicant): array
    {
        $primaryId = $applicant->postings->first()?->rec_position_id;

        $isCommitted = ($applicant->phase?->order ?? 0) >= 3
            || RecInterviewBooking::where('rec_applicant_id', $applicant->id)
                ->whereNotIn('status', ['cancelled'])
                ->exists();

        if ($isCommitted) {
            return $primaryId ? [$primaryId] : [];
        }

        $wunschOrte = $applicant->getExtraField('beschaftigungsort') ?? [];
        if (!is_array($wunschOrte)) {
            $wunschOrte = [$wunschOrte];
        }
        $wunschOrte = array_filter($wunschOrte, fn ($v) => $v !== null && $v !== '');

        $wunschPositionIds = collect();
        if (!empty($wunschOrte)) {
            // Cut-Over-Schutz: alte ('% bis %' im Titel) und neue Stellen
            // duerfen sich nicht im Wunsch-Match vermischen. Sonst wuerde
            // ein alter Bewerber (in P1/P2 ohne Booking) eine neue Stelle
            // sehen — Buchung darauf laesst Phase-Modell auseinanderlaufen.
            $primaryTitle = $applicant->postings->first()?->position?->title ?? '';
            $primaryIsLegacy = str_contains($primaryTitle, ' bis ');

            $query = RecPosition::forTeam($applicant->team_id)
                ->whereIn('beschaftigungsort_lookup_value', $wunschOrte)
                ->where('is_active', true);

            if ($primaryIsLegacy) {
                $query->where('title', 'like', '% bis %');
            } else {
                $query->where('title', 'not like', '% bis %');
            }

            $wunschPositionIds = $query->pluck('id');
        }

        return $wunschPositionIds
            ->push($primaryId)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function bookInterview(int $interviewId): void
    {
        $applicant = RecApplicant::find($this->applicantId);
        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }

        // Check no active booking exists
        $hasActive = RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($hasActive) {
            return;
        }

        $interview = RecInterview::forTeam($this->teamId)
            ->active()
            ->where('starts_at', '>', now())
            ->whereIn('status', ['planned', 'confirmed'])
            ->find($interviewId);

        if (!$interview) {
            return;
        }

        // Capacity check
        if ($interview->max_participants) {
            $currentCount = RecInterviewBooking::where('rec_interview_id', $interviewId)
                ->whereNotIn('status', ['cancelled'])
                ->count();

            if ($currentCount >= $interview->max_participants) {
                unset($this->visibleInterviews);
                return;
            }
        }

        // Status 'booked' (NEU mit Schritt 3): Initial-Status fuer eine
        // frische Buchung. Wird beim Phase-3-Hook auf 'registered'
        // hochgestuft (sofern die Phase confirm_booking_on_completion=true
        // setzt). Reminder-Ja-Antwort hebt dann auf 'confirmed'.
        // Cancelled-Felder werden explizit zurueckgesetzt fuer den Fall
        // dass die Row via updateOrCreate auf einer alten cancelled-Buchung
        // landet — sonst bleiben Storno-Metadaten an einer wieder aktiven
        // Buchung haengen.
        RecInterviewBooking::updateOrCreate(
            [
                'rec_interview_id' => $interviewId,
                'rec_applicant_id' => $this->applicantId,
            ],
            [
                'status'        => 'booked',
                'booked_at'     => now(),
                'team_id'       => $this->teamId,
                'cancelled_by'  => null,
                'cancelled_at'  => null,
            ],
        );

        // Optional: Stellen-Wechsel falls die aktuelle Phase es erlaubt
        $this->maybeSwitchPosition($applicant, $interview);

        // Bucht der Bewerber, ist seine Warteliste-Anfrage erfüllt.
        RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->open()
            ->update(['fulfilled_at' => now()]);

        unset($this->existingBooking, $this->visibleInterviews, $this->waitlistEntry, $this->interviewWaitlistEntries);
        $this->state = 'booked';
    }

    public function joinWaitlist(): void
    {
        $applicant = RecApplicant::with(['phase', 'postings.position'])->find($this->applicantId);
        if (!$applicant || !$this->waitlistEnabled) {
            return;
        }

        // Snapshot der bestätigten Wunschorte — gleiche Quelle wie
        // resolvePositionIdsForApplicant() (beschaftigungsort-Extra-Field),
        // Fallback auf den Ort der primären Stelle.
        $wunschOrte = WaitlistEnrollmentPlanner::resolveWunschorte(
            $applicant->getExtraField('beschaftigungsort'),
            $applicant->postings->first()?->position?->beschaftigungsort_lookup_value,
        );

        $entry = $this->waitlistEntry;
        $plan = WaitlistEnrollmentPlanner::plan(
            $entry ? [
                'notified'   => $entry->notified_at !== null,
                'wunschorte' => $entry->wunschorte ?? [],
            ] : null,
            $wunschOrte,
        );

        if ($plan['action'] === 'create') {
            RecInterviewWaitlist::create([
                'rec_applicant_id' => $applicant->id,
                'team_id'          => $applicant->team_id,
                'wunschorte'       => $plan['wunschorte'],
                'enrolled_at'      => now(),
            ]);
        } elseif ($plan['action'] === 'rearm') {
            // Verbrauchten Eintrag wieder scharf schalten: nur notified_at
            // und Snapshot — enrolled_at bleibt das ursprüngliche
            // Eintragedatum ("wartet seit" für HR).
            $entry->update([
                'notified_at' => null,
                'wunschorte'  => $plan['wunschorte'],
            ]);
        }

        // State bleibt 'selection'; die Empty-Box rendert aus dem frischen
        // waitlistEntry den passenden Zustand.
        unset($this->waitlistEntry);
    }

    public function joinInterviewWaitlist(int $interviewId): void
    {
        $applicant = RecApplicant::with(['phase', 'postings.position'])->find($this->applicantId);
        if (!$applicant || !$this->waitlistEnabled) {
            return;
        }

        // Check no active booking exists — gleicher Guard wie bookInterview():
        // wer eine aktive Buchung hat, wartet nirgendwo mehr (die UI bietet
        // den Button dann eh nicht an; das hier sichert den Wire-Pfad ab).
        $hasActive = RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($hasActive) {
            return;
        }

        // Gleiche Server-Validierung wie bookInterview(): Team, aktiv,
        // Zukunft, buchbarer Status. Zusätzlich: nur für VOLLE Termine —
        // ist noch Platz, soll der Bewerber buchen statt warten.
        $interview = RecInterview::forTeam($this->teamId)
            ->active()
            ->where('starts_at', '>', now())
            ->whereIn('status', ['planned', 'confirmed'])
            ->find($interviewId);

        if (!$interview || !$interview->max_participants) {
            return;
        }

        $booked = RecInterviewBooking::where('rec_interview_id', $interviewId)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        if ($booked < $interview->max_participants) {
            unset($this->visibleInterviews);
            return;
        }

        $entry = $this->interviewWaitlistEntries[$interviewId] ?? null;
        $plan = WaitlistEnrollmentPlanner::planForInterview(
            $entry ? ['notified' => $entry->notified_at !== null] : null
        );

        if ($plan['action'] === 'create') {
            // Wunschorte-Snapshot nur als HR-Info — das Matching läuft
            // über rec_interview_id, deshalb ist auch [] okay.
            RecInterviewWaitlist::create([
                'rec_applicant_id' => $applicant->id,
                'rec_interview_id' => $interviewId,
                'team_id'          => $applicant->team_id,
                'wunschorte'       => WaitlistEnrollmentPlanner::resolveWunschorte(
                    $applicant->getExtraField('beschaftigungsort'),
                    $applicant->postings->first()?->position?->beschaftigungsort_lookup_value,
                ),
                'enrolled_at'      => now(),
            ]);
        } elseif ($plan['action'] === 'rearm') {
            $entry->update(['notified_at' => null]);
        }

        unset($this->interviewWaitlistEntries);
    }

    /**
     * Wechselt den Bewerber zur Buchungs-Stelle, wenn:
     *  - die aktuelle Phase `completion_config.switch_position_on_booking = true` hat
     *  - der Bewerber noch in Phase order <= 2 ist (Schutz vor Datenverlust)
     *  - die Buchungs-Stelle und seine aktuelle Stelle beide gemappt sind
     *  - die Buchungs-Stelle != aktuelle primary
     */
    private function maybeSwitchPosition(RecApplicant $applicant, RecInterview $interview): void
    {
        $applicant->loadMissing('phase', 'postings.position');

        $config = $applicant->phase?->completion_config ?? [];
        $switchEnabled = ($config['switch_position_on_booking'] ?? false) === true;
        if (!$switchEnabled) {
            return;
        }

        $currentOrder = $applicant->phase?->order ?? 99;
        if ($currentOrder > 2) {
            return; // Phase 3+ → Schutz vor Datenverlust
        }

        $bookedPosition = $interview->position;
        if (!$bookedPosition) {
            return;
        }

        $primaryPosition = $applicant->primaryPosition();
        if (!$primaryPosition || $primaryPosition->id === $bookedPosition->id) {
            return; // Schon in der richtigen Stelle
        }

        // Mapping-Schutz: beide Stellen müssen einen Lookup-Wert haben
        if (empty($bookedPosition->beschaftigungsort_lookup_value)
            || empty($primaryPosition->beschaftigungsort_lookup_value)) {
            return;
        }

        $applicant->switchToPosition($bookedPosition);
    }

    public function cancelAndRebook(): void
    {
        // Cancel ALL non-cancelled bookings for this applicant (not just the first one)
        // cancelled_by='applicant' weil Bewerber aktiv umbucht (kein HR-Eingriff).
        // Model-Updates (kein Query-Builder), damit der Waitlist-Observer den
        // frei werdenden Platz mitbekommt.
        RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->get()
            ->each->update([
                'status'        => 'cancelled',
                'cancelled_by'  => 'applicant',
                'cancelled_at'  => now(),
            ]);

        // Force fresh computed values on next access
        unset($this->existingBooking, $this->visibleInterviews);
        $this->state = 'selection';
    }

    /**
     * Bewerber sagt die Schulung dauerhaft ab (nicht nur umbuchen). Anders
     * als cancelAndRebook landet er danach NICHT im selection-State sondern
     * in einem cancelled-State + wird auf HR-Schreibtisch geroutet.
     */
    public function cancelSchulung(): void
    {
        $applicant = RecApplicant::find($this->applicantId);
        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }

        // 1) Aktive Buchungen einsammeln BEVOR wir sie cancellen — wir brauchen
        //    Termin + Ort fuer die HR-Schreibtisch-Notes. Bei mehreren aktiven
        //    Buchungen nehmen wir die naechste (frueheste starts_at) als
        //    Referenz fuer den Notes-Kontext.
        $activeBookings = RecInterviewBooking::with('interview')
            ->where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $referenceInterview = $activeBookings
            ->map(fn ($b) => $b->interview)
            ->filter()
            ->sortBy('starts_at')
            ->first();

        // 2) Alle aktiven Buchungen cancellen mit Quellen-Info — als
        //    Model-Updates, damit der Waitlist-Observer den frei werdenden
        //    Platz mitbekommt.
        $activeBookings->each->update([
            'status'        => 'cancelled',
            'cancelled_by'  => 'applicant',
            'cancelled_at'  => now(),
        ]);

        // 3) Notes-Kontext fuer den HR-Schreibtisch-Case zusammenbauen
        $notes = 'Bewerber hat die Schulung uber den Public-Form-Link abgesagt.';
        if ($referenceInterview) {
            $dateLabel = $referenceInterview->starts_at?->format('d.m.Y H:i') ?? '—';
            $location = trim((string) ($referenceInterview->location ?? ''));
            $notes = "Schulung am {$dateLabel}"
                . ($location !== '' ? " in {$location}" : '')
                . ' wurde vom Bewerber uber den Public-Form-Link abgesagt.';
        }

        // Bewerber will keine Schulung mehr → offene Warteliste-Anfrage schließen.
        RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->open()
            ->update(['cancelled_at' => now()]);

        // 4) HR-Schreibtisch-Case anlegen + Flag setzen ueber den zentralen
        //    Service. Idempotent: existiert schon ein offener Case fuer den
        //    gleichen Reason (z.B. Bewerber sagt zweimal hintereinander ab),
        //    wird kein Duplicate angelegt.
        app(HrDeskRoutingService::class)->routeIfNotAlreadyOpen(
            $applicant,
            RecHrDeskCase::REASON_APPLICANT_CANCELLED_TRAINING,
            null,
            $notes
        );

        // 3) Zusaetzlicher AutoPilotLog mit semantischem Type fuer die
        //    Bewerber-Timeline (Service-Log ist generisch 'hr_desk_routed').
        try {
            \Platform\Recruiting\Models\RecAutoPilotLog::create([
                'rec_applicant_id' => $applicant->id,
                'type'             => 'cancelled_by_applicant',
                'summary'           => 'Bewerber hat die Schulung aktiv ueber den Public-Form-Link abgesagt.',
            ]);
        } catch (\Throwable) {
            // Log-Fehler darf den Cancel nicht blockieren
        }

        unset($this->existingBooking, $this->visibleInterviews, $this->waitlistEntry, $this->interviewWaitlistEntries);
        $this->state = 'cancelled';
    }

    public function render()
    {
        return view('recruiting::livewire.public.interview-booking')
            ->layout('platform::layouts.guest');
    }
}
