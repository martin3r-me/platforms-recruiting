<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * Firmen-Praefix an der ZAS-Personalnummer.
 *
 * ZAS bedient zwei Firmen, RG und MA. Die Dispo-Lieferung traegt den Praefix
 * seit jeher (`RG353`), der Mitarbeiter-Export bisher nicht (`353`) — und weil
 * beide Firmen dieselben Ziffernfolgen vergeben (belegt: 276, 322, 325, 353),
 * war die Nummer bei uns nicht eindeutig. Ergebnis: Einsaetze der MA-Person
 * landeten beim gleichnamigen RG-Mitarbeiter.
 *
 * Michel stellt den Mitarbeiter-Export auf die Praefix-Form um. Bis das
 * passiert, kommen weiter blanke Nummern — deshalb normalisieren WIR beim
 * Import. Damit gibt es keinen Stichtag: vorher wie nachher steht bei uns die
 * praefixte Form.
 *
 * Ein FREMDER Praefix wird nie ueberschrieben. Kommt `MA353`, bleibt es
 * `MA353` — sonst wuerden wir eine MA-Person zu einer RG-Person erklaeren.
 */
class ZasPersonnelNumberTest extends TestCase
{
    public function test_blanke_nummer_bekommt_den_eigenen_praefix(): void
    {
        $this->assertSame('RG353', ZasPersonnelNumber::normalize('353', 'RG'));
    }

    public function test_eigener_praefix_bleibt_unveraendert(): void
    {
        $this->assertSame('RG353', ZasPersonnelNumber::normalize('RG353', 'RG'));
    }

    public function test_fremder_praefix_wird_nicht_ueberschrieben(): void
    {
        // Eine MA-Person darf niemals zur RG-Person werden.
        $this->assertSame('MA353', ZasPersonnelNumber::normalize('MA353', 'RG'));
    }

    public function test_leere_werte_bleiben_leer(): void
    {
        $this->assertNull(ZasPersonnelNumber::normalize(null, 'RG'));
        $this->assertNull(ZasPersonnelNumber::normalize('', 'RG'));
        $this->assertNull(ZasPersonnelNumber::normalize('   ', 'RG'));
    }

    public function test_umgebende_leerzeichen_stoeren_nicht(): void
    {
        $this->assertSame('RG353', ZasPersonnelNumber::normalize('  353 ', 'RG'));
    }

    public function test_ohne_konfigurierten_praefix_bleibt_alles_wie_geliefert(): void
    {
        // Notausgang: leerer Config-Wert schaltet die Normalisierung ab.
        $this->assertSame('353', ZasPersonnelNumber::normalize('353', ''));
    }

    public function test_erkennt_ob_eine_nummer_schon_einen_praefix_traegt(): void
    {
        $this->assertTrue(ZasPersonnelNumber::hasPrefix('RG353'));
        $this->assertTrue(ZasPersonnelNumber::hasPrefix('MA13'));
        $this->assertFalse(ZasPersonnelNumber::hasPrefix('353'));
        $this->assertFalse(ZasPersonnelNumber::hasPrefix(''));
    }
}
