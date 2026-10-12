<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Services\Zas\Dispo\DispoQualifikationExtractor;

/**
 * Schicht-Optionen einer Veranstaltung fuer „Jobs anbieten“ (Schritt 4):
 * je Tag die vorkommenden Kombinationen Arbeitsbeginn × Taetigkeit.
 * Rein rechnend; verschwundene und zur Loeschung gemeldete Zeilen zaehlen nicht
 * (wie DispoRowFilters). `platzhalter` = RG0/RG14-Zeilen — nur Anzeige
 * „laut ZAS N unbesetzt“, keine Vorbelegung.
 */
final class DispoJobSchichten
{
    /**
     * @param iterable<object|array<string,mixed>> $assignments Einbuchungen (Modelle oder Arrays)
     * @param list<string> $tage Y-m-d — nur diese Tage; leer = keine Einschraenkung
     * @return array<string, list<array{von:string, taetigkeit:string, zeilen:int, platzhalter:int}>>
     */
    public static function optionen(iterable $assignments, array $tage): array
    {
        $tageSet = array_fill_keys($tage, true);
        $platzhalter = array_fill_keys(array_map('mb_strtoupper', DispoQualifikationExtractor::PLATZHALTER_PNR), true);
        $out = [];

        foreach ($assignments as $a) {
            if (self::feld($a, 'missing_since') !== null || self::feld($a, 'deletion_marked_at') !== null) {
                continue;
            }
            $datum = self::datum(self::feld($a, 'datum'));
            if ($datum === '' || ($tageSet !== [] && !isset($tageSet[$datum]))) {
                continue;
            }
            $von = trim((string) self::feld($a, 'von'));
            $taet = trim((string) self::feld($a, 'taetigkeit'));
            $key = $von . "\0" . $taet;
            $row = &$out[$datum][$key];
            $row ??= ['von' => $von, 'taetigkeit' => $taet, 'zeilen' => 0, 'platzhalter' => 0];
            $row['zeilen']++;
            if (isset($platzhalter[mb_strtoupper(trim((string) self::feld($a, 'pnr_raw')))])) {
                $row['platzhalter']++;
            }
            unset($row);
        }

        ksort($out, SORT_STRING);
        foreach ($out as $datum => $rows) {
            $rows = array_values($rows);
            usort($rows, fn ($x, $y) => [$x['von'], mb_strtolower($x['taetigkeit'])] <=> [$y['von'], mb_strtolower($y['taetigkeit'])]);
            $out[$datum] = $rows;
        }

        return $out;
    }

    /**
     * Stabiler, wire:model-tauglicher Schluessel einer Schicht (keine Punkte,
     * keine Leerzeichen — Taetigkeiten koennen beides enthalten).
     */
    public static function schluessel(int $eventId, string $datum, string $von, string $taetigkeit): string
    {
        return 's' . substr(sha1($eventId . '|' . $datum . '|' . trim($von) . '|' . trim($taetigkeit)), 0, 16);
    }

    private static function feld(mixed $a, string $name): mixed
    {
        return is_array($a) ? ($a[$name] ?? null) : ($a->{$name} ?? null);
    }

    private static function datum(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        return substr(trim((string) $v), 0, 10);
    }
}
