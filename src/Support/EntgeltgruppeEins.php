<?php

namespace Platform\Recruiting\Support;

/**
 * Entgeltgruppe 1 des Tarifwerks DGB/GVP (Stand Januar 2026), Anlage zum
 * Entgelttarifvertrag — der Grundlohn jeder MA-Anstellung (Markus 16.10.2026,
 * E15: "es gilt fuer alle Mitarbeiter EG 1").
 *
 * DATIERT, NICHT EINSTELLBAR: Der Tarif ist oeffentlich und hat feste Stufen.
 * Ein frei editierbarer Settings-Wert ohne Datum stuende im April 2027 still
 * falsch da. Neue Stufe = neue Zeile hier + Neu-Export der MA-Zeilen
 * (recruiting:lohn-ma-export --markieren), siehe Deploy-Checkliste.
 *
 * Reine Logik (kein Framework/DB).
 */
final class EntgeltgruppeEins
{
    /** @var list<array{ab:string, satz:float}> aufsteigend nach `ab` */
    public const STUFEN = [
        ['ab' => '2026-01-01', 'satz' => 14.96],
        ['ab' => '2026-09-01', 'satz' => 15.33],
        ['ab' => '2027-04-01', 'satz' => 15.87],
    ];

    /** Stundensatz am Stichtag (Y-m-d, Uhrzeit wird ignoriert); vor der ersten Stufe gilt die erste. */
    public static function satz(string $datum): float
    {
        return self::stufe($datum)['satz'];
    }

    /** Beginn der am Stichtag gueltigen Stufe (Y-m-d) — fuer Anzeige "EG 1 gilt seit". */
    public static function gueltigAb(string $datum): string
    {
        return self::stufe($datum)['ab'];
    }

    /** @return array{ab:string, satz:float} */
    private static function stufe(string $datum): array
    {
        $tag = substr($datum, 0, 10);
        $treffer = self::STUFEN[0];
        foreach (self::STUFEN as $stufe) {
            if ($stufe['ab'] <= $tag) {
                $treffer = $stufe;
            }
        }

        return $treffer;
    }
}
