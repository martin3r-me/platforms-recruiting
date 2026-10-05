<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Collection;

/**
 * Spaltenfilter der VA-Tabelle (Kunde 05.10.: "wie der Tabellenfilter in Excel
 * — ich will auswaehlen, welche Anfangszeit und welche Taetigkeit ich sehe").
 *
 * Rein rechnend, kein DB-Zugriff: die Einbuchungen reicht der Aufrufer herein.
 *
 * Angeboten werden NUR Werte, die in dieser Veranstaltung vorkommen — ein
 * freier Datumswaehler waere sinnlos, eine VA kennt nur ihre eigenen Tage.
 */
final class DispoRowFilters
{
    /**
     * Auswahlwerte mit Anzahl, wie der Excel-Filter sie zeigt. Verschwundene und
     * zur Loeschung gemeldete Zeilen zaehlen nicht mit — sonst bietet der Filter
     * eine Tätigkeit an, die in der Tabelle gar nicht mehr auftaucht.
     *
     * @param iterable<\Platform\Recruiting\Models\RecDispoAssignment> $rows
     * @return array{days: list<array{value:string,label:string,count:int}>, times: list<array{value:string,label:string,count:int}>, taetigkeiten: list<array{value:string,label:string,count:int}>}
     */
    public static function options(iterable $rows): array
    {
        $days = $times = $taet = [];

        foreach ($rows as $a) {
            if ($a->missing_since !== null || $a->deletion_marked_at !== null) {
                continue;
            }
            if ($a->datum !== null) {
                $key = $a->datum->format('Y-m-d');
                $days[$key] = ($days[$key] ?? 0) + 1;
            }
            $von = trim((string) $a->von);
            if ($von !== '') {
                $times[$von] = ($times[$von] ?? 0) + 1;
            }
            $t = trim((string) $a->taetigkeit);
            if ($t !== '') {
                $taet[$t] = ($taet[$t] ?? 0) + 1;
            }
        }

        ksort($days, SORT_STRING);
        ksort($times, SORT_STRING);
        ksort($taet, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'days'         => self::entries($days, fn ($v) => \Illuminate\Support\Carbon::parse($v)->format('d.m.')),
            'times'        => self::entries($times, fn ($v) => $v),
            'taetigkeiten' => self::entries($taet, fn ($v) => $v),
        ];
    }

    /**
     * Zeilen auf die angehakten Werte einschraenken. Leeres Array heisst "keine
     * Einschraenkung" — nicht "nichts anzeigen".
     *
     * @param list<string> $days Y-m-d
     * @param list<string> $times Anfangszeiten
     * @param list<string> $taetigkeiten
     */
    public static function apply(Collection $rows, array $days, array $times, array $taetigkeiten): Collection
    {
        if ($days !== []) {
            $rows = $rows->filter(fn ($a) => $a->datum !== null && in_array($a->datum->format('Y-m-d'), $days, true));
        }
        if ($times !== []) {
            $rows = $rows->filter(fn ($a) => in_array(trim((string) $a->von), $times, true));
        }
        if ($taetigkeiten !== []) {
            $rows = $rows->filter(fn ($a) => in_array(trim((string) $a->taetigkeit), $taetigkeiten, true));
        }

        return $rows;
    }

    /**
     * @param array<string,int> $counts
     * @return list<array{value:string,label:string,count:int}>
     */
    private static function entries(array $counts, callable $label): array
    {
        $out = [];
        foreach ($counts as $value => $count) {
            $out[] = ['value' => (string) $value, 'label' => (string) $label((string) $value), 'count' => $count];
        }

        return $out;
    }
}
