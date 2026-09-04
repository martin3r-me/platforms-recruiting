<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Manuelle Bestaetigung durch die Dispo (Kunde 04.09., Esra-Fall): telefonische
 * Zusage oder Meldung ueber eine fremde Nummer — formal fehlte nur der Klick.
 *
 * Wirkt pro Person fuer ALLE kommenden Einsatztage der VA (Spiegel der
 * Selbstbestaetigung auf der Einsatz-Seite) und holt dabei auch bereits zur
 * Loeschung Gemeldete zurueck. Bewusst NICHT angefasst: Abgesagte (bewusste
 * Entscheidung) und aus ZAS Verschwundene (nicht mehr im Rennen).
 *
 * Danach wird das Portal GRUPPENWEIT entsperrt (alle Datensaetze der Person,
 * Woettki-Lehre vom 04.09.) — aber nur Dispo-Sperren; anderweitige Sperren
 * bleiben stehen. Die Eskalation stoppt von selbst: bestaetigt beendet sie.
 */
class DispoManualConfirm
{
    public function __construct(private DispoEmployeeGateway $gateway)
    {
    }

    /**
     * @param list<int> $groupIds alle Datensaetze der Person (Identitaetsgruppe)
     * @return int Anzahl bestaetigter Einbuchungen
     */
    public function confirm(int $eventId, array $groupIds, ?int $userId): int
    {
        $updated = RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $eventId)
            ->whereIn('rec_employee_id', $groupIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereDate('datum', '>=', now()->toDateString())
            ->whereNull('confirmed_at')
            ->whereNull('declined_at')
            ->whereNull('missing_since')
            ->update([
                'confirmed_at'                  => now(),
                'confirmed_datum'               => DB::raw('datum'),
                'confirmed_von'                 => DB::raw('von'),
                'confirmed_bis'                 => DB::raw('bis'),
                'reconfirm_required_at'         => null,
                'reconfirm_previous'            => null,
                'deletion_marked_at'            => null,
                'manually_confirmed_by_user_id' => $userId,
            ]);

        if ($updated > 0) {
            $this->gateway->unlockPortal($groupIds);
        }

        return $updated;
    }
}
