<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TriggerRegeln;

/**
 * Wann geht eine Nachricht raus — und wann bewusst nicht. Rein, kein
 * Framework, keine Uhr: Jetzt wird hereingereicht.
 */
final class TriggerRegelnTest extends TestCase
{
    public function test_ohne_offene_punkte_gibt_es_keine_signatur(): void
    {
        $this->assertSame('', TriggerRegeln::signatur([]));
    }

    public function test_dieselben_punkte_ergeben_dieselbe_signatur(): void
    {
        $a = TriggerRegeln::signatur([['code' => 'ausweis'], ['code' => 'immatrikulation']]);
        $b = TriggerRegeln::signatur([['code' => 'ausweis'], ['code' => 'immatrikulation']]);

        $this->assertSame($a, $b);
    }

    public function test_die_reihenfolge_aendert_die_signatur_nicht(): void
    {
        // Sonst meldete eine andere Sortierung dieselben Punkte als neu.
        $a = TriggerRegeln::signatur([['code' => 'ausweis'], ['code' => 'immatrikulation']]);
        $b = TriggerRegeln::signatur([['code' => 'immatrikulation'], ['code' => 'ausweis']]);

        $this->assertSame($a, $b);
    }

    public function test_ein_zusaetzlicher_punkt_aendert_die_signatur(): void
    {
        $a = TriggerRegeln::signatur([['code' => 'ausweis']]);
        $b = TriggerRegeln::signatur([['code' => 'ausweis'], ['code' => 'aufenthaltstitel']]);

        $this->assertNotSame($a, $b);
    }

    public function test_beim_ersten_mal_wird_gemeldet(): void
    {
        $this->assertTrue(TriggerRegeln::darfMelden(null, null, 'abc', '2026-10-01 09:00:00', 7));
    }

    public function test_dieselben_punkte_werden_nicht_erneut_gemeldet(): void
    {
        $this->assertFalse(TriggerRegeln::darfMelden('abc', '2026-09-01 09:00:00', 'abc', '2026-10-01 09:00:00', 7));
    }

    public function test_neue_punkte_werden_gemeldet_wenn_die_pause_um_ist(): void
    {
        $this->assertTrue(TriggerRegeln::darfMelden('abc', '2026-09-20 09:00:00', 'xyz', '2026-10-01 09:00:00', 7));
    }

    public function test_neue_punkte_warten_bis_die_pause_um_ist(): void
    {
        // Sieben Tage Pause: am sechsten Tag noch nicht.
        $this->assertFalse(TriggerRegeln::darfMelden('abc', '2026-09-25 09:00:00', 'xyz', '2026-10-01 09:00:00', 7));
    }

    /**
     * Korrektur ET-6 (nachgemessen): die urspruengliche Grenze lag am
     * 23.09. — acht Tage vor dem 01.10., aber auf beiden Seiten eines
     * `<`/`<=`-Vergleichs dasselbe Ergebnis (23.09. +7 Tage = 30.09.,
     * klar vor dem 01.10.). Der tatsaechliche Grenzfall ist der GENAU
     * siebte Tag: 24.09. +7 Tage = 01.10., exakt gleich "jetzt".
     */
    public function test_nach_genau_sieben_tagen_ist_die_pause_um(): void
    {
        $this->assertTrue(TriggerRegeln::darfMelden('abc', '2026-09-24 09:00:00', 'xyz', '2026-10-01 09:00:00', 7));
    }

    public function test_ohne_offene_punkte_wird_nie_gemeldet(): void
    {
        $this->assertFalse(TriggerRegeln::darfMelden('abc', '2026-09-01 09:00:00', '', '2026-10-01 09:00:00', 7));
    }

    // --- Nachbesserungsrunde 1 (Pruefbericht task-6-7-review.md) ---------

    /**
     * TR-2: die Zusage "64 Zeichen Hex" ist tragend, weil die Spalte
     * rec_persons.aufgaben_signatur ein string(64) ist (Migration
     * 2026_10_01_000001_add_trigger_state.php). Bisher verglichen alle
     * Signatur-Tests nur zwei Ausgaben MITEINANDER — ein `hash('sha512', …)`
     * (128 Zeichen) haette keinem Test widersprochen.
     */
    public function test_die_signatur_ist_64_zeichen_hex(): void
    {
        $signatur = TriggerRegeln::signatur([['code' => 'ausweis']]);

        $this->assertSame(64, strlen($signatur));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signatur);
    }

    /**
     * TR-7: ohne `array_filter` liefert ein leerer/fehlender Code einen
     * Hash statt der leeren Signatur — und `darfMelden()` wuerde dann ueber
     * NULL offene Punkte melden.
     */
    public function test_nur_leere_codes_ergeben_weiterhin_die_leere_signatur(): void
    {
        $this->assertSame('', TriggerRegeln::signatur([[], ['code' => '']]));
    }

    /**
     * TR-3: darfMelden() muss den uebergebenen pauseTage-Wert benutzen,
     * nicht die 7 aus den uebrigen Tests fest verdrahten. Mit pauseTage=3
     * ist die Pause nach drei Tagen um — ein hartcodiertes "+7 days" waere
     * hier noch nicht so weit.
     */
    public function test_eine_andere_pause_als_sieben_tage_wird_tatsaechlich_benutzt(): void
    {
        $this->assertTrue(TriggerRegeln::darfMelden(
            'abc', '2026-09-28 09:00:00', 'xyz', '2026-10-01 09:00:00', 3
        ));
    }

    /**
     * TR-4: ein leerer String (nicht nur null) in gemeldetAm muss ebenfalls
     * als "noch nie gemeldet" gelten — z.B. wenn das Feld zwar existiert,
     * aber nie befuellt wurde.
     */
    public function test_ein_leerer_zeitstempel_gilt_wie_kein_zeitstempel(): void
    {
        $this->assertTrue(TriggerRegeln::darfMelden('abc', '', 'xyz', '2026-10-01 09:00:00', 7));
    }

    /**
     * TR-5 / ET-14-Entscheidung: ein unlesbarer gemeldetAm-Zeitstempel darf
     * nicht zu dauerhaftem Schweigen fuehren (gemeldetAm wird nur bei einem
     * erfolgreichen Versand ueberschrieben, siehe Docblock). Er gilt deshalb
     * als "Pause ist um" und es wird gemeldet.
     */
    public function test_ein_unlesbarer_zeitstempel_gilt_als_pause_um(): void
    {
        $this->assertTrue(TriggerRegeln::darfMelden(
            'abc', 'Schrottwert', 'xyz', '2026-10-01 09:00:00', 7
        ));
    }
}
