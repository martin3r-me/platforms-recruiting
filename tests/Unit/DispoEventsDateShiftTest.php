<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Events\Index;

/**
 * Tagesweise blaettern in der VA-Uebersicht (Kunde 26.09.) — reine
 * Eigenschafts-Logik, kein Container noetig.
 */
class DispoEventsDateShiftTest extends TestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow('2026-09-28 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private function component(string $from = '', string $to = ''): Index
    {
        $c = new Index();
        $c->dateFrom = $from;
        $c->dateTo = $to;

        return $c;
    }

    public function test_einzelner_tag_springt_vor_und_zurueck(): void
    {
        $c = $this->component('2026-09-30', '2026-09-30');

        $c->shiftDates(1);
        $this->assertSame('2026-10-01', $c->dateFrom);
        $this->assertSame('2026-10-01', $c->dateTo);

        $c->shiftDates(-1);
        $this->assertSame('2026-09-30', $c->dateFrom);
        $this->assertSame('2026-09-30', $c->dateTo);
    }

    /** Ein gewaehlter Zeitraum behaelt seine Laenge. */
    public function test_zeitraum_wandert_als_ganzes(): void
    {
        $c = $this->component('2026-09-28', '2026-10-04');

        $c->shiftDates(1);

        $this->assertSame('2026-09-29', $c->dateFrom);
        $this->assertSame('2026-10-05', $c->dateTo);
    }

    /** Sonst blaettert man in die Vergangenheit und die Liste bleibt leer. */
    public function test_rueckwaerts_in_die_vergangenheit_schaltet_vergangene_ein(): void
    {
        $c = $this->component('2026-09-28', '2026-09-28');
        $this->assertFalse($c->showPast);

        $c->shiftDates(-1);

        $this->assertSame('2026-09-27', $c->dateTo);
        $this->assertTrue($c->showPast);
    }

    public function test_vorwaerts_laesst_vergangene_unberuehrt(): void
    {
        $c = $this->component('2026-09-28', '2026-09-28');

        $c->shiftDates(1);

        $this->assertFalse($c->showPast);
    }

    /** Leere Felder starten bei heute statt zu zerbrechen. */
    public function test_leere_felder_starten_bei_heute(): void
    {
        $c = $this->component('', '');

        $c->shiftDates(1);

        $this->assertSame('2026-09-29', $c->dateFrom);
        $this->assertSame('2026-09-29', $c->dateTo);
    }

    public function test_heute_springt_zurueck(): void
    {
        $c = $this->component('2026-12-24', '2026-12-31');

        $c->jumpToToday();

        $this->assertSame('2026-09-28', $c->dateFrom);
        $this->assertSame('2026-09-28', $c->dateTo);
    }
}
