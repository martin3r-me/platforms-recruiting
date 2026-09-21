<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Absage erfassen und zuruecknehmen (Kunde 04.09., tagesgenau seit 21.09.).
 *
 * Die Absage galt anfangs immer fuer ALLE kommenden Tage der Person in der VA.
 * Bei Mehrtages-Veranstaltungen ist das zu grob (Befund Marco 18.09.: Absage
 * fuer den Aufbautag nahm die Person auch fuer die Veranstaltungstage raus) —
 * deshalb entscheidet jetzt der Aufrufer ueber die Tage.
 *
 * Eigener Dienst wie DispoManualConfirm: die Komponente bleibt duenn, die
 * Regeln sind ohne Livewire testbar.
 */
class DispoDecline
{
    public function __construct(private DispoEmployeeGateway $gateway)
    {
    }

    /**
     * @param list<int> $assignmentIds Einbuchungen, die abgesagt werden (bereits gefiltert auf die Person/VA)
     * @param list<int> $groupIds      alle Datensaetze der Person (Identitaetsgruppe) fuer die Portalsperre
     * @return int Anzahl abgesagter Einbuchungen
     */
    public function apply(
        int $eventId,
        array $assignmentIds,
        array $groupIds,
        string $reason,
        ?string $note,
        bool $lockPortal,
        bool $toHrDesk,
        ?int $userId,
    ): int {
        if ($assignmentIds === []) {
            return 0;
        }

        $updated = RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $eventId)
            ->whereIn('rec_employee_id', $groupIds)
            ->whereIn('id', $assignmentIds)
            ->whereDate('datum', '>=', now()->toDateString())
            ->whereNull('declined_at')
            ->update([
                'declined_at'            => now(),
                'declined_reason'        => $reason,
                'declined_note'          => $note,
                'declined_by_user_id'    => $userId,
                'declined_portal_locked' => $lockPortal,
                'declined_hr_at'         => $toHrDesk ? now() : null,
            ]);

        if ($updated > 0 && $lockPortal) {
            $this->gateway->lockPortal($groupIds, 'Dispo-Absage (' . $reason . ')');
        }

        return $updated;
    }

    /**
     * Nimmt EINE Absage zurueck: die Zeile ist wieder offen und laeuft normal in
     * Versand und Eskalation. Versand-Stempel bleiben bewusst stehen (User-
     * Entscheid 21.09.) — man soll sehen, dass die Person schon angeschrieben war.
     *
     * Die Portalsperre faellt nur, wenn KEINE weitere Absage der Person sie noch
     * haelt — sonst wuerde eine zurueckgenommene Absage die Sperre einer anderen
     * mit aufheben. Fremde Sperren (Grund nicht "Dispo…") bleiben ohnehin stehen.
     *
     * @param list<int> $groupIds alle Datensaetze der Person
     */
    public function undo(RecDispoAssignment $assignment, array $groupIds): bool
    {
        if ($assignment->declined_at === null) {
            return false;
        }
        $hadLock = (bool) $assignment->declined_portal_locked;

        $assignment->forceFill([
            'declined_at'                 => null,
            'declined_reason'             => null,
            'declined_note'               => null,
            'declined_by_user_id'         => null,
            'declined_portal_locked'      => false,
            'declined_hr_at'              => null,
            'declined_hr_done_at'         => null,
            'declined_hr_done_by_user_id' => null,
        ])->save();

        if ($hadLock && !$this->anotherDeclineHoldsLock($assignment, $groupIds)) {
            $this->gateway->unlockPortal($groupIds);
        }

        return true;
    }

    /** @param list<int> $groupIds */
    private function anotherDeclineHoldsLock(RecDispoAssignment $assignment, array $groupIds): bool
    {
        return RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $groupIds)
            ->whereKeyNot($assignment->id)
            ->whereNotNull('declined_at')
            ->where('declined_portal_locked', true)
            ->exists();
    }
}
