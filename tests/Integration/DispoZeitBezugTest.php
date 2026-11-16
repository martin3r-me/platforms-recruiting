<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Services\Zeit\DispoZeitBezug;
use Platform\Recruiting\Zeit\ZeitEintrag;

class DispoZeitBezugTest extends ZeitTestCase
{
    private string $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC'); // wie die Host-App
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        parent::tearDown();
    }

    private function a(array $attrs = [])
    {
        return $this->assignment($this->event(), array_merge(['rec_employee_id' => 901, 'datum' => '2026-10-01', 'von' => '22:00', 'bis' => '04:00'], $attrs));
    }

    private function ort($t): ?string
    {
        return $t?->setTimezone('Europe/Berlin')->format('Y-m-d H:i');
    }

    public function test_nachtschicht_endet_am_folgetag(): void
    {
        $b = DispoZeitBezug::fuer($this->a());
        $this->assertSame('2026-10-01 22:00', $this->ort($b->sollVon));
        $this->assertSame('2026-10-02 04:00', $this->ort($b->sollBis));
    }

    public function test_gleiche_zeit_ist_folgetag(): void
    {
        $b = DispoZeitBezug::fuer($this->a(['von' => '20:00', 'bis' => '20:00']));
        $this->assertSame('2026-10-02 20:00', $this->ort($b->sollBis));
    }

    public function test_ohne_bis_gilt_tagesende(): void
    {
        $b = DispoZeitBezug::fuer($this->a(['von' => '14:00', 'bis' => null]));
        $this->assertSame('2026-10-02 00:00', $this->ort($b->sollBis));
    }

    public function test_ohne_von_ist_soll_von_null(): void
    {
        $b = DispoZeitBezug::fuer($this->a(['von' => null]));
        $this->assertNull($b->sollVon);
        $this->assertNotNull($b->sollBis);
    }

    public function test_ohne_person_null(): void
    {
        $this->assertNull(DispoZeitBezug::fuer($this->a(['rec_employee_id' => null])));
    }

    public function test_typ_kuerzel(): void
    {
        $a = $this->a();
        $b = DispoZeitBezug::fuer($a, self::TEAM);
        $this->assertSame('rec_employee', $b->personTyp);
        $this->assertSame('rec_dispo_assignment', $b->bezugTyp);
        $this->assertSame(901, $b->personId);
        $this->assertSame((int) $a->id, $b->bezugId);
        $this->assertSame('2026-10-01', $b->tag);
        $this->assertSame(self::TEAM, $b->teamId);
    }

    public function test_zeitzone_berlin_im_sommer_und_winter(): void
    {
        $s = DispoZeitBezug::fuer($this->a(['datum' => '2026-07-01', 'von' => '14:00', 'bis' => '22:00']));
        $this->assertSame('2026-07-01 12:00', $s->sollVon->utc()->format('Y-m-d H:i'));
        $w = DispoZeitBezug::fuer($this->a(['datum' => '2026-12-01', 'von' => '14:00', 'bis' => '22:00']));
        $this->assertSame('2026-12-01 13:00', $w->sollVon->utc()->format('Y-m-d H:i'));
    }

    public function test_umstellungstag_ende_ueber_mitternacht_in_ortszeit(): void
    {
        // Nacht auf den 25.10.2026: Sommerzeit endet um 03:00. 22:00 bis 04:00 sind 6 Uhr-Stunden plus eine Zusatzstunde = 7 echte.
        $b = DispoZeitBezug::fuer($this->a(['datum' => '2026-10-24', 'von' => '22:00', 'bis' => '04:00']));
        $this->assertSame('2026-10-25 04:00', $this->ort($b->sollBis));
        $this->assertSame(7 * 3600, $b->sollBis->getTimestamp() - $b->sollVon->getTimestamp());
    }

    public function test_aus_eintrag(): void
    {
        $b = DispoZeitBezug::fuer($this->a(), self::TEAM);
        $e = ZeitEintrag::create([
            'person_typ' => 'rec_employee', 'person_id' => 901, 'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => 5,
            'tag' => '2026-10-01', 'soll_von_at' => $b->sollVon->setTimezone(date_default_timezone_get()), 'soll_bis_at' => $b->sollBis->setTimezone(date_default_timezone_get()), 'team_id' => self::TEAM,
        ])->fresh();

        $r = DispoZeitBezug::ausEintrag($e);
        $this->assertSame(901, $r->personId);
        $this->assertSame(5, $r->bezugId);
        $this->assertSame('2026-10-01', $r->tag);
        $this->assertSame(self::TEAM, $r->teamId);
        $this->assertTrue($r->sollVon->equalTo($b->sollVon));
        $this->assertTrue($r->sollBis->equalTo($b->sollBis));
    }
}
