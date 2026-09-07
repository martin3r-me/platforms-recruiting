<?php

namespace Platform\Recruiting\Support;

/**
 * Ordnung der Phasen-Spalten in den Statistik-Tabellen (Claras Liste):
 * erste Phase weg (kumulativ ≈ „Bewerbungen", zweite fast gleichnamige Spalte),
 * Buchungs-Phase vor den Buchungs-Trichter (Prozess-Reihenfolge), Rest dahinter;
 * die Vertragsversand-Phase heisst in der Anzeige „Vollständig registriert" —
 * am completion_type festgemacht, nicht am Phasennamen (freier Nutzertext).
 *
 * Pure Entscheidungslogik ohne Framework; die Blades bauen daraus ihre
 * colDefs, die Daten (phase_reached je order) bleiben unveraendert.
 */
final class StatisticsPhaseColumns
{
    /**
     * @param  array<int, array{name: string, completion_type: ?string}>  $phases  order => Phase
     * @return array{early: list<int>, late: list<int>, labels: array<int, string>}
     */
    public static function plan(array $phases): array
    {
        ksort($phases);

        $orders = array_keys($phases);
        $first = $orders[0] ?? null;

        $early = [];
        $late = [];
        $labels = [];
        foreach ($phases as $order => $phase) {
            if ($order === $first) {
                continue; // kumulative Erste ≈ „Bewerbungen" — Claras Doppel-Spalte
            }
            if (($phase['completion_type'] ?? null) === 'booking') {
                $early[] = $order;
            } else {
                $late[] = $order;
            }
            $labels[$order] = ($phase['completion_type'] ?? null) === 'contract_sent'
                ? 'Vollständig registriert'
                : (string) $phase['name'];
        }

        return ['early' => $early, 'late' => $late, 'labels' => $labels];
    }
}
