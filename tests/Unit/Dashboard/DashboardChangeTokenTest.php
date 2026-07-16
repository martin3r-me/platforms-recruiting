<?php

namespace Platform\Recruiting\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Dashboard\DashboardChangeToken;

class DashboardChangeTokenTest extends TestCase
{
    public function test_gleiche_inputs_ergeben_gleiches_token(): void
    {
        $a = DashboardChangeToken::build([5, '2026-07-16 10:00:00', 2, null, 0, null], [7, 3], '2026-07-16 10');
        $b = DashboardChangeToken::build([5, '2026-07-16 10:00:00', 2, null, 0, null], [7, 3], '2026-07-16 10');
        $this->assertSame($a, $b);
    }

    public function test_enriching_ids_sind_reihenfolge_unabhaengig(): void
    {
        $a = DashboardChangeToken::build([1], [3, 7], 'b');
        $b = DashboardChangeToken::build([1], [7, 3], 'b');
        $this->assertSame($a, $b);
    }

    public function test_jede_input_aenderung_aendert_das_token(): void
    {
        $base = DashboardChangeToken::build([5, 'x'], [1], 'bucket');
        $this->assertNotSame($base, DashboardChangeToken::build([6, 'x'], [1], 'bucket'), 'Counter');
        $this->assertNotSame($base, DashboardChangeToken::build([5, 'y'], [1], 'bucket'), 'Timestamp');
        $this->assertNotSame($base, DashboardChangeToken::build([5, 'x'], [1, 2], 'bucket'), 'Enriching-IDs');
        $this->assertNotSame($base, DashboardChangeToken::build([5, 'x'], [1], 'anders'), 'Zeitbucket');
    }

    public function test_null_und_leer_unterscheidbar(): void
    {
        // MAX(updated_at) auf leerer Tabelle = null; darf nicht mit 0/'' kollidieren
        $this->assertNotSame(
            DashboardChangeToken::build([0, null], [], 'b'),
            DashboardChangeToken::build([0, 0], [], 'b')
        );
        $this->assertNotSame(
            DashboardChangeToken::build([0, null], [], 'b'),
            DashboardChangeToken::build([0, ''], [], 'b')
        );
    }
}
