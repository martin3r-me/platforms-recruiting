<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReport;

class WhatsAppCostReportTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['template_name' => 'reminder_de',  'is_manual' => false, 'count' => 10],
            ['template_name' => 'reminder_de',  'is_manual' => true,  'count' => 2],
            ['template_name' => 'booking_de',   'is_manual' => false, 'count' => 5],
            ['template_name' => null,           'is_manual' => true,  'count' => 1],
        ];
    }

    public function test_totals_split_and_cost(): void
    {
        $r = WhatsAppCostReport::fromRows($this->rows(), 0.055, 'EUR');

        $this->assertSame(18, $r->totalCount);
        $this->assertSame(3, $r->manualCount);
        $this->assertSame(15, $r->automaticCount);
        $this->assertSame(round(18 * 0.055, 2), $r->totalCost);
        $this->assertSame(round(3 * 0.055, 2), $r->manualCost);
        $this->assertSame(round(15 * 0.055, 2), $r->automaticCost);
        $this->assertSame('EUR', $r->currency);
    }

    public function test_breakdown_grouped_by_template_and_sorted_desc(): void
    {
        $r = WhatsAppCostReport::fromRows($this->rows(), 0.055, 'EUR');

        $this->assertCount(3, $r->templates);
        $this->assertSame('reminder_de', $r->templates[0]->templateName);
        $this->assertSame(12, $r->templates[0]->count); // 10 + 2 zusammengefasst
        $this->assertSame(round(12 * 0.055, 2), $r->templates[0]->cost);
        $this->assertSame('booking_de', $r->templates[1]->templateName);
        $this->assertSame(5, $r->templates[1]->count);
        $this->assertSame('(ohne Template)', $r->templates[2]->templateName);
    }

    public function test_empty_rows_yield_zeroes(): void
    {
        $r = WhatsAppCostReport::fromRows([], 0.055, 'EUR');

        $this->assertSame(0, $r->totalCount);
        $this->assertSame(0.0, $r->totalCost);
        $this->assertSame([], $r->templates);
    }
}
