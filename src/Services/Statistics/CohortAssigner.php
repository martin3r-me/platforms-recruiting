<?php

namespace Platform\Recruiting\Services\Statistics;

use Platform\Recruiting\Support\BookingStatusGroups;
use Platform\Recruiting\Support\SeatStandbyPolicy;

/**
 * Herzstueck der Statistik-Seite (Spec §4): Praezedenz-Kette (Zeilentyp) und
 * Zuordnungsregel (Gruppe) leben KOMPLETT hier — nicht in SQL —, damit die
 * Rekonziliations-Invariante pure-testbar ist. Liefert pro Zelle ID-Mengen;
 * die Anzeige ist count() davon, das Drill-down laedt exakt diese IDs.
 *
 * Ergebnis-Shape:
 * [
 *   'total_ids' => list<int>,
 *   'rows' => list<array{
 *     type: string,   // ohne_datum|dublette|unrouted|import|schulung|
 *                     // unbekannter_status|geparkt|abgesagt|ohne_schulung
 *     key: string,    // z.B. "schulung:42", "ohne_schulung:2|Onboarding"
 *     group: array{ort:string, taetigkeit:string, uneindeutig:bool},
 *     ids: list<int>,
 *     hr_desk_ids: list<int>,
 *     uneindeutig_ids: list<int>,  // Fall 2 der Zuordnungsregel (Spec §4): kein
 *                                  // Pivot passte zur Phase-Position, Fallback griff
 *     columns: array{kontaktiert:list<int>, gebucht:list<int>, bestaetigt:list<int>,
 *                    teilgenommen:list<int>, standby:list<int>, no_show:list<int>,
 *                    vertrag_verschickt:list<int>, unterschrieben:list<int>},
 *     tth_days: list<int>,  // Eingang→Unterschrift DIESER Zeile (P5: Kacheln
 *                           // aggregieren ueber dieselben gefilterten Zeilen)
 *   }>,
 * ]
 */
final class CohortAssigner
{
    public function assign(
        array $applicants,
        array $bookingsByApplicant,
        array $pivotsByApplicant,
        ?string $from,
        ?string $to,
    ): array {
        // P6: geleerte Livewire-Datumsfelder liefern '' statt null — und
        // '2026-07-05' > '' ist WAHR, die Tabelle waere komplett leer.
        // Pure Klasse mit oeffentlichem Kontrakt traut dem Aufrufer nicht.
        $from = ($from === '') ? null : $from;
        $to = ($to === '') ? null : $to;

        $rows = [];
        $totalIds = [];

        foreach ($applicants as $a) {
            // Stufe 1: is_test — einziger stiller Filter (Spec §4)
            if ($a['is_test']) {
                continue;
            }
            // Zeitraum ist Filter — mit NULL-Ausnahme (Stufe 2 faengt NULL)
            if ($a['applied_at'] !== null) {
                if (($from !== null && $a['applied_at'] < $from)
                    || ($to !== null && $a['applied_at'] > $to)) {
                    continue;
                }
            }

            $totalIds[] = $a['id'];

            [$type, $key, $booking] = $this->rowTypeFor($a, $bookingsByApplicant[$a['id']] ?? []);
            $group = $this->groupFor($a, $pivotsByApplicant[$a['id']] ?? []);

            $rowKey = $type . '|' . $key . '|' . $group['ort'] . '|' . $group['taetigkeit'];
            if (!isset($rows[$rowKey])) {
                $rows[$rowKey] = [
                    'type' => $type, 'key' => $key, 'group' => $group,
                    'ids' => [], 'hr_desk_ids' => [], 'uneindeutig_ids' => [], 'tth_days' => [],
                    'columns' => [
                        'kontaktiert' => [], 'gebucht' => [], 'bestaetigt' => [],
                        'teilgenommen' => [], 'standby' => [], 'no_show' => [],
                        'vertrag_verschickt' => [], 'unterschrieben' => [],
                    ],
                ];
            }
            $row = &$rows[$rowKey];
            $row['ids'][] = $a['id'];
            if ($a['hr_desk']) {
                $row['hr_desk_ids'][] = $a['id']; // Marker, kein Zeilentyp (Spec §4)
            }
            if ($group['uneindeutig']) {
                $row['uneindeutig_ids'][] = $a['id']; // Marker (Fall 2), kein Zeilentyp
            }

            if ($a['enrichment_status'] !== null && $a['enrichment_status'] !== 'no_contact') {
                $row['columns']['kontaktiert'][] = $a['id'];
            }
            if ($type === 'schulung' && $booking !== null) {
                $rank = BookingStatusGroups::rank($booking['status']);
                if ($rank >= 1) { $row['columns']['gebucht'][] = $a['id']; }
                if ($rank >= 2) { $row['columns']['bestaetigt'][] = $a['id']; }
                if ($rank >= 3) { $row['columns']['teilgenommen'][] = $a['id']; }
                if ($booking['status'] === 'no_show') { $row['columns']['no_show'][] = $a['id']; }
                // Review-Fix 2: keine zweite Wahrheit fuer Standby — die Policy
                // (SeatStandbyPolicy::statusLabel) ist die einzige Quelle (Spec §4).
                if (SeatStandbyPolicy::statusLabel($booking['status'], $booking['seat_released']) !== null) {
                    $row['columns']['standby'][] = $a['id'];
                }
            }
            if ($a['contract_sent']) { $row['columns']['vertrag_verschickt'][] = $a['id']; }
            if ($a['contract_signed']) {
                $row['columns']['unterschrieben'][] = $a['id'];
                if ($a['applied_to_signed_days'] !== null) {
                    $row['tth_days'][] = $a['applied_to_signed_days']; // P5: pro Zeile
                }
            }
            unset($row);
        }

        return ['total_ids' => $totalIds, 'rows' => array_values($rows)];
    }

    /** @return array{0:string,1:string,2:?array} [type, key, gewinnende Buchung|null] */
    private function rowTypeFor(array $a, array $bookings): array
    {
        if ($a['applied_at'] === null) { return ['ohne_datum', '-', null]; }   // Stufe 2
        if ($a['duplicate']) { return ['dublette', '-', null]; }               // Stufe 3
        if ($a['unrouted']) { return ['unrouted', '-', null]; }                // Stufe 4
        if ($a['import']) { return ['import', '-', null]; }                    // Stufe 5: Import schlaegt Buchung

        // Stufe 6: neueste kohorten-relevante Buchung. Tie-Break (Senior-Rule):
        // spaetester starts_at, bei Gleichstand kleinste Booking-ID.
        $candidates = array_values(array_filter($bookings, fn ($b) => !$b['deleted']
            && (BookingStatusGroups::isCohortAssigned($b['status'])
                || BookingStatusGroups::isUnknownActive($b['status']))));
        if ($candidates !== []) {
            usort($candidates, function ($x, $y) {
                $cmp = strcmp((string) $y['starts_at'], (string) $x['starts_at']);
                return $cmp !== 0 ? $cmp : ($x['booking_id'] <=> $y['booking_id']);
            });
            $winner = $candidates[0];
            if (BookingStatusGroups::isCohortAssigned($winner['status'])) {
                return ['schulung', 'schulung:' . $winner['interview_id'], $winner];
            }
            return ['unbekannter_status', '-', $winner]; // sichtbar, nie verschluckt
        }

        // Stufe 7: rejected und parked koennen gleichzeitig true sein — Review-Fix 3
        // (Entscheidung Controller): abgesagt schlaegt geparkt, da rejected der
        // endgueltige Zustand ist und parked nur der weiche.
        if ($a['rejected']) { return ['abgesagt', '-', null]; }
        if ($a['parked']) { return ['geparkt', '-', null]; }
        // Stufe 8: nach aktueller Phase aufgeschluesselt
        $phaseKey = ($a['phase_order'] ?? '-') . '|' . ($a['phase_name'] ?? 'ohne Phase');
        return ['ohne_schulung', 'ohne_schulung:' . $phaseKey, null];
    }

    /** Zuordnungsregel (Spec §4, fuenf Faelle) → Gruppe, nie Zeilentyp */
    private function groupFor(array $a, array $pivots): array
    {
        if ($pivots === []) {
            return ['ort' => 'ohne Ausschreibung', 'taetigkeit' => 'ohne Ausschreibung', 'uneindeutig' => false]; // Fall 3
        }
        // Fall 1: alle Pivots, die zur Position von rec_phase_id passen. Review-Fix 4:
        // bei mehreren Treffern deterministisch die kleinste posting_id, nicht Array-Reihenfolge.
        $matching = ($a['phase_position_id'] !== null)
            ? array_values(array_filter($pivots, fn ($p) => $p['position_id'] === $a['phase_position_id']))
            : [];
        // Fall 2: keine Pivot passt → uneindeutig, Fallback auf kleinste posting_id
        // ueber ALLE Pivots. Review-Fix 1: als 'uneindeutig' im Shape sichtbar, damit
        // die UI mess- und anklickbar bleibt, ohne die Praezedenzlogik zu duplizieren.
        $uneindeutig = $matching === [];
        $candidates = $uneindeutig ? $pivots : $matching;
        usort($candidates, fn ($x, $y) => $x['posting_id'] <=> $y['posting_id']);
        $match = $candidates[0];
        return [
            'ort' => ($match['location'] !== null && $match['location'] !== '') ? $match['location'] : 'ohne Ort',
            'taetigkeit' => ($match['activity'] !== null && $match['activity'] !== '') ? $match['activity'] : 'ohne Tätigkeit',
            'uneindeutig' => $uneindeutig,
        ];
    }
}
