<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardTimeline;

class ForwardTimelineTest extends TestCase
{
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $r) => $r['id'], $rows);
    }

    public function test_ohne_karten_unveraendert(): void
    {
        $rows = [['id' => 'a', 'ts' => 10], ['id' => 'b', 'ts' => 20]];
        $this->assertSame($rows, ForwardTimeline::insert($rows, []));
    }

    public function test_karte_landet_nach_gleichzeitigen_und_vor_spaeteren(): void
    {
        $rows = [['id' => 'a', 'ts' => 10], ['id' => 'b', 'ts' => 20], ['id' => 'c', 'ts' => 30]];
        $out = ForwardTimeline::insert($rows, [['id' => 'K', 'ts' => 20]]);
        $this->assertSame(['a', 'b', 'K', 'c'], self::ids($out));
    }

    public function test_spaete_karte_ans_ende_mehrere_karten_sortiert(): void
    {
        $rows = [['id' => 'a', 'ts' => 10]];
        $out = ForwardTimeline::insert($rows, [['id' => 'K2', 'ts' => 99], ['id' => 'K1', 'ts' => 5]]);
        $this->assertSame(['K1', 'a', 'K2'], self::ids($out));
    }

    public function test_bestandsreihenfolge_bleibt_auch_bei_unsortierten_ts(): void
    {
        $rows = [['id' => 'a', 'ts' => 30], ['id' => 'b', 'ts' => 10], ['id' => 'c', 'ts' => 40]];
        $out = ForwardTimeline::insert($rows, [['id' => 'K', 'ts' => 20]]);
        $bestand = array_values(array_filter(self::ids($out), static fn ($id) => $id !== 'K'));
        $this->assertSame(['a', 'b', 'c'], $bestand);
        $this->assertCount(4, $out);
    }
}
