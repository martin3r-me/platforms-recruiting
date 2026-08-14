<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
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
    public string $token = '';
    public ?int $employeeId = null;
    public string $firstName = '';
    public bool $tokenInvalid = false;

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
        if ($this->employeeId === null) {
            return [];
        }

        $settings = RecApplicantSettings::getOrCreateForTeam((int) config('recruiting.zas.inbound_team_id'));
        $deadlineHours = (int) ($settings->getSetting('dispo_deadline_hours') ?? 4);
        $contactLine   = $settings->getSetting('dispo_contact_line');

        $assignments = RecDispoAssignment::query()
            ->with('event')
            ->where('rec_employee_id', $this->employeeId)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNull('deletion_marked_at')
            ->whereDate('datum', '>=', now()->toDateString())
            ->orderBy('datum')->orderBy('von')
            ->get();

        $groups = [];
        foreach ($assignments as $assignment) {
            $event = $assignment->event;
            $key = $event->id;

            $groups[$key] ??= [
                'event_id'     => $event->id,
                'name'         => $event->name ?? $event->einsatz_ref,
                'venue_text'   => $event->venue_text ?? $event->ort,
                'anfahrt'      => $event->anfahrt,
                'dresscode'    => $event->dresscode,
                'contact_line' => $contactLine,
                'all_confirmed' => true,
                'days'         => [],
            ];

            $arrival = DispoTimeCalculator::arrivalTime($assignment->von, $event->vorlauf_minuten);
            $groups[$key]['days'][] = [
                'datum'      => $assignment->datum->format('d.m.Y'),
                'von'        => $assignment->von,
                'bis'        => $assignment->bis,
                'taetigkeit' => $assignment->taetigkeit,
                'arrival'    => $arrival,
                'confirmed'  => $assignment->confirmed_at !== null,
                'deadline'   => \Carbon\Carbon::parse(
                    DispoTimeCalculator::confirmationDeadline($assignment->datum->format('Y-m-d'), $assignment->von, $deadlineHours)
                )->format('d.m.Y H:i'),
            ];
            if ($assignment->confirmed_at === null) {
                $groups[$key]['all_confirmed'] = false;
            }
        }

        return array_values($groups);
    }

    /** Sammel-Bestaetigen: ALLE kommenden Auftrags-Einsaetze dieser VA (idempotent). */
    public function confirm(int $eventId): void
    {
        if ($this->employeeId === null) {
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
