<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;

/**
 * Normalisierung der Vergleichsschluessel fuer die Dublettenpruefung.
 *
 * Der Telefon-Teil ist der kritische: verglichen wird die NATIONALE
 * Rufnummer, nicht die E164-Form. Sonst matcht der gespeicherte Wert
 * "0176 1234567" nicht gegen den gelieferten "+49 176 1234567" — beides
 * dieselbe Person. Naives Ziffernfiltern scheitert genau daran (fuehrende
 * 0 gegen 49), das ist im Modul schon einmal aufgeschlagen.
 */
class ZasInboundDuplicateFinderNormalizationTest extends TestCase
{
    public function test_nationale_und_internationale_schreibweise_ergeben_denselben_schluessel(): void
    {
        $a = ZasInboundDuplicateFinder::normalizePhone('0176 1234567');
        $b = ZasInboundDuplicateFinder::normalizePhone('+49 176 1234567');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
    }

    public function test_trennzeichen_sind_egal(): void
    {
        $this->assertSame(
            ZasInboundDuplicateFinder::normalizePhone('0176/123-45 67'),
            ZasInboundDuplicateFinder::normalizePhone('01761234567')
        );
    }

    public function test_unbrauchbare_nummer_ergibt_keinen_schluessel(): void
    {
        // Kein Raten mit Muell: wer nicht parsebar ist, wird nicht verglichen.
        $this->assertNull(ZasInboundDuplicateFinder::normalizePhone(''));
        $this->assertNull(ZasInboundDuplicateFinder::normalizePhone('123'));
        $this->assertNull(ZasInboundDuplicateFinder::normalizePhone('keine Nummer'));
    }

    public function test_email_wird_klein_und_getrimmt(): void
    {
        $this->assertSame(
            'marie@example.com',
            ZasInboundDuplicateFinder::normalizeEmail('  Marie@Example.COM ')
        );
        $this->assertNull(ZasInboundDuplicateFinder::normalizeEmail('   '));
    }

    public function test_iban_ohne_leerzeichen_und_gross(): void
    {
        $this->assertSame(
            'DE85430400360217904200',
            ZasInboundDuplicateFinder::normalizeIban('de85 4304 0036 0217 9042 00')
        );
        $this->assertNull(ZasInboundDuplicateFinder::normalizeIban(''));
    }
}
