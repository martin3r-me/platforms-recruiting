<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonGroupPlanner;

final class PersonGroupPlannerTest extends TestCase
{
    /** @return array{id:int, person_key:?string, phone:?string, updated_at:?string} */
    private function ma(int $id, ?string $key, ?string $phone, ?string $updated = '2026-01-01 00:00:00'): array
    {
        return ['id' => $id, 'person_key' => $key, 'phone' => $phone, 'updated_at' => $updated];
    }

    public function test_gleicher_marker_wird_eine_gruppe(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, 'p-1', '+4915112345678'),
            $this->ma(2, 'p-1', '+4915112345678'),
        ]);

        $this->assertCount(1, $r['gruppen']);
        $this->assertSame([1, 2], $r['gruppen'][0]['ids']);
    }

    public function test_ohne_marker_ist_jeder_seine_eigene_gruppe(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, null, '+4915112345678'),
            $this->ma(2, '', '+4915112345678'),
        ]);

        $this->assertCount(2, $r['gruppen']);
        $this->assertSame([1], $r['gruppen'][0]['ids']);
        $this->assertSame([2], $r['gruppen'][1]['ids']);
    }

    public function test_juengste_nummer_gewinnt(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, 'p-1', '+4915111111111', '2026-01-01 00:00:00'),
            $this->ma(2, 'p-1', '+4915122222222', '2026-06-01 00:00:00'),
        ]);

        $this->assertSame('+4915122222222', $r['gruppen'][0]['phone']);
        $this->assertTrue($r['gruppen'][0]['phone_uneinig'], 'zwei verschiedene Nummern muessen an HR gemeldet werden');
    }

    public function test_leere_nummer_gewinnt_nie(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, 'p-1', '+4915111111111', '2026-01-01 00:00:00'),
            $this->ma(2, 'p-1', null, '2026-06-01 00:00:00'),
        ]);

        $this->assertSame('+4915111111111', $r['gruppen'][0]['phone']);
        $this->assertFalse($r['gruppen'][0]['phone_uneinig'], 'eine fehlende Nummer ist kein Streit');
    }

    public function test_dieselbe_nummer_anders_geschrieben_ist_kein_streit(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(1, 'p-1', '+49 152 12345678'),
            $this->ma(2, 'p-1', '015212345678'),
        ]);

        $this->assertFalse($r['gruppen'][0]['phone_uneinig']);
    }

    public function test_gleicher_zeitstempel_kleinere_kennung_gewinnt(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(2, 'p-1', '+4915122222222', '2026-06-01 00:00:00'),
            $this->ma(1, 'p-1', '+4915111111111', '2026-06-01 00:00:00'),
        ]);

        $this->assertSame('+4915111111111', $r['gruppen'][0]['phone'], 'der Lauf muss wiederholbar sein');
    }

    public function test_gruppen_sind_nach_kleinster_kennung_sortiert(): void
    {
        $r = PersonGroupPlanner::plan([
            $this->ma(9, 'p-b', null),
            $this->ma(3, 'p-a', null),
        ]);

        $this->assertSame([3], $r['gruppen'][0]['ids']);
        $this->assertSame([9], $r['gruppen'][1]['ids']);
    }
}
