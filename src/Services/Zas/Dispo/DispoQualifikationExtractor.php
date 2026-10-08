<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Macht aus den ZAS-Bloecken {Dispo4} (Taetigkeiten-Katalog) und {Dispo5}
 * (Zuordnung je Mitarbeiter) zwei brauchbare Strukturen. Pure — kein DB-
 * Zugriff, kein Matching auf unsere Mitarbeiter (das macht ZasDispoMatcher).
 *
 * Der Abgleich laeuft spaeter ueber den NAMEN, nicht ueber die ID: ZAS hat
 * mehrere Taetigkeiten doppelt angelegt (Logistiker = RG13 + RG271, Block
 * RG269-RG272 und RG287-RG290, gemessen 07.10.2026). Ueber den Namen fallen
 * die Dubletten zusammen; ueber die ID muesste man jedes Waeschepaket doppelt
 * pflegen.
 */
final class DispoQualifikationExtractor
{
    /**
     * Unbesetzte Plaetze der Dispo. Hartkodiert wird NUR, was nachweislich mit
     * einem echten Mitarbeiter kollidiert: 'RG14' trifft Mitarbeiter 126 und
     * traegt in {Dispo5} 782 Einsaetze auf RG8 — ohne diesen Filter bekaeme ein
     * realer Mensch fremde Qualifikationen. Andere Dummys ('RG902',
     * 'RG999999') treffen auf niemanden und laufen ohnehin als unmatched ins
     * Protokoll; sie gehoeren nicht in diese Liste.
     */
    public const PLATZHALTER_PNR = [
        'RG0', 'RG14',
        // Dieselben Platzhalter in blanker Notation: der Matcher behandelt eine
        // praefixlose Nummer als eigene Firma, '14' traefe also ebenfalls RG14 = MA 126.
        '0', '14',
    ];

    /**
     * @param list<array<string,string>> $dispo4
     * @param list<array<string,string>> $dispo5
     * @return array{katalog: array<string,string>, namen: list<string>, byPnr: array<string, list<string>>, stats: array{katalog:int, zeilen:int, platzhalter:int, ohne_katalog:int, ohne_pnr:int}}
     */
    public static function extract(array $dispo4, array $dispo5): array
    {
        $katalog = [];
        $namen = [];

        foreach ($dispo4 as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            $katalog[$code] = $name;
            $namen[mb_strtolower($name)] ??= $name;
        }

        $platzhalter = array_map('mb_strtoupper', self::PLATZHALTER_PNR);

        $byPnr = [];
        $stats = [
            'katalog'      => count($katalog),
            'zeilen'       => 0,
            'platzhalter'  => 0,
            'ohne_katalog' => 0,
            'ohne_pnr'     => 0,
        ];

        foreach ($dispo5 as $row) {
            $stats['zeilen']++;

            $pnr = trim((string) ($row['pnr'] ?? ''));
            if ($pnr === '') {
                $stats['ohne_pnr']++;
                continue;
            }
            // Platzhalter-Abgleich schreibungsunabhaengig machen.
            if (in_array(mb_strtoupper($pnr), $platzhalter, true)) {
                $stats['platzhalter']++;
                continue;
            }

            $id = trim((string) ($row['taetigkeit_id'] ?? ''));
            if ($id === '' || !isset($katalog[$id])) {
                $stats['ohne_katalog']++;
                continue;
            }

            // Namen entdoppeln — kanonische Schreibweise aus `namen` nehmen, nicht
            // den Katalognamen direkt. Erste Schreibweise gewinnt.
            $key = mb_strtolower($katalog[$id]);
            if (isset($namen[$key])) {
                $byPnr[$pnr][$key] ??= $namen[$key];
            }
        }

        foreach ($byPnr as $pnr => $liste) {
            $byPnr[$pnr] = array_values($liste);
        }

        return [
            'katalog' => $katalog,
            'namen'   => array_values($namen),
            'byPnr'   => $byPnr,
            'stats'   => $stats,
        ];
    }
}
