<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobRecipientPlanner;

final class DispoJobRecipientPlannerTest extends TestCase
{
    private static function kandidat(int $id, array $extra = []): array
    {
        return $extra + ['anchor' => $id, 'employee_id' => $id, 'phone' => '+4917' . $id, 'first_name' => 'P' . $id,
            'portal_token' => 'tok' . $id, 'block' => 200, 'portal_locked' => false, 'taetigkeiten' => ['Zapfer']];
    }

    private static function schichten(): array
    {
        return [
            ['id' => 11, 'datum' => '2026-10-24', 'taetigkeit' => 'Zapfer'],
            ['id' => 12, 'datum' => '2026-10-25', 'taetigkeit' => 'Servicekraft'],
        ];
    }

    private static function auswahl(array $extra = []): array
    {
        return $extra + ['gruppen' => ['alle'], 'bloecke' => [200], 'nur_passend' => false];
    }

    public function testHappyPathBekommtAlleFreienSchichten(): void
    {
        $r = DispoJobRecipientPlanner::plan([self::kandidat(1)], self::schichten(), [], [], [], self::auswahl());
        $this->assertSame([11, 12], $r['recipients'][0]['shift_ids']);
        $this->assertSame('+49171',$r['recipients'][0]['phone']);
        $this->assertSame(0, array_sum($r['skipped']));
    }

    public function testBlockUndGruppeBildenDieGrundmenge(): void
    {
        $k = [self::kandidat(1, ['block' => 100]), self::kandidat(2, ['block' => null]), self::kandidat(3)];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [3 => 7], [], [], self::auswahl(['bloecke' => [200, null], 'gruppen' => ['5-9']]));
        $this->assertSame([3], array_column($r['recipients'], 'anchor'));
        $this->assertSame(0, array_sum($r['skipped']), 'Grundmenge zaehlt nicht als Topf');
    }

    public function testLeereGruppenOderBloeckeHeissenNiemand(): void
    {
        $this->assertSame([], DispoJobRecipientPlanner::plan([self::kandidat(1)], self::schichten(), [], [], [], self::auswahl(['gruppen' => []]))['recipients']);
        $this->assertSame([], DispoJobRecipientPlanner::plan([self::kandidat(1)], self::schichten(), [], [], [], self::auswahl(['bloecke' => []]))['recipients']);
    }

    public function testToepfeInReihenfolge(): void
    {
        $k = [
            self::kandidat(1),                                        // schon angeschrieben
            self::kandidat(2, ['portal_locked' => true, 'phone' => null]),
            self::kandidat(3, ['phone' => null]),
            self::kandidat(4, ['taetigkeiten' => ['Koch']]),          // ohne passende Taetigkeit
            self::kandidat(5),                                        // an beiden Tagen gebucht
        ];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [5 => ['2026-10-24' => true, '2026-10-25' => true]], [1 => true], self::auswahl(['nur_passend' => true]));
        $this->assertSame([], $r['recipients']);
        $this->assertSame(['schon_angeschrieben' => 1, 'portal_gesperrt' => 1, 'ohne_nummer' => 1, 'ohne_taetigkeit' => 1, 'alle_tage_gebucht' => 1, 'schon_zugesagt' => 0], $r['skipped']);
        $this->assertSame([2], $r['skipped_ids']['portal_gesperrt'], 'gesperrt vor ohne Nummer');
    }

    public function testNurFreieTageUndPassendeSchichtenBleiben(): void
    {
        $k = [self::kandidat(1, ['taetigkeiten' => ['zapfer ']])];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [1 => ['2026-10-25' => true]], [], self::auswahl(['nur_passend' => true]));
        $this->assertSame([11], $r['recipients'][0]['shift_ids'], 'Taetigkeit case-insensitiv, 25. ist gebucht');
    }

    public function testOhnePassendFilterBleibenAlleSchichten(): void
    {
        $k = [self::kandidat(1, ['taetigkeiten' => []])];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl(['nur_passend' => false]));
        $this->assertSame([11, 12], $r['recipients'][0]['shift_ids']);
    }

    /** Spec §9: dieselbe Schicht (VA, Tag, Beginn, Taetigkeit) schon in einer anderen Anfrage zugesagt. */
    public function testSchonAnderswoZugesagteSchichtFaelltWeg(): void
    {
        $schichten = [
            ['id' => 11, 'event' => 1, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer'],
            ['id' => 12, 'event' => 1, 'datum' => '2026-10-25', 'von' => '17:00', 'taetigkeit' => 'Zapfer'],
        ];
        $schon = [
            1 => [DispoJobRecipientPlanner::schichtSchluessel(1, '2026-10-24', '17:00', 'zapfer ')],
            2 => [DispoJobRecipientPlanner::schichtSchluessel(1, '2026-10-24', '17:00', 'Zapfer'), DispoJobRecipientPlanner::schichtSchluessel(1, '2026-10-25', '17:00', 'Zapfer')],
            3 => [DispoJobRecipientPlanner::schichtSchluessel(1, '2026-10-24', '18:00', 'Zapfer')], // anderer Beginn: zaehlt nicht
        ];
        $r = DispoJobRecipientPlanner::plan([self::kandidat(1), self::kandidat(2), self::kandidat(3)], $schichten, [], [], [], self::auswahl(), $schon);

        $this->assertSame([1, 3], array_column($r['recipients'], 'anchor'));
        $this->assertSame([12], $r['recipients'][0]['shift_ids']);
        $this->assertSame([11, 12], $r['recipients'][1]['shift_ids']);
        $this->assertSame(1, $r['skipped']['schon_zugesagt']);
        $this->assertSame([2], $r['skipped_ids']['schon_zugesagt']);
        $this->assertSame('hat diese Schicht schon zugesagt', DispoJobRecipientPlanner::TOEPFE['schon_zugesagt']);
    }

    public function testOhneZusagenKarteBleibtAllesWieGehabt(): void
    {
        $r = DispoJobRecipientPlanner::plan([self::kandidat(1)], self::schichten(), [], [], [], self::auswahl());
        $this->assertSame([11, 12], $r['recipients'][0]['shift_ids']);
        $this->assertSame(0, $r['skipped']['schon_zugesagt']);
    }
}
