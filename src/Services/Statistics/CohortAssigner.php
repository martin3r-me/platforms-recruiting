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
 *     posting_id: ?int,        // v2: die Ausschreibung fuehrt den Zeilen-Schluessel,
 *     posting_title: string,   // jede Ausschreibung bekommt ihre eigene Zeile.
 *     posting_closed: bool,    // Ohne Zuordnung (Fall 3): null / '' / false.
 *     ids: list<int>,
 *     hr_desk_ids: list<int>,
 *     uneindeutig_ids: list<int>,  // Fall 2 der Zuordnungsregel (Spec §4): kein
 *                                  // Pivot passte zur Phase-Position, Fallback griff
 *     columns: array{kontaktiert:list<int>, gebucht:list<int>, bestaetigt:list<int>,
 *                    teilgenommen:list<int>, standby:list<int>, no_show:list<int>,
 *                    vertrag_verschickt:list<int>, unterschrieben:list<int>,
 *                    phase_reached:array<int,list<int>>},
 *                    // phase_reached[$order] = Bewerbungen, die die Phase mit dieser
 *                    // order erreicht haben — KUMULATIV und lueckenlos von 1 an, und
 *                    // NETTO: nur laufende Zeilentypen (RUNNING_TYPES) fuellen es,
 *                    // Geparkte/Abgesagte/Buckets tauchen im Trichter nicht auf.
 *     tth_days: list<int>,  // Eingang→Unterschrift DIESER Zeile (P5: Kacheln
 *                           // aggregieren ueber dieselben gefilterten Zeilen)
 *     offen_ids: list<int>,       // Right-Censoring (Spec §6): ids − unterschrieben
 *                                 // − no_show, aber NUR fuer RUNNING_TYPES; auf
 *                                 // ausgeschlossenen Buckets immer []. Als ID-Menge,
 *                                 // damit die Zahl anklickbar ist wie jede andere.
 *     max_applied_at: ?string,    // JUENGSTE Bewerbung der Zeile (Y-m-d) = Anker des
 *                                 // Kohorten-Alters; null nur bei ohne_datum-Zeilen
 *   }>,
 * ]
 */
final class CohortAssigner
{
    /**
     * Zeilentypen, die eine LAUFENDE Kohorte beschreiben — der Bewerber steckt noch
     * im Funnel und sein Ausgang ist offen. Alle anderen Typen sind ausgeschlossene
     * Buckets (Dublette, Import, unrouted, ohne Datum, geparkt, abgesagt, unbekannter
     * Status): dort ist „noch offen" keine Aussage, weil es gar keinen Termin-Ausgang
     * gibt, gegen den etwas offen sein koennte.
     *
     * @var list<string>
     */
    public const RUNNING_TYPES = ['schulung', 'ohne_schulung'];


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
            $assignment = $this->groupFor($a, $pivotsByApplicant[$a['id']] ?? []);
            // group behaelt sein Shape (Ort/Taetigkeit/uneindeutig) — die
            // Ausschreibungs-Felder sitzen auf der ZEILE, nicht in der Gruppe:
            // die Gruppierung der Anzeige (CohortViewModel) liest group und darf
            // von der neuen Granularitaet nichts mitbekommen.
            $group = [
                'ort' => $assignment['ort'],
                'taetigkeit' => $assignment['taetigkeit'],
                'uneindeutig' => $assignment['uneindeutig'],
            ];

            // v2: die Ausschreibung fuehrt den Zeilen-Schluessel — zwei
            // Ausschreibungen derselben Ort/Taetigkeit-Gruppe bilden getrennte
            // Zeilen (frueher waren sie zu einer Gruppenzeile verschmolzen, und
            // genau das konnte der Kunde nicht nachvollziehen). Der bisherige
            // Gruppierungs-Anteil bleibt vollstaendig erhalten, damit die
            // Praezedenz-Kette und die Fallbacks weiter trennen wie bisher.
            //
            // 'ohne' ist ein STABILER Platzhalter fuer Bewerbungen ohne
            // Zuordnung (Fall 3): ein leerer Anteil wuerde den Schluessel
            // kollabieren lassen und koennte mit einer echten posting_id
            // kollidieren. Im Row-Shape bleibt posting_id trotzdem null.
            $postingPart = $assignment['posting_id'] === null
                ? 'ohne'
                : (string) $assignment['posting_id'];
            $rowKey = 'posting:' . $postingPart
                . '|' . $type . '|' . $key . '|' . $group['ort'] . '|' . $group['taetigkeit'];
            if (!isset($rows[$rowKey])) {
                $rows[$rowKey] = [
                    'type' => $type, 'key' => $key, 'group' => $group,
                    'posting_id' => $assignment['posting_id'],
                    'posting_title' => $assignment['posting_title'],
                    'posting_closed' => $assignment['posting_closed'],
                    'ids' => [], 'hr_desk_ids' => [], 'uneindeutig_ids' => [], 'tth_days' => [],
                    'max_applied_at' => null,
                    'columns' => [
                        'kontaktiert' => [], 'gebucht' => [], 'bestaetigt' => [],
                        'teilgenommen' => [], 'standby' => [], 'no_show' => [],
                        'vertrag_verschickt' => [], 'unterschrieben' => [],
                        'phase_reached' => [],
                    ],
                ];
            }
            $row = &$rows[$rowKey];
            $row['ids'][] = $a['id'];
            // Kohorten-Alter (Spec §6) ankert an der JUENGSTEN Bewerbung der Zeile.
            // Mit der aeltesten waere das Censoring wirkungslos: eine alte Bewerbung
            // plus zwanzig frische haette die Zeile reif erscheinen lassen, obwohl
            // fast alle noch im Funnel haengen. Die juengste liefert das kleinste
            // Alter und graut damit haeufiger — falsch-grau ist harmlos, falsch-farbig
            // geht als Zahl an den Kunden.
            // Y-m-d-Strings sind lexikographisch = chronologisch, kein Datum-Parsing.
            if ($a['applied_at'] !== null
                && ($row['max_applied_at'] === null || $a['applied_at'] > $row['max_applied_at'])) {
                $row['max_applied_at'] = $a['applied_at'];
            }
            if ($a['hr_desk']) {
                $row['hr_desk_ids'][] = $a['id']; // Marker, kein Zeilentyp (Spec §4)
            }
            if ($group['uneindeutig']) {
                $row['uneindeutig_ids'][] = $a['id']; // Marker (Fall 2), kein Zeilentyp
            }

            // Phase-Trichter, NETTO: nur laufende Kohorten (RUNNING_TYPES) fuellen
            // phase_reached. Geparkte, Abgesagte und die ausgeschlossenen Buckets
            // haben eigene Zeilen (Praezedenz-Kette) und bleiben dort mit LEERER
            // phase_reached stehen — sonst zeigt der Phasen-Trichter Leute, die
            // gar nicht mehr im Rennen sind.
            //
            // ACHTUNG, haeufiges Missverstaendnis: netto rechnet NUR phase_reached.
            // Die alten Spalten sind es NICHT und werden es hier auch nicht:
            //  - kontaktiert, vertrag_verschickt, unterschrieben (und tth_days)
            //    fuellen JEDEN Zeilentyp, auch geparkt/abgesagt/dublette/import/
            //    unrouted/ohne_datum/unbekannter_status;
            //  - gebucht/bestaetigt/teilgenommen/no_show/standby nur auf
            //    Schulungszeilen (sie haengen an der gewonnenen Buchung);
            //  - offen_ids ist wie phase_reached auf RUNNING_TYPES begrenzt
            //    (Nachlauf unter der Schleife).
            // Wer das aendert, aendert Bestandszahlen — nicht beilaeufig tun.
            //
            // KUMULATIV und lueckenlos von 1 an: wer Phase 4 erreicht hat, hat 1
            // bis 3 durchlaufen, unabhaengig davon, ob das Transition-Log jeden
            // Zwischenschritt protokolliert hat (Entscheidung 2026-08-17). Ein
            // Zaehlen nur der protokollierten Schritte haette den Trichter an der
            // Log-Vollstaendigkeit haengen lassen.
            $reached = $a['phase_order_reached'] ?? null;
            if ($reached !== null && in_array($type, self::RUNNING_TYPES, true)) {
                for ($order = 1; $order <= (int) $reached; $order++) {
                    $row['columns']['phase_reached'][$order][] = $a['id'];
                }
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

        // Nachlauf statt Mitzaehlen: "offen" ist definiert als Mengen-Differenz
        // (Spec §6), und unterschrieben/no_show stehen erst nach der Schleife
        // vollstaendig fest. Ein Mitzaehlen waere von der Reihenfolge der
        // Spalten-Zuweisungen abhaengig gewesen.
        foreach ($rows as &$row) {
            // Nur laufende Kohorten: auf ausgeschlossenen Buckets gibt es keinen
            // Termin-Ausgang, gegen den etwas offen sein koennte — jede Person ohne
            // Unterschrift haette dort als "offen" gezaehlt und die Zahl aufgeblasen.
            $row['offen_ids'] = in_array($row['type'], self::RUNNING_TYPES, true)
                ? array_values(array_diff(
                    $row['ids'],
                    $row['columns']['unterschrieben'],
                    $row['columns']['no_show'],
                ))
                : [];
        }
        unset($row);

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

    /**
     * Zuordnungsregel (Spec §4, fuenf Faelle) → Gruppe UND Ausschreibung, nie
     * Zeilentyp. Die Auswahl-Logik der fuenf Faelle ist unveraendert; sie gibt
     * jetzt zusaetzlich aus, WELCHE Ausschreibung gewonnen hat, damit die Zeile
     * daran haengen kann.
     *
     * @return array{ort:string, taetigkeit:string, uneindeutig:bool,
     *               posting_id:?int, posting_title:string, posting_closed:bool}
     */
    private function groupFor(array $a, array $pivots): array
    {
        if ($pivots === []) {
            return self::noAssignment(); // Fall 3
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
        // Eine Pivot-Zeile ohne posting_id ist KEINE Zuordnung — sie fuehrt auf
        // denselben Ausgang wie Fall 3. Ein blosser (int)-Cast waere hier still
        // falsch: (int) null ist 0, und 0 saehe wie eine echte Ausschreibung aus.
        // Alle Bewerbungen mit null-Pivot wuerden dann unter der Phantom-
        // Ausschreibung 0 zu EINER Zeile verschmelzen, statt als "ohne
        // Ausschreibung" auszuweisen. Heute nicht ausloesbar (posting_id ist ein
        // Primaerschluessel), aber die Klasse behandelt den Fall sonst ueberall
        // saeuberlich — diese eine Stelle darf nicht die Ausnahme sein.
        if (($match['posting_id'] ?? null) === null) {
            return self::noAssignment();
        }
        return [
            'ort' => ($match['location'] !== null && $match['location'] !== '') ? $match['location'] : 'ohne Ort',
            'taetigkeit' => ($match['activity'] !== null && $match['activity'] !== '') ? $match['activity'] : 'ohne Tätigkeit',
            'uneindeutig' => $uneindeutig,
            // Titel/closed sind reine Anzeige-Beigaben der gewonnenen Zuordnung.
            // Mit ??-Fallback, weil sie erst mit v2 in den Pivot-Shape kommen und
            // ein Aufrufer-Stand ohne sie die Seite nicht zerlegen soll.
            'posting_id' => (int) $match['posting_id'],
            'posting_title' => (string) ($match['posting_title'] ?? ''),
            'posting_closed' => (bool) ($match['posting_closed'] ?? false),
        ];
    }

    /**
     * „Keine Ausschreibungs-Zuordnung" — Fall 3 der Zuordnungsregel und der
     * Ausgang fuer eine Pivot-Zeile ohne posting_id. Als eine Quelle, damit die
     * beiden Wege nicht auseinanderlaufen koennen; den Platzhalter im
     * Zeilen-Schluessel setzt assign().
     *
     * @return array{ort:string, taetigkeit:string, uneindeutig:bool,
     *               posting_id:null, posting_title:string, posting_closed:bool}
     */
    private static function noAssignment(): array
    {
        return [
            'ort' => 'ohne Ausschreibung', 'taetigkeit' => 'ohne Ausschreibung', 'uneindeutig' => false,
            'posting_id' => null, 'posting_title' => '', 'posting_closed' => false,
        ];
    }
}
