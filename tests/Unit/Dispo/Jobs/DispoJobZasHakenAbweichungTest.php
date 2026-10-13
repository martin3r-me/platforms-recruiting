<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasHaken;

/** „Alle markieren“: weniger gesetzt als angezeigt = inzwischen zurueckgenommen (Warnung ohne Namen). */
final class DispoJobZasHakenAbweichungTest extends TestCase
{
    public function testKeineAbweichungKeineWarnung(): void
    {
        $this->assertNull(DispoJobZasHaken::abweichung(3, 3));
        $this->assertNull(DispoJobZasHaken::abweichung(0, 2), 'ohne Erwartung (alter Aufruf) nie warnen');
        $this->assertNull(DispoJobZasHaken::abweichung(2, 3), 'mehr gesetzt als erwartet ist keine Ruecknahme');
    }

    public function testEineZurueckgenommen(): void
    {
        $this->assertSame('1 Zusage wurde inzwischen zurückgenommen — bitte in ZAS prüfen, ob du sie schon eingetragen hast.', DispoJobZasHaken::abweichung(3, 2));
    }

    public function testMehrereZurueckgenommen(): void
    {
        $this->assertSame('2 Zusagen wurden inzwischen zurückgenommen — bitte in ZAS prüfen, ob du sie schon eingetragen hast.', DispoJobZasHaken::abweichung(2, 0));
    }
}
