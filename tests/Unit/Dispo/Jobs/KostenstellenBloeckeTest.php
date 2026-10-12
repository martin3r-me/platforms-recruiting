<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\KostenstellenBloecke;

final class KostenstellenBloeckeTest extends TestCase
{
    public function testBlockIstDerHunderter(): void
    {
        $this->assertSame(400, KostenstellenBloecke::block('401', null));
        $this->assertSame(200, KostenstellenBloecke::block('200', null));
        $this->assertSame(100, KostenstellenBloecke::block(' 103 ', null));
    }

    public function testEigeneKostenstelleGehtVorDerStelle(): void
    {
        $this->assertSame(200, KostenstellenBloecke::block('201', '100'));
        $this->assertSame(100, KostenstellenBloecke::block('', '100'));
        $this->assertSame(100, KostenstellenBloecke::block(null, '100'));
    }

    public function testNichtNumerischOderLeerIstNull(): void
    {
        $this->assertNull(KostenstellenBloecke::block('MGL', null));
        $this->assertNull(KostenstellenBloecke::block(null, null));
        $this->assertNull(KostenstellenBloecke::block('', ''));
        $this->assertSame(0, KostenstellenBloecke::block('0', null));
        // Eigene Kostenstelle gesetzt, aber nicht numerisch: KEIN Rueckfall auf die Stelle.
        $this->assertNull(KostenstellenBloecke::block('MGL', '200'));
    }

    public function testOptionenGruppierenUndZaehlen(): void
    {
        $rows = [
            ['cost_center' => '401', 'position_cost_center' => null],
            ['cost_center' => '400', 'position_cost_center' => null],
            ['cost_center' => null, 'position_cost_center' => '200'],
            ['cost_center' => 'MGL', 'position_cost_center' => null],
            ['cost_center' => '', 'position_cost_center' => ''],
        ];
        $this->assertSame([
            ['block' => 200, 'nummern' => ['200'], 'count' => 1],
            ['block' => 400, 'nummern' => ['400', '401'], 'count' => 2],
            ['block' => null, 'nummern' => [], 'count' => 2],
        ], KostenstellenBloecke::optionen($rows));
    }
}
