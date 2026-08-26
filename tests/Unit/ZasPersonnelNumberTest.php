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

    public function test_liest_die_firma_aus_der_nummer(): void
    {
        $this->assertSame('MA', ZasPersonnelNumber::prefixOf('MA1000000878'));
        $this->assertSame('RG', ZasPersonnelNumber::prefixOf('RG17944'));
        $this->assertSame('RG', ZasPersonnelNumber::prefixOf('  rg17944 '), 'Grossschreibung vereinheitlichen');
    }

    public function test_ohne_praefix_keine_firma(): void
    {
        // Wer keine Nummer oder eine blanke hat, traegt keine Firmenangabe in
        // sich — die muss dann von aussen kommen (Default oder HR-Eingabe).
        $this->assertNull(ZasPersonnelNumber::prefixOf('353'));
        $this->assertNull(ZasPersonnelNumber::prefixOf(''));
        $this->assertNull(ZasPersonnelNumber::prefixOf(null));
    }

    public function test_gekuerzte_form_zieht_eine_milliarde_ab(): void
    {
        // Die Altlast-Regel von ZAS: im Dispo-Export (und frueher auch im
        // Mitarbeiter-Export) wird oberhalb einer Milliarde gekuerzt.
        $this->assertSame('MA878', ZasPersonnelNumber::shortenedForm('MA1000000878'));
        $this->assertSame('RG17944', ZasPersonnelNumber::shortenedForm('RG1000017944'));
    }

    public function test_kurze_nummern_haben_keine_gekuerzte_form(): void
    {
        // Unter der Schwelle kuerzt ZAS nicht — wir duerfen dort nichts erfinden.
        $this->assertNull(ZasPersonnelNumber::shortenedForm('MA97933'));
        $this->assertNull(ZasPersonnelNumber::shortenedForm('RG17944'));
        $this->assertNull(ZasPersonnelNumber::shortenedForm('MA1000000000'));
        $this->assertNull(ZasPersonnelNumber::shortenedForm(''));
        $this->assertNull(ZasPersonnelNumber::shortenedForm(null));
    }

    public function test_erkennt_ob_eine_nummer_schon_einen_praefix_traegt(): void
    {
        $this->assertTrue(ZasPersonnelNumber::hasPrefix('RG353'));
        $this->assertTrue(ZasPersonnelNumber::hasPrefix('MA13'));
        $this->assertFalse(ZasPersonnelNumber::hasPrefix('353'));
        $this->assertFalse(ZasPersonnelNumber::hasPrefix(''));
    }
}
