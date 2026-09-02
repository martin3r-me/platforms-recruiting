<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasColumnProfiler;

class ZasColumnProfilerTest extends TestCase
{
    private ZasColumnProfiler $profiler;

    protected function setUp(): void
    {
        $this->profiler = new ZasColumnProfiler();
    }

    public function test_counts_filled_and_ratio(): void
    {
        $rows = [
            ['Kunde' => 'Broich', 'Ort' => ''],
            ['Kunde' => 'EFP',    'Ort' => 'Wuppertal'],
            ['Kunde' => '',       'Ort' => '   '],
            ['Kunde' => 'Broich', 'Ort' => 'Koeln'],
        ];

        $profile = $this->profiler->profile(['Kunde', 'Ort'], $rows);

        $this->assertSame('Kunde', $profile[0]['column']);
        $this->assertSame(3, $profile[0]['filled']);
        $this->assertSame(0.75, $profile[0]['fill_ratio']);
        $this->assertSame(2, $profile[1]['filled']); // Whitespace-only zaehlt als leer
    }

    public function test_examples_are_deduped_and_capped(): void
    {
        $rows = [
            ['R' => 'Koch'], ['R' => 'Koch'], ['R' => 'Service'],
            ['R' => 'Logistik'], ['R' => 'Spueler'],
        ];

        $profile = $this->profiler->profile(['R'], $rows);

        $this->assertSame(['Koch', 'Service', 'Logistik'], $profile[0]['examples']); // max 3, dedupliziert
    }

    public function test_missing_column_key_counts_as_empty(): void
    {
        $profile = $this->profiler->profile(['A', 'Fehlt'], [['A' => 'x']]);
        $this->assertSame(0, $profile[1]['filled']);
    }

    public function test_empty_rows_give_zero_ratio(): void
    {
        $profile = $this->profiler->profile(['A'], []);
        $this->assertSame(0, $profile[0]['filled']);
        $this->assertSame(0.0, $profile[0]['fill_ratio']);
    }
}
