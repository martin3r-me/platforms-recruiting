<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoAttachment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator;

/**
 * Oeffentliche Einsatz-Seite (Dispo-Bestaetigung), token-only.
 *
 * BEWUSST ohne die Verifikations-Schranke des EmployeePortal (Spec):
 * unerratbarer UUIDv7-Token, Link kommt privat per WhatsApp, Seite zeigt
 * nur Einsatz-Infos und kennt nur die positive Aktion Bestaetigen.
 * Nirgends im MA-Portal verlinkt. Token am URL-Ende (Meta-URL-Button).
 */
class EmployeeAssignments extends Component
{
    #[Locked]
    public string $token = '';
    #[Locked]
    public ?int $employeeId = null;
    #[Locked]
    public string $firstName = '';
    #[Locked]
    public bool $tokenInvalid = false;

    /**
     * Server-only-Sperrflag (Eskalations-Stufe 3, DispoEmployeeGateway::
     * lockPortal), analog EmployeePortal::$portalLocked. #[Locked] verhindert
     * Client-Ueberschreiben; wird ausschliesslich aus rec_employees.
     * portal_locked_at gesetzt. Zeigt bei true den Sperr-Screen statt der
     * Einsatz-Liste und gated confirm().
     */
    #[Locked]
    public bool $portalLocked = false;

    public bool $showPast = false;

    public function mount(string $token): void
    {
        $this->token = $token;

        $employee = RecEmployee::query()->where('portal_token', $token)->first();
        if (!$employee) {
            $this->tokenInvalid = true;
            return;
        }

        $this->employeeId = (int) $employee->id;
        $this->firstName  = (string) $employee->first_name;

        if ($employee->portal_locked_at !== null) {
            $this->portalLocked = true;
        }
    }

    /**
     * Kommende Auftrags-Einsaetze des MA, gruppiert pro VA — EINE Karte pro VA
     * mit allen Tagen und Sammel-Bestaetigen (Spec). #[Computed]: nie im State.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function eventGroups(): array
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return [];
        }

        $assignments = RecDispoAssignment::query()
            ->with('event')
            ->where('rec_employee_id', $this->employeeId)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNull('deletion_marked_at')
            ->whereDate('datum', '>=', now()->toDateString())
            ->orderBy('datum')->orderBy('von')
            ->get();

        $attachments = RecDispoAttachment::query()
            ->where('rec_employee_id', $this->employeeId)
            ->whereIn('rec_dispo_event_id', $assignments->pluck('rec_dispo_event_id')->unique()->all())
            ->get()
            ->keyBy('rec_dispo_event_id');

        $groups = [];
        foreach ($assignments as $assignment) {
            $event = $assignment->event;
            $key = $event->id;

            $groups[$key] ??= [
                'event_id'     => $event->id,
                'name'         => $event->name ?? $event->einsatz_ref,
                'adresse'      => $event->venue_text,
                'zusatz_ort'   => $event->ort,
                'kleidung'     => $event->dresscode,
                'contact_line' => $event->ansprechpartner,
                'vorlauf_minuten' => (int) ($event->vorlauf_minuten ?? 0),
                'attachment'   => isset($attachments[$event->id]) ? [
                    'name' => (string) $attachments[$event->id]->original_filename,
                    'url'  => route('recruiting.public.employee-assignments.attachment', ['token' => $this->token, 'uuid' => $attachments[$event->id]->uuid]),
                ] : null,
                'all_confirmed' => true,
                'days'         => [],
            ];

            $arrival = DispoTimeCalculator::arrivalTime($assignment->von, $event->vorlauf_minuten);
            $groups[$key]['days'][] = [
                'datum'           => $assignment->datum->format('d.m.Y'),
                'von'             => $assignment->von,
                'bis'             => $assignment->bis,
                'taetigkeit'      => $assignment->taetigkeit,
                'arrival'         => $arrival,
                'confirmed'       => $assignment->confirmed_at !== null,
                'individual_note' => $assignment->individual_note,
            ];
            if ($assignment->confirmed_at === null) {
                $groups[$key]['all_confirmed'] = false;
            }
        }

        return array_values($groups);
    }

    /**
     * Vergangene Auftrags-Einsaetze des MA, kompakt gruppiert pro VA (nur Anzeige,
     * eingeklappt per Default). Kein Filter auf missing/deletion_marked — vergangen
     * ist vergangen. #[Computed]: nie im State.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function pastEventGroups(): array
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return [];
        }

        $assignments = RecDispoAssignment::query()
            ->with('event')
            ->where('rec_employee_id', $this->employeeId)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereDate('datum', '<', now()->toDateString())
            ->orderByDesc('datum')->orderByDesc('von')
            ->limit(30)
            ->get();

        $groups = [];
        foreach ($assignments as $assignment) {
            $event = $assignment->event;
            $key = $event->id;

            $groups[$key] ??= [
                'name' => $event->name ?? $event->einsatz_ref,
                'days' => [],
            ];

            $groups[$key]['days'][] = [
                'datum'     => $assignment->datum->format('d.m.Y'),
                'confirmed' => $assignment->confirmed_at !== null,
            ];
        }

        return array_values($groups);
    }

    /** Sammel-Bestaetigen: ALLE kommenden Auftrags-Einsaetze dieser VA (idempotent). */
    public function confirm(int $eventId): void
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return;
        }

        // Frisch pruefen — die Sperre kann nach mount() gesetzt worden sein
        // (Eskalations-Cron laeuft unabhaengig vom Request); ohne diesen
        // Re-Check koennte ein bereits offener Tab die Sperre umgehen.
        if (RecEmployee::query()->whereKey($this->employeeId)->whereNotNull('portal_locked_at')->exists()) {
            $this->portalLocked = true;
            return;
        }

        RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $eventId)
            ->where('rec_employee_id', $this->employeeId)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNull('deletion_marked_at')
            ->whereNull('confirmed_at')
            ->whereDate('datum', '>=', now()->toDateString())
            ->update(['confirmed_at' => now()]);

        unset($this->eventGroups);
    }

    public function render()
    {
        return view('recruiting::livewire.public.employee-assignments')
            ->layout('platform::layouts.guest');
    }
}
