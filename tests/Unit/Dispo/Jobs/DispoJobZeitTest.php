<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit;

final class DispoJobZeitTest extends TestCase
{
    public function testSpanneZeigtBeginnBisEndeOderNurBeginn(): void
    {
        $this->assertSame('16:00–23:00', DispoJobZeit::spanne('16:00', '23:00'));
        $this->assertSame('16:00', DispoJobZeit::spanne('16:00', ''));
        $this->assertSame('16:00', DispoJobZeit::spanne(' 16:00 ', null));
        $this->assertSame('', DispoJobZeit::spanne('', '23:00'), 'ohne Beginn keine Spanne');
        $this->assertSame('', DispoJobZeit::spanne(null, null));
    }

    public function testProblemBeiUngueltigerOderVerdrehterZeit(): void
    {
        $this->assertNull(DispoJobZeit::problem('16:00', '23:00'));
        $this->assertNull(DispoJobZeit::problem('16:00', ''), 'Ende ist optional');
        $this->assertNull(DispoJobZeit::problem('', ''), 'beides leer ist erlaubt');
        $this->assertSame('Der Beginn muss als Uhrzeit (HH:MM) angegeben werden.', DispoJobZeit::problem('16h', ''));
        $this->assertSame('Das Ende muss als Uhrzeit (HH:MM) angegeben werden.', DispoJobZeit::problem('16:00', '23'));
        $this->assertNull(DispoJobZeit::problem('16:00', '10:00'), 'Ende vor Beginn = Nachtschicht');
        $this->assertNull(DispoJobZeit::problem('18:00', '02:00'), 'Nachtschicht ueber Mitternacht');
        $this->assertSame('Beginn und Ende dürfen nicht gleich sein.', DispoJobZeit::problem('16:00', '16:00'));
        $this->assertNull(DispoJobZeit::problem('', '23:00'), 'Ende ohne Beginn: kein Vergleich moeglich, kein Fehler');
    }
}
