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
        $this->assertSame(['schon_angeschrieben' => 1, 'portal_gesperrt' => 1, 'ohne_nummer' => 1, 'ohne_taetigkeit' => 1, 'alle_tage_gebucht' => 1, 'schon_zugesagt' => 0, 'spaeter' => 0], $r['skipped']);
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

    public function testStatusFilterIstGrundmengeUndKeinTopf(): void
    {
        $k = [self::kandidat(1, ['status' => 'GO']), self::kandidat(2, ['status' => 'MA']), self::kandidat(3)];
        $anker = fn (array $auswahl) => array_column(DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl($auswahl))['recipients'], 'anchor');

        $this->assertSame([1, 3], $anker(['stati' => ['GO']]), 'ohne status-Feld zaehlt als GO');
        $this->assertSame([2], $anker(['stati' => ['MA']]));
        $this->assertSame([1, 2, 3], $anker(['stati' => ['GO', 'MA']]));
        $this->assertSame([], $anker(['stati' => []]), 'leer heisst niemand, nicht alle');
        $this->assertSame([1, 2, 3], $anker([]), 'fehlender Schluessel = alte Welle = beide');
        $this->assertSame([1, 2, 3], $anker(['stati' => null]), 'null = alte Welle = beide');

        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl(['stati' => ['MA']]));
        $this->assertSame(0, array_sum($r['skipped']), 'Status zaehlt nicht als Topf');
    }

    public function testStatiNimmtNurBekannteWerteInFesterReihenfolge(): void
    {
        $this->assertSame(['GO', 'MA'], DispoJobRecipientPlanner::stati(['MA', 'XY', 'GO', 'GO']));
        $this->assertSame([], DispoJobRecipientPlanner::stati([]));
        $this->assertSame(['GO'], DispoJobRecipientPlanner::stati([['x'], 'GO']), 'verschachtelte Werte vom Client fallen still heraus');
    }

    public function testStatiLabel(): void
    {
        $this->assertSame('GO + MA', DispoJobRecipientPlanner::statiLabel(null));
        $this->assertSame('GO + MA', DispoJobRecipientPlanner::statiLabel(['MA', 'GO']));
        $this->assertSame('nur GO', DispoJobRecipientPlanner::statiLabel(['GO']));
        $this->assertSame('nur MA', DispoJobRecipientPlanner::statiLabel(['MA']));
        $this->assertSame('kein Status', DispoJobRecipientPlanner::statiLabel([]));
    }

    public function testGrundmengeOhneStatusFilter(): void
    {
        $k = [self::kandidat(1, ['status' => 'GO']), self::kandidat(2, ['status' => 'MA', 'phone' => null]), self::kandidat(3, ['block' => 100])];
        $this->assertSame([1, 2], array_column(DispoJobRecipientPlanner::grundmenge($k, [], self::auswahl(['stati' => ['GO']])), 'anchor'),
            'Filiale + Gruppe, aber weder Status noch Toepfe');
    }

    public function testReihenfolgeWenigsteEinsaetzeZuerstDannAnker(): void
    {
        $k = [self::kandidat(1), self::kandidat(2), self::kandidat(3), self::kandidat(4)];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [1 => 3, 2 => 0, 3 => 1, 4 => 0], [], [], self::auswahl());
        $this->assertSame([2, 4, 3, 1], array_column($r['recipients'], 'anchor'));
        $this->assertSame(0, $r['rest']);
    }

    public function testObergrenzeKapptNachDenToepfenUndZaehltDenRest(): void
    {
        $k = [self::kandidat(1), self::kandidat(2, ['phone' => null]), self::kandidat(3), self::kandidat(4)];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl(['max' => 2]));
        $this->assertSame([1, 3], array_column($r['recipients'], 'anchor'));
        $this->assertSame(1, $r['skipped']['ohne_nummer'], 'Topf vor der Obergrenze');
        $this->assertSame(1, $r['skipped']['spaeter']);
        $this->assertSame([4], $r['skipped_ids']['spaeter']);
        $this->assertSame(1, $r['rest']);

        $alle = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl(['max' => null]));
        $this->assertCount(3, $alle['recipients'], 'null = alle');
        $this->assertCount(3, DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl(['max' => 0]))['recipients'], '0 = alle');
    }

    public function testGesellschaftIstGrundmenge(): void
    {
        $k = [self::kandidat(1, ['firmen' => ['RG', 'MA']]), self::kandidat(2, ['firmen' => ['MA']]), self::kandidat(3)];
        $anker = fn (array $a) => array_column(DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl($a))['recipients'], 'anchor');
        $this->assertSame([1, 2], $anker(['firmen' => ['MA']]), 'Person mit beiden Anstellungen ist bei MA dabei');
        $this->assertSame([1, 3], $anker(['firmen' => ['RG']]), 'ohne firmen-Feld = RG');
        $this->assertSame([1, 2, 3], $anker(['firmen' => ['RG', 'MA']]));
        $this->assertSame([], $anker(['firmen' => []]), 'leer = niemand');
        $this->assertSame([1, 2, 3], $anker([]), 'fehlend = alte Welle = beide');
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl(['firmen' => ['MA']]));
        $this->assertSame(0, array_sum($r['skipped']), 'kein Topf');
    }

    public function testEmpfaengerTraegtFruehestenTagSeinerSchichten(): void
    {
        $k = [self::kandidat(1)];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [1 => ['2026-10-24' => true]], [], self::auswahl());
        $this->assertSame('2026-10-25', $r['recipients'][0]['datum'], 'der 24. ist gebucht');
    }
}
