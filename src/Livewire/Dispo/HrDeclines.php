<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Dispo-Absagen auf dem HR-Schreibtisch (Kunde 04.09.): eigener Abschnitt,
 * bewusst GETRENNT von den Bewerber-Cases — Dispo-MA sind RecEmployees aus
 * der ZAS-Welt und haben meist keinen Bewerber-Datensatz. Gespeist aus den
 * Absage-Feldern an den Einbuchungen (declined_hr_at gesetzt, noch nicht
 * erledigt), gruppiert pro Person+VA. "Erledigt" stempelt alle Zeilen der
 * Gruppe — nichts wird geloescht, die Historie bleibt an der Einbuchung.
 */
class HrDeclines extends Component
{
    /**
     * @return list<array{event_id:int, employee_id:int, name:string, pnr:string, event_label:string,
     *   days:list<string>, reason:?string, note:?string, declined_at:?string, locked:bool}>
     */
    #[Computed]
    public function declines(): array
    {
        $rows = RecDispoAssignment::query()
            ->whereNotNull('declined_hr_at')
            ->whereNull('declined_hr_done_at')
            ->with(['employee', 'event'])
            ->orderByDesc('declined_at')
            ->get();

        $groups = [];
        foreach ($rows as $a) {
            $key = $a->rec_dispo_event_id . '-' . $a->rec_employee_id;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'event_id'    => (int) $a->rec_dispo_event_id,
                    'employee_id' => (int) $a->rec_employee_id,
                    'name'        => $a->employee ? trim($a->employee->first_name . ' ' . $a->employee->last_name) : ('PNr ' . $a->pnr_raw),
                    'pnr'         => (string) ($a->employee->personnel_number ?? $a->pnr_raw ?? ''),
                    'event_label' => (string) ($a->event->name ?? $a->event->einsatz_ref ?? '—'),
                    'days'        => [],
                    'reason'      => $a->declined_reason,
                    'note'        => $a->declined_note,
                    'declined_at' => $a->declined_at?->format('d.m.Y H:i'),
                    'locked'      => (bool) $a->declined_portal_locked,
                ];
            }
            $groups[$key]['days'][] = $a->datum->format('d.m.');
        }

        foreach ($groups as &$g) {
            $g['days'] = array_values(array_unique($g['days']));
        }

        return array_values($groups);
    }

    public function markDone(int $eventId, int $employeeId): void
    {
        RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $eventId)
            ->where('rec_employee_id', $employeeId)
            ->whereNotNull('declined_hr_at')
            ->whereNull('declined_hr_done_at')
            ->update([
                'declined_hr_done_at'         => now(),
                'declined_hr_done_by_user_id' => auth()->id(),
            ]);
        unset($this->declines);
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.hr-declines');
    }
}
