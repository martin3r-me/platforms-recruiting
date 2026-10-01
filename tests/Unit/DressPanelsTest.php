<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DressPanels;

/**
 * Welcher Kleidungs-Kasten auf der Einsatz-Seite steht — ein gemeinsamer im
 * VA-Kopf oder einer je Tag.
 */
class DressPanelsTest extends TestCase
{
    public function test_without_packages_the_zas_text_stays_as_today(): void
    {
        $r = DressPanels::build([1 => null, 2 => null], 'Bitte schwarze Hose', 'Hinweis');

        $this->assertSame(['heading' => 'Kleidung / Infos', 'text' => 'Bitte schwarze Hose'], $r['group']);
        $this->assertSame([], $r['perDay']);
        $this->assertNull($r['hinweis'], 'Ohne Paket bleibt die Seite unveraendert.');
    }

    public function test_one_package_for_all_days_replaces_the_zas_text(): void
    {
        $r = DressPanels::build([1 => 'Hoodie; Sicherheitsschuhe', 2 => 'Hoodie; Sicherheitsschuhe'], 'Bitte schwarze Hose', 'Ausweis mitnehmen');

        $this->assertSame(['heading' => 'Deine Kleidung', 'text' => 'Hoodie; Sicherheitsschuhe'], $r['group']);
        $this->assertSame([], $r['perDay']);
        $this->assertSame('Ausweis mitnehmen', $r['hinweis']);
    }

    public function test_different_packages_per_day_move_the_panel_into_the_day_row(): void
    {
        $r = DressPanels::build([1 => 'weisses Hemd', 2 => 'Hoodie'], 'Bitte schwarze Hose', null);

        $this->assertNull($r['group']);
        $this->assertSame([
            1 => ['heading' => 'Deine Kleidung', 'text' => 'weisses Hemd'],
            2 => ['heading' => 'Deine Kleidung', 'text' => 'Hoodie'],
        ], $r['perDay']);
    }

    public function test_a_day_without_package_keeps_the_zas_text(): void
    {
        $r = DressPanels::build([1 => 'Hoodie', 2 => null], 'Bitte schwarze Hose', null);

        $this->assertNull($r['group']);
        $this->assertSame([
            1 => ['heading' => 'Deine Kleidung', 'text' => 'Hoodie'],
            2 => ['heading' => 'Kleidung / Infos', 'text' => 'Bitte schwarze Hose'],
        ], $r['perDay'], 'Ein Tag ohne Paket verliert den ZAS-Text nicht.');
    }

    public function test_empty_texts_produce_no_panel_at_all(): void
    {
        $r = DressPanels::build([1 => null], '   ', '  ');

        $this->assertNull($r['group']);
        $this->assertSame([], $r['perDay']);
        $this->assertNull($r['hinweis']);
    }
}
