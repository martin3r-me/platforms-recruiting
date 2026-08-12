<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Baut aus den geparsten Bloecken den Import-Plan (Upsert-Zielzustand) —
 * pure, damit die komplette Logik ohne DB testbar ist. Der Importer fuehrt
 * den Plan nur noch aus.
 *
 * Regeln (Spec): Events aus {Dispo2} gruppiert ueber einsatz_id (erster
 * nicht-leerer Wert pro Feld gewinnt innerhalb eines Laufs; jeder Lauf
 * ueberschreibt mit aktuellem Stand). Assignments aus {Dispo} keyed by
 * ds_id. Assignments ohne {Dispo2}-Event bekommen Platzhalter-Events.
 * missing = existierende zukuenftige ds_refs, die in der Lieferung fehlen.
 */
class ZasDispoImportPlanner
{
    /**
     * @param list<array<string, string>> $dispoRows
     * @param list<array<string, string>> $dispo2Rows
     * @param list<string> $existingFutureDsRefs
     * @return array{events: array<string, array<string, mixed>>, assignments: array<string, array<string, mixed>>, missing_ds_refs: list<string>, stats: array<string, int>}
     */
    public function plan(array $dispoRows, array $dispo2Rows, array $existingFutureDsRefs, string $today): array
    {
        $stats = [
            'dispo_rows'  => count($dispoRows),
            'dispo2_rows' => count($dispo2Rows),
            'skipped_rows_without_ds_id'      => 0,
            'skipped_rows_without_einsatz_id' => 0,
            'placeholder_events' => 0,
        ];

        // --- Events aus {Dispo2} ---
        $events = [];
        foreach ($dispo2Rows as $row) {
            $ref = trim($row['einsatz_id'] ?? '');
            if ($ref === '') {
                $stats['skipped_rows_without_einsatz_id']++;
                continue;
            }

            $current = $events[$ref] ?? [
                'einsatz_ref' => $ref,
                'name' => null, 'venue_text' => null, 'ort' => null,
                'einsatzfirma' => null, 'starts_on' => null, 'ends_on' => null,
                'source_meta' => [],
                'is_placeholder' => false,
            ];

            $current['name']         ??= ZasDispoFieldParser::text($row['projektbezeichnung'] ?? null);
            $current['venue_text']   ??= ZasDispoFieldParser::text($row['text'] ?? null);
            $current['ort']          ??= ZasDispoFieldParser::text($row['ort'] ?? null);
            $current['einsatzfirma'] ??= ZasDispoFieldParser::text($row['einsatzfirma'] ?? null);

            $date = ZasDispoFieldParser::date($row['datum'] ?? null);
            if ($date !== null) {
                $current['starts_on'] = ($current['starts_on'] === null || $date < $current['starts_on']) ? $date : $current['starts_on'];
                $current['ends_on']   = ($current['ends_on'] === null || $date > $current['ends_on']) ? $date : $current['ends_on'];
            }

            foreach (['filiale', 'filial_nr', 'id_firma', 'mitarbeiter_info', 'interne_bem'] as $metaKey) {
                $value = ZasDispoFieldParser::text($row[$metaKey] ?? null);
                if ($value !== null && !array_key_exists($metaKey, $current['source_meta'])) {
                    $current['source_meta'][$metaKey] = $value;
                }
            }

            $events[$ref] = $current;
        }

        // --- Assignments aus {Dispo} ---
        $assignments = [];
        foreach ($dispoRows as $row) {
            $dsRef = trim($row['ds_id'] ?? '');
            if ($dsRef === '') {
                $stats['skipped_rows_without_ds_id']++;
                continue;
            }

            $einsatzRef = trim($row['einsatz_id'] ?? '');
            if ($einsatzRef !== '' && !array_key_exists($einsatzRef, $events)) {
                $events[$einsatzRef] = [
                    'einsatz_ref' => $einsatzRef,
                    'name' => null, 'venue_text' => null, 'ort' => null,
                    'einsatzfirma' => null, 'starts_on' => null, 'ends_on' => null,
                    'source_meta' => [],
                    'is_placeholder' => true,
                ];
                $stats['placeholder_events']++;
            }

            $assignments[$dsRef] = [
                'ds_ref'      => $dsRef,
                'einsatz_ref' => $einsatzRef,
                'pnr_raw'     => trim($row['pnr'] ?? ''),
                'datum'       => ZasDispoFieldParser::date($row['datum'] ?? null),
                'von'         => ZasDispoFieldParser::time($row['von'] ?? null),
                'bis'         => ZasDispoFieldParser::time($row['bis'] ?? null),
                'status_id'   => ZasDispoFieldParser::int($row['status_id'] ?? null) ?? 0,
                'taetigkeit'  => ZasDispoFieldParser::text($row['taetigkeit'] ?? null),
                'source_meta' => array_filter([
                    'einsatzfirma_kurz' => ZasDispoFieldParser::text($row['einsatzfirma_kurz'] ?? null),
                    'ze'                => ZasDispoFieldParser::text($row['ze'] ?? null),
                    'tlp_nr'            => ZasDispoFieldParser::text($row['tlp_nr'] ?? null),
                    'tlp_nr2'           => ZasDispoFieldParser::text($row['tlp_nr2'] ?? null),
                    'essengeld'         => ZasDispoFieldParser::decimal($row['essengeld'] ?? null),
                    'verrechnungssatz'  => ZasDispoFieldParser::decimal($row['verrechnungssatz'] ?? null),
                ], fn ($v) => $v !== null),
            ];
        }

        $stats['events'] = count($events);
        $stats['assignments'] = count($assignments);

        // --- Missing: existierende zukuenftige ds_refs, die die Lieferung nicht enthaelt ---
        $missing = array_values(array_diff($existingFutureDsRefs, array_keys($assignments)));

        return [
            'events' => $events,
            'assignments' => $assignments,
            'missing_ds_refs' => $missing,
            'stats' => $stats,
        ];
    }
}
