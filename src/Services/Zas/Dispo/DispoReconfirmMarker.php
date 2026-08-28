<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Duenner DB-Rand fuer DispoReconfirmPolicy (Runde 4, #2): prueft eine Lieferung
 * gegen bestaetigte Einbuchungen und liefert je ds_ref die Reset-Attribute,
 * die der Importer in sein updateOrCreate mergt. Liest nur, schreibt nie.
 * Setzt Bestaetigung, Versand-Stempel und Eskalations-Stufen zurueck, laesst
 * den Snapshot stehen (Vergleichsbasis bis zur naechsten Bestaetigung) und
 * merkt sich die alten Zeiten fuer die Anzeige "Neue Zeiten — vorher …".
 */
class DispoReconfirmMarker
{
    /**
     * @param array<string, array{datum:?string, von:?string, bis:?string}> $incomingByDsRef
     * @return array{overrides: array<string, array<string, mixed>>, count: int}
     */
    public function plan(array $incomingByDsRef, string $today): array
    {
        if ($incomingByDsRef === []) {
            return ['overrides' => [], 'count' => 0];
        }

        $confirmed = RecDispoAssignment::query()
            ->whereIn('ds_ref', array_keys($incomingByDsRef))
            ->whereNotNull('confirmed_at')
            ->whereNull('deletion_marked_at')
            ->get(['id', 'ds_ref', 'confirmed_datum', 'confirmed_von', 'confirmed_bis']);

        $overrides = [];
        foreach ($confirmed as $a) {
            $snap = [
                'datum' => $a->confirmed_datum?->format('Y-m-d'),
                'von'   => $a->confirmed_von,
                'bis'   => $a->confirmed_bis,
            ];
            if (!DispoReconfirmPolicy::needsReconfirm($snap, $incomingByDsRef[$a->ds_ref], $today)) {
                continue;
            }
            $overrides[(string) $a->ds_ref] = [
                'confirmed_at'            => null,
                'reminder_sent_at'        => null,
                'reminder_message_id'     => null,
                'escalation_1_at'         => null,
                'escalation_2_at'         => null,
                'escalation_1_message_id' => null,
                'escalation_2_message_id' => null,
                'reconfirm_required_at'   => now(),
                'reconfirm_previous'      => $snap,
            ];
        }

        return ['overrides' => $overrides, 'count' => count($overrides)];
    }
}
