<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Services\Zas\Dispo\DispoQualifikationExtractor;

/**
 * Schicht-Optionen einer Veranstaltung fuer „Jobs anbieten“ (Schritt 2).
 *
 * Zwei Quellen (Spec 2026-10-29 §5):
 *  1. Bedarfsposten aus der ZAS-Bedarfsliste ({Dispo2}): je Posten EINE Zeile
 *     mit `offen` = Plaetze minus echte Personen und `dispoposten`.
 *  2. Personenzeilen ({Dispo}): werden ihrem Posten zugeordnet (gleicher Tag,
 *     gleiche Taetigkeit, Beginn = eine Zeitvariante des Postens; sonst der
 *     einzige Posten dieser Taetigkeit am Tag). Ohne Posten bilden sie wie
 *     frueher eigene Zeilen Beginn × Taetigkeit mit `offen` = null.
 *
 * Rein rechnend; verschwundene und zur Loeschung gemeldete Zeilen zaehlen nicht
 * (wie DispoRowFilters), ebenso verschwundene/stornierte Posten.
 * `platzhalter` = RG0/RG14-Zeilen — zaehlen in `zeilen`, nie als echte Person.
 */
final class DispoJobSchichten
{
    /**
     * @param iterable<object|array<string,mixed>> $assignments Einbuchungen (Modelle oder Arrays)
     * @param list<string> $tage Y-m-d — nur diese Tage; leer = keine Einschraenkung
     * @param iterable<object|array<string,mixed>> $bedarfe Bedarfsposten (RecDispoBedarf oder Arrays)
     * @return array<string, list<array{von:string, bis:string, taetigkeit:string, zeilen:int, platzhalter:int, offen:?int, dispoposten:?string}>>
     */
    public static function optionen(iterable $assignments, array $tage, iterable $bedarfe = []): array
    {
        $tageSet = array_fill_keys($tage, true);
        $platzhalter = array_fill_keys(array_map('mb_strtoupper', DispoQualifikationExtractor::PLATZHALTER_PNR), true);
        $out = [];
        /** @var array<string, array<string, list<string>>> $postenJeTaetigkeit datum => taetigkeit(normiert) => Zeilen-Schluessel */
        $postenJeTaetigkeit = [];

        // --- 1. Posten ---
        foreach ($bedarfe as $b) {
            if (self::feld($b, 'missing_since') !== null || (int) self::feld($b, 'status_id') === 3) {
                continue;
            }
            $nr = trim((string) self::feld($b, 'dispoposten_id'));
            $datum = self::datum(self::feld($b, 'datum'));
            if ($nr === '' || $datum === '' || ($tageSet !== [] && !isset($tageSet[$datum]))) {
                continue;
            }
            $von = trim((string) self::feld($b, 'von'));
            $taet = trim((string) self::feld($b, 'taetigkeit'));
            $vons = [$von => true];
            foreach (self::zeiten(self::feld($b, 'zeiten')) as $z) {
                $vons[trim((string) ($z['von'] ?? ''))] = true;
            }
            $key = 'p' . $nr;
            $out[$datum][$key] = [
                'von' => $von, 'bis' => trim((string) self::feld($b, 'bis')), 'taetigkeit' => $taet,
                'zeilen' => 0, 'platzhalter' => 0, 'offen' => 0, 'dispoposten' => $nr,
                '_anzahl' => max(0, (int) self::feld($b, 'anzahl')), '_echte' => 0, '_vons' => $vons,
            ];
            $postenJeTaetigkeit[$datum][self::norm($taet)][] = $key;
        }

        // --- 2. Personen ---
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
            $istPlatzhalter = isset($platzhalter[mb_strtoupper(trim((string) self::feld($a, 'pnr_raw')))]);

            $ziel = null;
            $kandidaten = $postenJeTaetigkeit[$datum][self::norm($taet)] ?? [];
            foreach ($kandidaten as $k) {
                if (isset($out[$datum][$k]['_vons'][$von])) {
                    $ziel = $k;
                    break;
                }
            }
            if ($ziel === null && count($kandidaten) === 1) {
                $ziel = $kandidaten[0];
            }

            if ($ziel !== null) {
                $row = &$out[$datum][$ziel];
                $row['zeilen']++;
                $istPlatzhalter ? $row['platzhalter']++ : $row['_echte']++;
                unset($row);
                continue;
            }

            $key = 'z' . $von . "\0" . $taet;
            $row = &$out[$datum][$key];
            $row ??= ['von' => $von, 'bis' => '', 'taetigkeit' => $taet, 'zeilen' => 0, 'platzhalter' => 0, 'offen' => null, 'dispoposten' => null, '_bis' => []];
            $row['zeilen']++;
            $bis = trim((string) self::feld($a, 'bis'));
            if ($bis !== '') {
                $row['_bis'][$bis] = ($row['_bis'][$bis] ?? 0) + 1;
            }
            if ($istPlatzhalter) {
                $row['platzhalter']++;
            }
            unset($row);
        }

        // --- 3. Abschluss ---
        ksort($out, SORT_STRING);
        foreach ($out as $datum => $rows) {
            $rows = array_values(array_map(function (array $r) {
                if ($r['dispoposten'] !== null) {
                    $r['offen'] = max(0, $r['_anzahl'] - $r['_echte']);
                    unset($r['_anzahl'], $r['_echte'], $r['_vons']);

                    return $r;
                }
                $zaehler = $r['_bis'];
                unset($r['_bis']);
                if ($zaehler !== []) {
                    ksort($zaehler, SORT_STRING);          // bei Gleichstand gewinnt die fruehere
                    arsort($zaehler, SORT_NUMERIC);        // stabil: haeufigste zuerst
                    $r['bis'] = (string) array_key_first($zaehler);
                }

                return $r;
            }, $rows));
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

    private static function norm(string $taetigkeit): string
    {
        return mb_strtolower(trim($taetigkeit));
    }

    /** @return list<array<string, mixed>> Zeitvarianten — Array (Modell-Cast) oder JSON-Text */
    private static function zeiten(mixed $v): array
    {
        if (is_string($v)) {
            $v = json_decode($v, true);
        }

        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }
}
