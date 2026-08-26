<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoMatcher;

/**
 * PNr-Matching mit Firmen-Praefix.
 *
 * Frueher: exakter Vergleich, sonst Rueckfall auf die blanken Ziffern. Der
 * Rueckfall war noetig, solange unsere Nummern keinen Praefix trugen — er hat
 * aber `MA353` auf einen RG-Mitarbeiter mit Nummer 353 gezogen, weil beide
 * Firmen dieselben Ziffernfolgen vergeben (belegt: 276, 322, 325, 353). Damit
 * hingen fremde Einsaetze an unseren Leuten.
 *
 * Jetzt: der Praefix entscheidet. Eine blanke Nummer — egal auf welcher Seite —
 * gilt als die EIGENE Firma; das ist dieselbe Annahme, mit der auch der
 * Mitarbeiter-Import normalisiert. Ein fremder Praefix trifft nie.
 */
class ZasDispoMatcherTest extends TestCase
{
    private function matcher(array $byPnr, string $ownPrefix = 'RG'): ZasDispoMatcher
    {
        return new ZasDispoMatcher($byPnr, $ownPrefix);
    }

    public function test_exakter_treffer(): void
    {
        $this->assertSame(
            ['employee_id' => 7, 'reason' => 'exact'],
            $this->matcher(['RG14' => 7])->match('RG14')
        );
    }

    public function test_fremder_praefix_trifft_nicht(): void
    {
        // Der eigentliche Fehler, der behoben wird.
        $this->assertSame(
            ['employee_id' => null, 'reason' => 'none'],
            $this->matcher(['RG353' => 7])->match('MA353')
        );
    }

    public function test_von_hand_eingetragene_blanke_nummer_trifft_eigenen_praefix(): void
    {
        // HR traegt die Nummer im Backend blank ein; die Dispo liefert sie
        // praefixt. Blank gilt als eigene Firma, also Treffer.
        $this->assertSame(
            ['employee_id' => 9, 'reason' => 'own_prefix'],
            $this->matcher(['353' => 9])->match('RG353')
        );
    }

    public function test_blanke_nummer_trifft_nicht_bei_fremdem_praefix(): void
    {
        // Genau hier lag der Schaden: 'MA353' darf den blanken 353 NICHT holen.
        $this->assertSame(
            ['employee_id' => null, 'reason' => 'none'],
            $this->matcher(['353' => 9])->match('MA353')
        );
    }

    public function test_blanke_lieferung_trifft_praefixten_bestand(): void
    {
        // Umgekehrte Richtung, gleiche Annahme: ohne Praefix = eigene Firma.
        $this->assertSame(
            ['employee_id' => 7, 'reason' => 'own_prefix'],
            $this->matcher(['RG14' => 7])->match('14')
        );
    }

    public function test_exakter_treffer_gewinnt_vor_dem_praefix_rueckfall(): void
    {
        // Liegen beide Formen vor, entscheidet der exakte Vergleich — sonst
        // haenge die Zuordnung von der Reihenfolge der Map ab.
        $matcher = $this->matcher(['353' => 9, 'RG353' => 7]);

        $this->assertSame(['employee_id' => 7, 'reason' => 'exact'], $matcher->match('RG353'));
        $this->assertSame(['employee_id' => 9, 'reason' => 'exact'], $matcher->match('353'));
    }

    public function test_unbekannte_nummer(): void
    {
        $this->assertSame(
            ['employee_id' => null, 'reason' => 'none'],
            $this->matcher(['RG14' => 7])->match('RG999')
        );
    }

    public function test_leere_nummer(): void
    {
        $matcher = $this->matcher(['RG14' => 7]);
        $this->assertSame(['employee_id' => null, 'reason' => 'empty'], $matcher->match(''));
        $this->assertSame(['employee_id' => null, 'reason' => 'empty'], $matcher->match(null));
    }

    public function test_praefix_ohne_ziffern_trifft_nicht(): void
    {
        $this->assertSame(
            ['employee_id' => null, 'reason' => 'none'],
            $this->matcher(['RG14' => 7])->match('RG')
        );
    }
}
