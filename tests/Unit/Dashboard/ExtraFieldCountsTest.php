<?php

namespace Platform\Recruiting\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Dashboard\ExtraFieldCounts;

class ExtraFieldCountsTest extends TestCase
{
    public function test_zaehlt_gefuellte_und_gesamt(): void
    {
        $this->assertSame(
            ['filled' => 2, 'total' => 3],
            ExtraFieldCounts::forApplicant([1, 2, 3], [1 => 'Köln', 2 => 42.0, 3 => null])
        );
    }

    public function test_leere_werte_zaehlen_nicht(): void
    {
        $this->assertSame(
            ['filled' => 0, 'total' => 4],
            ExtraFieldCounts::forApplicant([1, 2, 3, 4], [1 => null, 2 => '', 3 => [], 4 => '[]'])
        );
    }

    public function test_werte_ohne_definition_zaehlen_nicht(): void
    {
        // Wert zu einer Definition, die nicht (mehr) gilt → ignoriert
        $this->assertSame(
            ['filled' => 1, 'total' => 1],
            ExtraFieldCounts::forApplicant([1], [1 => 'x', 99 => 'verwaist'])
        );
    }

    public function test_falsy_aber_gefuellte_werte_zaehlen(): void
    {
        // 0, false, '0' sind echte Werte (heutige Semantik: nur null/''/[]/'[]' sind leer)
        $this->assertSame(
            ['filled' => 3, 'total' => 3],
            ExtraFieldCounts::forApplicant([1, 2, 3], [1 => 0.0, 2 => false, 3 => '0'])
        );
    }

    public function test_keine_definitionen(): void
    {
        $this->assertSame(['filled' => 0, 'total' => 0], ExtraFieldCounts::forApplicant([], []));
    }
}
