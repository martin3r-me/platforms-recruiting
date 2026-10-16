<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPosition;

/**
 * Filiale des MA = Hunderterblock der Kostenstelle; Feld am MA vor Stelle —
 * gleiche Regel wie KostenstellenBloecke (Vorbelegung HR -> Dispo, 16.10.2026).
 */
class RecEmployeeDispoFilialNrTest extends TestCase
{
    private function ma(?string $eigene, ?int $stelle): RecEmployee
    {
        $ma = new RecEmployee();
        $ma->setRawAttributes(['cost_center' => $eigene]);
        $ma->setRelation('position', $stelle === null ? null : tap(new RecPosition(), fn ($p) => $p->setRawAttributes(['cost_center' => $stelle])));

        return $ma;
    }

    public function test_feld_am_ma_gewinnt(): void
    {
        $this->assertSame(400, $this->ma('400', 200)->dispoFilialNr());
    }

    public function test_sonst_die_stelle(): void
    {
        $this->assertSame(200, $this->ma(null, 200)->dispoFilialNr());
        $this->assertSame(200, $this->ma('  ', 200)->dispoFilialNr());
    }

    public function test_unternummern_landen_im_hunderterblock(): void
    {
        // Prod-Befund 12.10.2026: 101–103 = DUS & ES, 201–203 = MGL, 401–403 = CGN.
        $this->assertSame(100, $this->ma('103', null)->dispoFilialNr());
        $this->assertSame(200, $this->ma(null, 201)->dispoFilialNr());
        $this->assertSame(400, $this->ma('0402', null)->dispoFilialNr());
    }

    public function test_ohne_beides_null_und_unbrauchbarer_wert_faellt_nicht_auf_die_stelle(): void
    {
        $this->assertNull($this->ma(null, null)->dispoFilialNr());
        $this->assertNull($this->ma('DUS', 100)->dispoFilialNr(), 'Gesetzt, aber unbrauchbar — wie KostenstellenBloecke.');
    }
}
