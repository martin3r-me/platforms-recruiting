<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Mitarbeiterfiliale aus der Kostenstelle (Prod-Befund 12.10.2026: 100/101/102/103
 * = DUS & ES, 200–203 = MGL, 400–403 = CGN). Der Hunderterblock ist die Filiale,
 * die Unternummern laufen mit. Rein rechnend; Labels vergibt der Aufrufer
 * (Filialen::code), damit die Klasse ohne Container testbar bleibt.
 */
final class KostenstellenBloecke
{
    /** Eigene Kostenstelle vor der der Stelle; nicht numerisch oder leer -> null. */
    public static function block(?string $eigene, ?string $stelle): ?int
    {
        foreach ([$eigene, $stelle] as $wert) {
            $wert = trim((string) $wert);
            if ($wert !== '' && ctype_digit($wert)) {
                return intdiv((int) $wert, 100) * 100;
            }
            if ($wert !== '') {
                return null; // "MGL" o. ae.: gesetzt, aber unbrauchbar — nicht auf die Stelle zurueckfallen
            }
        }

        return null;
    }

    /**
     * @param iterable<array{cost_center:?string, position_cost_center:?string}> $rows
     * @return list<array{block:?int, nummern:list<string>, count:int}> sortiert nach Block, null zuletzt
     */
    public static function optionen(iterable $rows): array
    {
        $bloecke = [];
        $ohne = 0;
        foreach ($rows as $row) {
            $block = self::block($row['cost_center'] ?? null, $row['position_cost_center'] ?? null);
            if ($block === null) {
                $ohne++;
                continue;
            }
            $nummer = trim((string) ($row['cost_center'] ?? ''));
            if ($nummer === '' || !ctype_digit($nummer)) {
                $nummer = trim((string) ($row['position_cost_center'] ?? ''));
            }
            $bloecke[$block]['count'] = ($bloecke[$block]['count'] ?? 0) + 1;
            $bloecke[$block]['nummern'][$nummer] = true;
        }
        ksort($bloecke);

        $out = [];
        foreach ($bloecke as $block => $data) {
            $nummern = array_keys($data['nummern']);
            sort($nummern, SORT_NATURAL);
            $out[] = ['block' => $block, 'nummern' => array_values(array_map('strval', $nummern)), 'count' => $data['count']];
        }
        if ($ohne > 0) {
            $out[] = ['block' => null, 'nummern' => [], 'count' => $ohne];
        }

        return $out;
    }
}
