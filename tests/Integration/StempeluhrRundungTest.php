<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Platform\Recruiting\Services\Zeit\RheingedeckZeitRegeln;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitVerlauf;

/**
 * Stempeluhr mit den ECHTEN Rheingedeck-Regeln (Markus 17./18.11.2026): Check-in ab 30 Minuten vor
 * Schichtbeginn bis Schichtende, Check-out bis 4 Stunden danach, gespeichert wird die naechste
 * Viertelstunde. Schicht im Test: 01.10.2026 14:00–22:00 Ortszeit (UTC+2).
 */
class StempeluhrRundungTest extends ZeitTestCase
{
    private string $alteZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alteZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->alteZone);
        parent::tearDown();
    }

    private function uhr(): Stempeluhr
    {
        return new Stempeluhr(new RheingedeckZeitRegeln());
    }

    private function jetzt(string $ortszeit): void
    {
        Carbon::setTestNow(Carbon::parse($ortszeit, 'Europe/Berlin'));
    }

    private function eintrag(): ZeitEintrag
    {
        return ZeitEintrag::query()->firstOrFail();
    }

    public function test_kommen_wird_auf_die_viertelstunde_gerundet_die_echte_zeit_steht_am_verlauf(): void
    {
        $this->jetzt('2026-10-01 13:53:10');
        $this->assertSame('ok', $this->uhr()->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));

        // 14:00 Ortszeit = 12:00 UTC.
        $this->assertSame('2026-10-01 12:00:00', $this->eintrag()->kommen_at->format('Y-m-d H:i:s'));
        $v = ZeitVerlauf::query()->firstOrFail();
        $this->assertSame('2026-10-01 12:00:00', $v->neu);
        $this->assertSame('2026-10-01 11:53:10', $v->created_at->format('Y-m-d H:i:s'));
    }

    public function test_fenster_wird_an_der_echten_uhrzeit_geprueft_nicht_an_der_gerundeten(): void
    {
        // 13:29 wuerde auf 13:30 runden — das Fenster oeffnet aber erst um 13:30.
        $this->jetzt('2026-10-01 13:29:00');
        $this->assertSame('zu_frueh', $this->uhr()->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame(0, ZeitEintrag::count());

        $this->jetzt('2026-10-01 13:31:00');
        $this->assertSame('ok', $this->uhr()->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame('2026-10-01 11:30:00', $this->eintrag()->kommen_at->format('Y-m-d H:i:s'));
    }

    public function test_selbst_einchecken_endet_mit_dem_schichtende_stellvertretend_nicht(): void
    {
        $this->jetzt('2026-10-01 22:01:00');
        $this->assertSame('zu_spaet', $this->uhr()->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame('ok', $this->uhr()->kommen($this->bezug(), $this->akteur('user', 5, 'Tina Teamleiterin'), Stempeluhr::ART_STELLVERTRETEND));
        $this->assertSame('2026-10-01 20:00:00', $this->eintrag()->kommen_at->format('Y-m-d H:i:s'));
    }

    public function test_selbst_auschecken_bis_vier_stunden_nach_schichtende(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('2026-10-01 14:00:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);

        $this->jetzt('2026-10-02 02:00:01');
        $this->assertSame('zu_spaet', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertNull($this->eintrag()->gehen_at);

        $this->jetzt('2026-10-02 01:58:00');
        $this->assertSame('ok', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        // 02:00 Ortszeit = 00:00 UTC.
        $this->assertSame('2026-10-02 00:00:00', $this->eintrag()->gehen_at->format('Y-m-d H:i:s'));
    }

    public function test_gehen_in_derselben_viertelstunde_wie_kommen_ist_gueltig(): void
    {
        $uhr = $this->uhr();
        // Kommen 14:07 -> 14:00; Gehen 14:05 echt liegt VOR dem echten Kommen nicht, aber gerundet gleich: 14:10 -> 14:15.
        $this->jetzt('2026-10-01 14:07:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $this->assertSame('2026-10-01 12:00:00', $this->eintrag()->kommen_at->format('Y-m-d H:i:s'));

        $this->jetzt('2026-10-01 14:07:20');
        $this->assertSame('ok', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame('2026-10-01 12:00:00', $this->eintrag()->gehen_at->format('Y-m-d H:i:s'));
    }

    public function test_korrektur_wird_ebenfalls_gerundet(): void
    {
        $uhr = $this->uhr();
        $status = $uhr->korrigieren(
            $this->bezug(),
            $this->akteur('user', 5, 'Tina Teamleiterin'),
            ['kommen_at' => '2026-10-01 12:10:00', 'gehen_at' => '2026-10-01 20:22:00'],
            'Teamleiter bestaetigt',
        );
        $this->assertSame('ok', $status);
        $e = $this->eintrag();
        $this->assertSame('2026-10-01 12:15:00', $e->kommen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 20:15:00', $e->gehen_at->format('Y-m-d H:i:s'));

        // Derselbe Wert noch einmal (gerundet identisch) ist keine Aenderung.
        $this->assertSame('unveraendert', $uhr->korrigieren($this->bezug(), $this->akteur('user', 5, 'T'), ['kommen_at' => '2026-10-01 12:14:00'], 'nochmal'));
    }
}
