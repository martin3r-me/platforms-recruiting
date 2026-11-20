<?php

namespace Platform\Recruiting\Services\Statistics\Mitarbeiter;

use Platform\Recruiting\Services\Zas\Dispo\DispoPhoneMatcher;

/**
 * Mitarbeiter-Statistik und Personalreport (Markus 18./19.11.2026) — pure, ohne DB.
 *
 * Regeln (mit Sebastian/Markus festgelegt):
 *  - Eine PERSON zählt einmal, auch wenn sie bei RG und MA geführt wird („kann sich ja nicht
 *    klonen“). Schlüssel: rec_person_id, sonst die Anstellung selbst.
 *  - AKTIV: mindestens eine aktive Anstellung der gewählten Firma (ZAS-Status GO oder MA).
 *  - PRODUKTIV: aktiv und mindestens eine zählende Einbuchung (GeleisteterEinsatz) in den
 *    60 Tagen bis zum Stichtag. Ist der Stichtag heute, zählen auch schon disponierte künftige
 *    Einsätze („disponierte, noch nicht beendete Termine mit beachten“).
 *  - TERMINE im Zeitraum: zählende Einbuchungen von–bis, höchstens bis heute (nur geleistete).
 *  - STUFEN als getrennte Gruppen: 0, 1–5, 6–10, 11–20, über 20.
 *  - RÜCKMELDUNG: jede eingehende WhatsApp-Nachricht im Zeitraum („Jede Rückmeldung zählt“).
 *  - KOSTENSTELLE = Niederlassung (Hunderterblock) der aktiven Anstellungen. Bei zwei
 *    Niederlassungen zählt die, in der zuletzt gearbeitet wurde, sonst die ältere Anstellung.
 *    Fehlt sie an allen aktiven Anstellungen, hilft eine inaktive derselben Person; sonst
 *    „ohne Kostenstelle“ (null).
 */
final class MitarbeiterStatistikRechner
{
    public const PRODUKTIV_TAGE = 60;

    /**
     * Gesellschafts-Filter (Markus 19.11.2026: „Es dürfen nur aktive Mitarbeiter der RG aufgeführt werden“ und
     * „Filter für die Gesellschaften … Auswahl MA und RG“). Rein nach der Firma der Anstellung: RG = bei der RG,
     * MA = bei der MA, „beide“ = beides, die Person zählt weiter einmal. Der ZAS-Status ist davon getrennt.
     */

    public const GESELLSCHAFT_RG = 'RG';
    public const GESELLSCHAFT_MA = 'MA';
    public const GESELLSCHAFT_BEIDE = 'beide';
    public const GESELLSCHAFTEN = [
        self::GESELLSCHAFT_RG => 'RG',
        self::GESELLSCHAFT_MA => 'MA',
        self::GESELLSCHAFT_BEIDE => 'RG und MA',
    ];

    /**
     * Status-Filter (Sebastian 20.11.2026): aktiv = ZAS-Status GO oder MA; wählbar „nur GO“, „nur MA“ oder beides.
     * Status „MA“ = noch dabei, aber gerade nicht abrufbar — hat nichts mit der Firma MA zu tun.
     */
    public const STATUS_GO = 'GO';
    public const STATUS_MA = 'MA';
    public const STATUS_BEIDE = 'beide';
    public const STATUSFILTER = [
        self::STATUS_BEIDE => 'GO und MA',
        self::STATUS_GO => 'GO',
        self::STATUS_MA => 'MA',
    ];

    /** Gehört die Anstellung zur gewählten Gesellschaft (Einsätze zählen mit)? */
    public static function inGesellschaft(array $a, string $gesellschaft): bool
    {
        $firma = $a['firma'] ?? null;

        return $gesellschaft === self::GESELLSCHAFT_BEIDE ? true : $firma === $gesellschaft;
    }

    /** Macht die Anstellung die Person in der gewählten Gesellschaft „aktiv“? */
    public static function aktivIn(array $a, string $gesellschaft, string $statusFilter = self::STATUS_BEIDE): bool
    {
        // Aktiv = ZAS-Status GO oder MA (Sebastian 20.11.2026: „als aktiv zählt status go und ma“); INAKTIV kommt
        // bei uns als is_active = false an. Status „MA“ (noch dabei, gerade nicht abrufbar) hat NICHTS mit der
        // Firma MA zu tun.
        if (!$a['aktiv'] || !self::inGesellschaft($a, $gesellschaft)) {
            return false;
        }
        $status = $a['status'] ?? null;
        if ($status === 'INAKTIV') {
            return false;
        }

        // Ohne gelieferten Status zählt die Anstellung nur bei „GO und MA“ (nichts verschwindet stillschweigend).
        return $statusFilter === self::STATUS_BEIDE || $status === $statusFilter;
    }

    /** Stufen-Schlüssel => [min, max|null] */
    public const STUFEN = [
        '0' => [0, 0],
        '1-5' => [1, 5],
        '6-10' => [6, 10],
        '11-20' => [11, 20],
        '21+' => [21, null],
    ];

    public const STUFEN_LABEL = [
        '0' => '0 Termine',
        '1-5' => '1–5',
        '6-10' => '6–10',
        '11-20' => '11–20',
        '21+' => 'über 20',
    ];

    /**
     * @param list<array{id:int, person:?int, aktiv:bool, kostenstelle:?int, name:string, pnr:?string, phones:list<string>}> $anstellungen
     * @param list<array{employee_id:int, datum:string}> $einsaetze nur zählende Einbuchungen
     * @param array<string,bool> $eingehendeNummern normalisierte Nummern mit eingehender Nachricht im Zeitraum
     * @param array<int,string> $letzterEinsatzAllzeit employee_id => jüngster zählender Einsatz bis heute, auch vor dem
     *        Ladezeitraum — für die Wahl der Niederlassung bei zwei Kostenstellen (Review 19.11.)
     * @return list<array<string,mixed>> eine Zeile je AKTIVER Person
     */
    public static function personen(array $anstellungen, array $einsaetze, array $eingehendeNummern, string $heute, string $von, string $bis, array $letzterEinsatzAllzeit = [], string $gesellschaft = self::GESELLSCHAFT_BEIDE, string $statusFilter = self::STATUS_BEIDE): array
    {
        $stichtag = min($bis, $heute);
        // „letzte 60 Tage“ einschließlich des Stichtags
        $produktivAb = self::minusTage($stichtag, self::PRODUKTIV_TAGE - 1);
        $termineBis = min($bis, $heute);

        // Einsätze je Anstellung
        $jeAnstellung = [];
        foreach ($einsaetze as $e) {
            $jeAnstellung[(int) $e['employee_id']][] = (string) $e['datum'];
        }

        // Anstellungen je Person
        $gruppen = [];
        foreach ($anstellungen as $a) {
            $key = $a['person'] !== null ? 'p' . $a['person'] : 'e' . $a['id'];
            $gruppen[$key][] = $a;
        }

        $out = [];
        foreach ($gruppen as $key => $liste) {
            usort($liste, fn ($x, $y) => $x['id'] <=> $y['id']);
            $aktive = array_values(array_filter($liste, fn ($a) => self::aktivIn($a, $gesellschaft, $statusFilter)));
            if ($aktive === []) {
                continue;
            }

            $termine = 0;
            $produktiv = false;
            $letzter = null;
            $naechster = null;
            $letzterJeAnstellung = [];
            foreach ($liste as $a) {
                if (!self::inGesellschaft($a, $gesellschaft)) {
                    continue; // Einsätze der anderen Gesellschaft zählen hier nicht
                }
                if (isset($letzterEinsatzAllzeit[$a['id']])) {
                    $letzterJeAnstellung[$a['id']] = (string) $letzterEinsatzAllzeit[$a['id']];
                }
                foreach ($jeAnstellung[$a['id']] ?? [] as $datum) {
                    if ($datum >= $von && $datum <= $termineBis) {
                        $termine++;
                    }
                    if ($datum >= $produktivAb && ($datum <= $stichtag || $stichtag === $heute)) {
                        $produktiv = true;
                    }
                    if ($datum <= $heute) {
                        $letzter = max($letzter ?? $datum, $datum);
                        $letzterJeAnstellung[$a['id']] = max($letzterJeAnstellung[$a['id']] ?? $datum, $datum);
                    } else {
                        $naechster = min($naechster ?? $datum, $datum);
                    }
                }
            }

            $rueckmeldung = false;
            $pnrs = [];
            foreach ($liste as $a) {
                if ($a['pnr'] !== null && $a['pnr'] !== '') {
                    $pnrs[] = $a['pnr'];
                }
                foreach ($a['phones'] as $nummer) {
                    $n = DispoPhoneMatcher::normalize($nummer);
                    if ($n !== null && isset($eingehendeNummern[$n])) {
                        $rueckmeldung = true;
                    }
                }
            }

            $out[] = [
                'person' => $key,
                'employee_id' => $aktive[0]['id'],
                'name' => $aktive[0]['name'],
                'pnr' => implode(', ', array_values(array_unique($pnrs))),
                'kostenstelle' => self::kostenstelle($aktive, $liste, $letzterJeAnstellung),
                'produktiv' => $produktiv,
                'termine' => $termine,
                'stufe' => self::stufe($termine),
                'letzter_einsatz' => $letzter,
                'naechster_einsatz' => $naechster,
                'rueckmeldung' => $rueckmeldung,
            ];
        }

        usort($out, fn ($x, $y) => [$x['name'], $x['employee_id']] <=> [$y['name'], $y['employee_id']]);

        return $out;
    }

    /**
     * Zeilen je Kostenstelle für Tabelle und Report, sortiert nach Nummer, „ohne“ am Ende.
     *
     * @param list<array<string,mixed>> $personen
     * @param array<int,int> $soll
     * @return list<array{kostenstelle:?int, soll:?int, aktiv:int, produktiv:int, abweichung:?int, stufen:array<string,int>, rueckmeldung_ja:int, rueckmeldung_nein:int}>
     */
    public static function jeKostenstelle(array $personen, array $soll): array
    {
        $zeilen = [];
        foreach ($soll as $ks => $_) {
            $zeilen[(string) $ks] = self::leereZeile((int) $ks);
        }
        foreach ($personen as $p) {
            $k = $p['kostenstelle'] === null ? 'ohne' : (string) $p['kostenstelle'];
            $zeilen[$k] ??= self::leereZeile($p['kostenstelle']);
            self::zaehle($zeilen[$k], $p);
        }

        uksort($zeilen, function ($a, $b) {
            if ($a === 'ohne' || $b === 'ohne') {
                return ($a === 'ohne') <=> ($b === 'ohne');
            }

            return (int) $a <=> (int) $b;
        });

        $out = [];
        foreach ($zeilen as $z) {
            $z['soll'] = $z['kostenstelle'] !== null ? ($soll[$z['kostenstelle']] ?? null) : null;
            $z['abweichung'] = $z['soll'] !== null ? $z['produktiv'] - $z['soll'] : null;
            $out[] = $z;
        }

        return $out;
    }

    /**
     * Gesamtzeile über alle Kostenstellen. Abweichung nur über Kostenstellen mit Soll — sonst
     * stünde ein „fehlt“ da, das nur aus nicht gepflegten Zahlen kommt.
     *
     * @param list<array<string,mixed>> $zeilen
     * @return array<string,mixed>
     */
    public static function gesamt(array $zeilen): array
    {
        $g = self::leereZeile(null);
        $sollSumme = null;
        $produktivMitSoll = 0;
        foreach ($zeilen as $z) {
            $g['aktiv'] += $z['aktiv'];
            $g['produktiv'] += $z['produktiv'];
            $g['rueckmeldung_ja'] += $z['rueckmeldung_ja'];
            $g['rueckmeldung_nein'] += $z['rueckmeldung_nein'];
            foreach ($z['stufen'] as $s => $n) {
                $g['stufen'][$s] += $n;
            }
            if ($z['soll'] !== null) {
                $sollSumme = ($sollSumme ?? 0) + $z['soll'];
                $produktivMitSoll += $z['produktiv'];
            }
        }
        $g['soll'] = $sollSumme;
        $g['abweichung'] = $sollSumme !== null ? $produktivMitSoll - $sollSumme : null;

        return $g;
    }

    public static function stufe(int $termine): string
    {
        foreach (self::STUFEN as $key => [$min, $max]) {
            if ($termine >= $min && ($max === null || $termine <= $max)) {
                return $key;
            }
        }

        return '21+';
    }

    /**
     * Passt eine Person zur Auswahl einer Zahl (Klick in Kachel/Tabelle)?
     * $kostenstelle: 'alle' | 'ohne' | Nummer; $merkmal: aktiv | produktiv | nicht_produktiv |
     * stufe:<key> | rueckmeldung:ja | rueckmeldung:nein.
     */
    public static function passt(array $p, string $kostenstelle, string $merkmal): bool
    {
        if ($kostenstelle !== 'alle') {
            $k = $p['kostenstelle'] === null ? 'ohne' : (string) $p['kostenstelle'];
            if ($k !== $kostenstelle) {
                return false;
            }
        }

        return match (true) {
            $merkmal === 'aktiv' => true,
            $merkmal === 'produktiv' => $p['produktiv'],
            $merkmal === 'nicht_produktiv' => !$p['produktiv'],
            str_starts_with($merkmal, 'stufe:') => $p['stufe'] === substr($merkmal, 6),
            $merkmal === 'rueckmeldung:ja' => $p['termine'] === 0 && $p['rueckmeldung'],
            $merkmal === 'rueckmeldung:nein' => $p['termine'] === 0 && !$p['rueckmeldung'],
            default => false,
        };
    }

    /**
     * @param list<array<string,mixed>> $aktive
     * @param list<array<string,mixed>> $alle
     * @param array<int,string> $letzterJeAnstellung
     */
    private static function kostenstelle(array $aktive, array $alle, array $letzterJeAnstellung): ?int
    {
        $mitStelle = array_values(array_filter($aktive, fn ($a) => $a['kostenstelle'] !== null));
        if ($mitStelle === []) {
            foreach ($alle as $a) {
                if ($a['kostenstelle'] !== null) {
                    return $a['kostenstelle'];
                }
            }

            return null;
        }

        $bloecke = array_unique(array_map(fn ($a) => $a['kostenstelle'], $mitStelle));
        if (count($bloecke) === 1) {
            return $mitStelle[0]['kostenstelle'];
        }

        // Zwei Niederlassungen: zuletzt gearbeitet gewinnt, sonst die ältere Anstellung
        // ($mitStelle ist nach id aufsteigend sortiert).
        $gewinner = null;
        foreach ($mitStelle as $a) {
            $datum = $letzterJeAnstellung[$a['id']] ?? null;
            if ($datum !== null && ($gewinner === null || $datum > $gewinner[0])) {
                $gewinner = [$datum, $a['kostenstelle']];
            }
        }

        return $gewinner[1] ?? $mitStelle[0]['kostenstelle'];
    }

    private static function leereZeile(?int $kostenstelle): array
    {
        return [
            'kostenstelle' => $kostenstelle,
            'soll' => null,
            'aktiv' => 0,
            'produktiv' => 0,
            'abweichung' => null,
            'stufen' => array_fill_keys(array_keys(self::STUFEN), 0),
            'rueckmeldung_ja' => 0,
            'rueckmeldung_nein' => 0,
        ];
    }

    private static function zaehle(array &$zeile, array $p): void
    {
        $zeile['aktiv']++;
        if ($p['produktiv']) {
            $zeile['produktiv']++;
        }
        $zeile['stufen'][$p['stufe']]++;
        if ($p['termine'] === 0) {
            $zeile[$p['rueckmeldung'] ? 'rueckmeldung_ja' : 'rueckmeldung_nein']++;
        }
    }

    public static function minusTage(string $ymd, int $tage): string
    {
        return (new \DateTimeImmutable($ymd))->modify('-' . $tage . ' days')->format('Y-m-d');
    }
}
