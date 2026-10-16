<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPosition;

/**
 * Filiale des MA = Kostenstelle; Feld am MA vor Stelle — dieselbe Regel wie
 * die Spalte `Kostenstelle` im ZAS-Export (Vorbelegung HR -> Dispo, 16.10.2026).
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

    public function test_ohne_beides_null_und_unsinn_wird_ignoriert(): void
    {
        $this->assertNull($this->ma(null, null)->dispoFilialNr());
        $this->assertSame(100, $this->ma('DUS', 100)->dispoFilialNr(), 'Kein Zahlwert am MA → Stelle.');
    }
}
