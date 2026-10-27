<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersTexte;

final class JobOffersTexteTest extends TestCase
{
    public function testVorrangUndTexte(): void
    {
        $this->assertSame('dringend', JobOffersTexte::anlassVorrang(['vier_wochen', 'dringend', null]));
        $this->assertSame('naechste_woche', JobOffersTexte::anlassVorrang(['vier_wochen', 'naechste_woche']));
        $this->assertNull(JobOffersTexte::anlassVorrang([null, 'xy']));
        $this->assertSame('Wir zählen auf dich!', JobOffersTexte::fuer('dringend')['kicker']);
        $this->assertSame('Keine Garantie auf eine Einteilung.', JobOffersTexte::fuer('vier_wochen')['fussnote']);
        $this->assertSame('Wir suchen Verstärkung', JobOffersTexte::fuer(null)['kicker'], 'alte Anfragen ohne Anlass');
    }

    public function testAntwortenBis(): void
    {
        $jetzt = Carbon::parse('2026-10-27 09:00:00');
        $this->assertSame('heute 12:30 Uhr', JobOffersTexte::antwortenBis(Carbon::parse('2026-10-27 12:30:00'), $jetzt));
        $this->assertSame('morgen 08:00 Uhr', JobOffersTexte::antwortenBis(Carbon::parse('2026-10-28 08:00:00'), $jetzt));
        $this->assertSame('Fr 30.10., 12:30 Uhr', JobOffersTexte::antwortenBis(Carbon::parse('2026-10-30 12:30:00'), $jetzt));
    }
}
