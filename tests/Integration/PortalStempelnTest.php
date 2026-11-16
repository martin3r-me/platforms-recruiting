<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zeit\PortalStempeln;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitEintrag;

/**
 * Portal-Dienst fuers Stempeln (Spec 2026-11-16 §3): Zeilen der Kachel und die
 * zwei Aktionen. Zeiten der Tests sind Berliner Ortszeit (Schicht 14:00-22:00).
 */
class PortalStempelnTest extends ZeitTestCase
{
    private object $schalter;
    private string $standardZone;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!Capsule::schema()->hasTable('rec_employees')) {
            Capsule::schema()->create('rec_employees', function ($t) {
                $t->increments('id');
                $t->unsignedBigInteger('team_id')->nullable();
                $t->string('uuid', 64)->nullable();
                $t->string('portal_token', 64)->nullable();
                $t->string('first_name')->nullable();
                $t->string('last_name')->nullable();
                $t->boolean('is_active')->default(true);
                $t->boolean('is_test')->default(false);
                $t->timestamp('portal_locked_at')->nullable();
                $t->softDeletes();
                $t->timestamps();
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::table('rec_employees')->delete();
        $this->standardZone = date_default_timezone_get();
        $this->schalter = new class extends ZeitSchalter {
            public bool $an = true;

            public function aktiv(): bool
            {
                return $this->an;
            }

            public function teamId(): int
            {
                return 1101;
            }
        };
        $this->jetzt('13:30');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->standardZone);
        parent::tearDown();
    }

    private function jetzt(string $uhr, string $tag = '2026-10-01'): Carbon
    {
        $t = Carbon::parse("{$tag} {$uhr}", 'Europe/Berlin');
        Carbon::setTestNow($t);

        return $t;
    }

    private function dienst(): PortalStempeln
    {
        return new PortalStempeln(new Stempeluhr($this->regeln()), $this->schalter, new DispoIdentityResolver(), $this->regeln());
    }

    private function ma(int $id = 901, array $attr = []): RecEmployee
    {
        return RecEmployee::create(array_merge(['id' => $id, 'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster', 'is_active' => true], $attr));
    }

    private function einsatz(int $person = 901, array $attr = []): RecDispoAssignment
    {
        return $this->assignment($this->event(['name' => 'Gala Nacht']), array_merge([
            'rec_employee_id' => $person,
            'datum' => '2026-10-01',
            'von' => '14:00',
            'bis' => '22:00',
            'confirmed_at' => '2026-09-30 10:00:00',
            'reminder_sent_at' => '2026-09-29 10:00:00',
        ], $attr));
    }

    public function test_zeile_erscheint_im_fenster_und_kommen_geht(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();

        $zeilen = $this->dienst()->zeilen($ma, Carbon::now());
        $this->assertCount(1, $zeilen);
        $this->assertSame($a->id, $zeilen[0]['assignment_id']);
        $this->assertSame('Gala Nacht', $zeilen[0]['name']);
        $this->assertSame('01.10.2026', $zeilen[0]['tag']);
        $this->assertSame('14:00–22:00', $zeilen[0]['soll']);
        $this->assertSame('offen', $zeilen[0]['zustand']);
        $this->assertTrue($zeilen[0]['kann_kommen']);
        $this->assertFalse($zeilen[0]['kann_gehen']);

        $this->assertSame('ok', $this->dienst()->kommen($ma, $a->id, Carbon::now()));

        $zeilen = $this->dienst()->zeilen($ma, Carbon::now());
        $this->assertSame('eingecheckt', $zeilen[0]['zustand']);
        $this->assertSame('13:30', $zeilen[0]['kommen']);
        $this->assertFalse($zeilen[0]['kann_kommen']);
        $this->assertTrue($zeilen[0]['kann_gehen']);
    }

    public function test_vor_dem_fenster_keine_zeile(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->jetzt('12:59');

        $this->assertSame([], $this->dienst()->zeilen($ma, Carbon::now()));
        $this->assertSame('nicht_erlaubt', $this->dienst()->kommen($ma, $a->id, Carbon::now()));
        $this->assertSame(0, ZeitEintrag::query()->count());
    }

    public function test_unbestaetigt_keine_zeile(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz(901, ['confirmed_at' => null]);

        $this->assertSame([], $this->dienst()->zeilen($ma, Carbon::now()));
        $this->assertSame('nicht_erlaubt', $this->dienst()->kommen($ma, $a->id, Carbon::now()));
    }

    public function test_fremde_einbuchung_wird_verweigert(): void
    {
        $ma = $this->ma(901);
        $this->ma(950);
        $fremd = $this->einsatz(950);

        $this->assertSame([], $this->dienst()->zeilen($ma, Carbon::now()));
        $this->assertSame('nicht_erlaubt', $this->dienst()->kommen($ma, $fremd->id, Carbon::now()));
        $this->assertSame('nicht_erlaubt', $this->dienst()->gehen($ma, $fremd->id, Carbon::now()));
        $this->assertSame(0, ZeitEintrag::query()->count(), 'kein Datensatz fuer fremde Einbuchung');
    }

    public function test_fremder_offener_stempel_laesst_sich_nicht_beenden(): void
    {
        $ma = $this->ma(901);
        $fremd = $this->einsatz(950);
        $bezug = \Platform\Recruiting\Services\Zeit\DispoZeitBezug::fuer($fremd, self::TEAM);
        (new Stempeluhr($this->regeln()))->kommen($bezug, $this->akteur('rec_employee', 950, 'Fremd'), 'selbst', Carbon::now());

        $this->assertSame([], $this->dienst()->zeilen($ma, Carbon::now()));
        $this->assertSame('nicht_erlaubt', $this->dienst()->gehen($ma, $fremd->id, Carbon::now()));
        $this->assertNull(ZeitEintrag::query()->first()->gehen_at);
    }

    public function test_schalter_aus_verweigert_und_zeigt_nichts(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->schalter->an = false;

        $this->assertSame([], $this->dienst()->zeilen($ma, Carbon::now()));
        $this->assertSame('aus', $this->dienst()->kommen($ma, $a->id, Carbon::now()));
        $this->assertSame('aus', $this->dienst()->gehen($ma, $a->id, Carbon::now()));
        $this->assertSame(0, ZeitEintrag::query()->count());
    }

    public function test_gesperrtes_portal_zeigt_nichts(): void
    {
        $ma = $this->ma(901, ['portal_locked_at' => '2026-09-30 09:00:00']);
        $a = $this->einsatz();

        $this->assertSame([], $this->dienst()->zeilen($ma, Carbon::now()));
        $this->assertSame('nicht_erlaubt', $this->dienst()->kommen($ma, $a->id, Carbon::now()));
        $this->assertSame(0, ZeitEintrag::query()->count());
    }

    public function test_nachtschicht_durchgaengig(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz(901, ['von' => '22:00', 'bis' => '04:00']);
        $this->jetzt('21:30');
        $this->assertSame('ok', $this->dienst()->kommen($ma, $a->id, Carbon::now()));

        $this->jetzt('04:10', '2026-10-02');
        $zeilen = $this->dienst()->zeilen($ma, Carbon::now());
        $this->assertCount(1, $zeilen);
        $this->assertTrue($zeilen[0]['kann_gehen']);
        $this->assertSame('ok', $this->dienst()->gehen($ma, $a->id, Carbon::now()));
        $this->assertSame('04:10', $this->dienst()->zeilen($ma, Carbon::now())[0]['gehen']);
    }

    public function test_nachtschicht_nach_dem_fenster_ist_zu_spaet(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz(901, ['von' => '22:00', 'bis' => '04:00']);
        $this->jetzt('21:30');
        $this->dienst()->kommen($ma, $a->id, Carbon::now());

        $this->jetzt('05:01', '2026-10-02');
        $zeilen = $this->dienst()->zeilen($ma, Carbon::now());
        $this->assertCount(1, $zeilen, 'offener Stempel bleibt sichtbar');
        $this->assertFalse($zeilen[0]['kann_gehen']);
        $this->assertSame('zu_spaet', $this->dienst()->gehen($ma, $a->id, Carbon::now()));
    }

    /** @dataProvider einbuchungsAenderungen */
    public function test_offener_stempel_schlaegt_alles(array $aenderung): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->assertSame('ok', $this->dienst()->kommen($ma, $a->id, Carbon::now()));

        Capsule::table('rec_dispo_assignments')->where('id', $a->id)->update($aenderung);

        $zeilen = $this->dienst()->zeilen($ma, Carbon::now());
        $this->assertCount(1, $zeilen);
        $this->assertSame($a->id, $zeilen[0]['assignment_id']);
        $this->assertTrue($zeilen[0]['kann_gehen']);
        $this->assertSame('ok', $this->dienst()->gehen($ma, $a->id, Carbon::now()));
        $this->assertNotNull(ZeitEintrag::query()->first()->gehen_at);
    }

    public static function einbuchungsAenderungen(): array
    {
        return [
            'Zeit verschoben (unbestaetigt)' => [['confirmed_at' => null]],
            'verschwunden' => [['missing_since' => '2026-10-01 12:00:00']],
            'abgesagt' => [['declined_at' => '2026-10-01 12:00:00']],
        ];
    }

    public function test_einbuchung_geloescht_gehen_bleibt_moeglich(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->dienst()->kommen($ma, $a->id, Carbon::now());
        Capsule::table('rec_dispo_assignments')->where('id', $a->id)->delete();

        $zeilen = $this->dienst()->zeilen($ma, Carbon::now());
        $this->assertCount(1, $zeilen);
        $this->assertSame('Einsatz', $zeilen[0]['name']);
        $this->assertSame('14:00–22:00', $zeilen[0]['soll']);
        $this->assertSame('ok', $this->dienst()->gehen($ma, $a->id, Carbon::now()));
    }

    public function test_offener_stempel_einer_alten_anstellung_ohne_besitzwechsel_bleibt_beim_eintrag(): void
    {
        // Einbuchung gehoert inzwischen einer anderen Person: Bezug aus dem Eintrag, kein Zugriff fuer Fremde.
        $ma = $this->ma(901);
        $a = $this->einsatz(901);
        $this->dienst()->kommen($ma, $a->id, Carbon::now());
        Capsule::table('rec_dispo_assignments')->where('id', $a->id)->update(['rec_employee_id' => 950]);

        $zeilen = $this->dienst()->zeilen($ma, Carbon::now());
        $this->assertCount(1, $zeilen);
        $this->assertSame('ok', $this->dienst()->gehen($ma, $a->id, Carbon::now()));
        $this->assertSame(1, ZeitEintrag::query()->count());
    }

    public function test_nach_gehen_zusammenfassung(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->jetzt('13:52');
        $this->dienst()->kommen($ma, $a->id, Carbon::now());
        $this->jetzt('22:07');
        $this->assertSame('ok', $this->dienst()->gehen($ma, $a->id, Carbon::now()));

        $z = $this->dienst()->zeilen($ma, Carbon::now())[0];
        $this->assertSame('ausgecheckt', $z['zustand']);
        $this->assertSame('13:52', $z['kommen']);
        $this->assertSame('22:07', $z['gehen']);
        $this->assertSame(30, $z['pause']);
        $this->assertSame('7:45', $z['arbeitszeit']);
        $this->assertFalse($z['kann_kommen']);
        $this->assertFalse($z['kann_gehen']);
    }

    public function test_anzeige_in_ortszeit_bei_standardzone_utc(): void
    {
        date_default_timezone_set('UTC');
        $ma = $this->ma();
        $a = $this->einsatz();
        // 11:52 UTC = 13:52 Berlin (MESZ).
        $t = Carbon::parse('2026-10-01 11:52:00', 'UTC');
        Carbon::setTestNow($t);
        $this->assertSame('ok', $this->dienst()->kommen($ma, $a->id, Carbon::now()));

        $z = $this->dienst()->zeilen($ma, Carbon::now())[0];
        $this->assertSame('13:52', $z['kommen']);
        $this->assertSame('14:00–22:00', $z['soll']);
    }

    public function test_meldungen(): void
    {
        $fruehestens = Carbon::parse('2026-10-01 13:00', 'Europe/Berlin');
        $this->assertNull(PortalStempeln::meldung('ok', null, true));
        $this->assertNull(PortalStempeln::meldung('schon', null, true));
        $this->assertSame('zu früh – ab 13:00 möglich', PortalStempeln::meldung('zu_frueh', $fruehestens, true));
        $this->assertSame('Zeitfenster vorbei – bitte bei der Disposition melden', PortalStempeln::meldung('zu_spaet', null, true));
        $this->assertSame('Zeitfenster vorbei – melden Sie sich bitte bei der Disposition', PortalStempeln::meldung('zu_spaet', null, false));
        $this->assertSame('gesperrt', PortalStempeln::meldung('gesperrt', null, true));
        foreach (['aus', 'nicht_erlaubt', 'kein_kommen'] as $s) {
            $this->assertSame('Das geht gerade nicht.', PortalStempeln::meldung($s, null, true));
        }
    }

    public function test_vergessenes_gehen_zeigt_zeile_mit_fensterhinweis_bis_sieben_tage(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->dienst()->kommen($ma, $a->id, Carbon::now());

        $this->jetzt('10:00', '2026-10-02');
        $z = $this->dienst()->zeilen($ma, Carbon::now());
        $this->assertCount(1, $z);
        $this->assertFalse($z[0]['kann_gehen']);
        $this->assertTrue($z[0]['fenster_vorbei']);
        $this->assertFalse($z[0]['heute']);

        $this->jetzt('23:30', '2026-10-08');
        $this->assertCount(1, $this->dienst()->zeilen($ma, Carbon::now()), 'bis einschliesslich 7 Tage nach dem Einsatztag');

        $this->jetzt('00:30', '2026-10-09');
        $this->assertSame([], $this->dienst()->zeilen($ma, Carbon::now()), 'danach nicht mehr');
        $this->assertSame('nicht_erlaubt', $this->dienst()->gehen($ma, $a->id, Carbon::now()));
    }

    public function test_heute_flag_und_kein_fensterhinweis_im_laufenden_fenster(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->dienst()->kommen($ma, $a->id, Carbon::now());

        $z = $this->dienst()->zeilen($ma, Carbon::now())[0];
        $this->assertTrue($z['heute']);
        $this->assertFalse($z['fenster_vorbei']);
    }

    public function test_gruppensperre_der_schwester_anstellung_sperrt_alles(): void
    {
        $ma = $this->ma(901);
        $this->ma(902, ['portal_locked_at' => '2026-09-30 09:00:00']);
        $a = $this->einsatz(901);
        $gruppe = new class extends DispoIdentityResolver {
            public function groupFor(int $employeeId): array
            {
                return [901, 902];
            }
        };
        $dienst = new PortalStempeln(new Stempeluhr($this->regeln()), $this->schalter, $gruppe, $this->regeln());

        $this->assertSame([], $dienst->zeilen($ma, Carbon::now()));
        $this->assertSame('nicht_erlaubt', $dienst->kommen($ma, $a->id, Carbon::now()));
        $this->assertSame('nicht_erlaubt', $dienst->gehen($ma, $a->id, Carbon::now()));
        $this->assertSame(0, ZeitEintrag::query()->count());
    }
}
