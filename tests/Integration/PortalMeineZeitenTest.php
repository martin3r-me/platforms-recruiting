<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zeit\DispoZeitBezug;
use Platform\Recruiting\Services\Zeit\PortalStempeln;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitEintrag;

/**
 * „Meine Zeiten“ im Portal (Spec 2026-11-16 §3): gelesen ueber den
 * Zeitdatensatz, nur die Identitaetsgruppe des Angemeldeten, Ortszeit.
 */
class PortalMeineZeitenTest extends ZeitTestCase
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

    private function ma(int $id = 901): RecEmployee
    {
        return RecEmployee::create(['id' => $id, 'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster', 'is_active' => true]);
    }

    private function einsatz(int $person = 901, string $tag = '2026-10-01', string $name = 'Gala Nacht'): RecDispoAssignment
    {
        return $this->assignment($this->event(['name' => $name]), [
            'rec_employee_id' => $person,
            'datum' => $tag,
            'von' => '14:00',
            'bis' => '22:00',
            'confirmed_at' => '2026-09-30 10:00:00',
        ]);
    }

    /** Stempelt Kommen/Gehen (Berliner Ortszeit) fuer die Einbuchung. */
    private function stempeln(RecDispoAssignment $a, string $kommen, ?string $gehen, string $art = 'selbst', int $person = 901): void
    {
        $uhr = new Stempeluhr($this->regeln());
        $bezug = DispoZeitBezug::fuer($a, self::TEAM);
        $akteur = $this->akteur('rec_employee', $person, 'Erika Muster');
        $uhr->kommen($bezug, $akteur, $art, Carbon::parse("{$a->datum->format('Y-m-d')} {$kommen}", 'Europe/Berlin'));
        if ($gehen !== null) {
            $uhr->gehen($bezug, $akteur, $art, Carbon::parse("{$a->datum->format('Y-m-d')} {$gehen}", 'Europe/Berlin'));
        }
    }

    public function test_nur_eigene_datensaetze(): void
    {
        $ma = $this->ma(901);
        $this->ma(950);
        $eigen = $this->einsatz(901);
        $fremd = $this->einsatz(950, '2026-10-01', 'Fremdes Fest');
        $this->stempeln($eigen, '13:50', '22:10');
        $this->stempeln($fremd, '13:50', '22:10', 'selbst', 950);

        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());

        $this->assertCount(1, $liste);
        $this->assertSame('Gala Nacht', $liste[0]['name']);
        $this->assertSame('01.10.2026', $liste[0]['tag']);
        $this->assertSame('13:50', $liste[0]['kommen']);
        $this->assertSame('22:10', $liste[0]['gehen']);
        $this->assertSame('ausgecheckt', $liste[0]['zustand']);
        $this->assertNull($liste[0]['vermerk']);
    }

    public function test_schwester_anstellung_der_gruppe_ist_sichtbar(): void
    {
        $ma = $this->ma(901);
        $schwester = $this->ma(902);
        Capsule::table('rec_employees')->whereIn('id', [901, 902])->update(['uuid' => 'x']);
        $aufloeser = new class extends DispoIdentityResolver {
            public function groupFor(int $employeeId): array
            {
                return [901, 902];
            }
        };
        $a = $this->einsatz(902, '2026-09-30', 'Sommerfest');
        $this->stempeln($a, '13:50', '22:10', 'selbst', 902);

        $dienst = new PortalStempeln(new Stempeluhr($this->regeln()), $this->schalter, $aufloeser, $this->regeln());
        $liste = $dienst->meineZeiten($ma, Carbon::now());

        $this->assertCount(1, $liste);
        $this->assertSame('Sommerfest', $liste[0]['name']);
        $this->assertSame(902, $schwester->id);
    }

    public function test_korrektur_vermerk_ohne_namen_und_grund(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->stempeln($a, '13:50', '22:00');
        $bezug = DispoZeitBezug::fuer($a, self::TEAM);
        $this->assertSame('ok', (new Stempeluhr($this->regeln()))->korrigieren(
            $bezug,
            $this->akteur('user', 7, 'Dagmar Dispo'),
            ['gehen_at' => Carbon::parse('2026-10-01 22:30', 'Europe/Berlin')],
            'Bahnausfall Geheimgrund',
        ));

        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());

        $this->assertSame('korrigiert', $liste[0]['vermerk']);
        $this->assertSame('22:30', $liste[0]['gehen'], 'korrigierte Zeit wird gezeigt');
        $text = json_encode($liste);
        $this->assertStringNotContainsString('Dagmar', $text);
        $this->assertStringNotContainsString('Geheimgrund', $text);
    }

    public function test_stellvertretend_vermerk(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->stempeln($a, '14:05', '22:00', 'stellvertretend');

        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());

        $this->assertSame('von der Disposition erfasst', $liste[0]['vermerk']);
    }

    public function test_korrektur_schlaegt_stellvertretend(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->stempeln($a, '14:05', '22:00', 'stellvertretend');
        (new Stempeluhr($this->regeln()))->korrigieren(
            DispoZeitBezug::fuer($a, self::TEAM),
            $this->akteur('user', 7, 'Dagmar Dispo'),
            ['gehen_at' => Carbon::parse('2026-10-01 22:30', 'Europe/Berlin')],
            'Grund',
        );

        $this->assertSame('korrigiert', $this->dienst()->meineZeiten($ma, Carbon::now())[0]['vermerk']);
    }

    public function test_verschwundene_einbuchung_bleibt_sichtbar(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->stempeln($a, '13:50', '22:00');
        Capsule::table('rec_dispo_assignments')->where('id', $a->id)->delete();

        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());

        $this->assertCount(1, $liste);
        $this->assertNull($liste[0]['name']);
        $this->assertSame('01.10.2026', $liste[0]['tag']);
    }

    public function test_aelter_als_drei_monate_fehlt(): void
    {
        $ma = $this->ma();
        $alt = $this->einsatz(901, '2026-06-30', 'Zu alt');
        $grenze = $this->einsatz(901, '2026-07-01', 'Gerade noch');
        $this->stempeln($alt, '13:50', '22:00');
        $this->stempeln($grenze, '13:50', '22:00');

        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());

        $this->assertSame(['Gerade noch'], array_column($liste, 'name'));
    }

    public function test_schalter_aus_leer_und_ohne_abfrage(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->stempeln($a, '13:50', '22:00');
        $this->schalter->an = false;

        Capsule::connection()->enableQueryLog();
        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());
        $log = Capsule::connection()->getQueryLog();
        Capsule::connection()->disableQueryLog();

        $this->assertSame([], $liste);
        foreach ($log as $q) {
            $this->assertStringNotContainsString('rec_zeit', $q['query']);
        }
    }

    public function test_gesperrtes_portal_leer(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->stempeln($a, '13:50', '22:00');
        Capsule::table('rec_employees')->where('id', 901)->update(['portal_locked_at' => '2026-09-30 09:00:00']);

        $this->assertSame([], $this->dienst()->meineZeiten($ma->fresh(), Carbon::now()));
    }

    public function test_offener_datensatz_ohne_zeiten_und_ohne_kommen_fehlen(): void
    {
        $ma = $this->ma();
        $a = $this->einsatz();
        $this->stempeln($a, '13:50', null);
        $leer = $this->einsatz(901, '2026-09-29', 'Geleert');
        $this->stempeln($leer, '13:50', '22:00');
        ZeitEintrag::query()->where('bezug_id', $leer->id)->update(['kommen_at' => null, 'gehen_at' => null]);

        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());

        $this->assertCount(1, $liste);
        $this->assertSame('eingecheckt', $liste[0]['zustand']);
        $this->assertNull($liste[0]['gehen']);
        $this->assertNull($liste[0]['arbeitszeit']);
    }

    public function test_erledigt_und_neueste_zuerst(): void
    {
        $ma = $this->ma();
        $a1 = $this->einsatz(901, '2026-09-20', 'Alt');
        $a2 = $this->einsatz(901, '2026-09-28', 'Neu');
        $this->stempeln($a1, '13:50', '22:00');
        $this->stempeln($a2, '13:50', '22:00');
        $e = ZeitEintrag::query()->where('bezug_id', $a1->id)->first();
        $e->update(['gesperrt_at' => Carbon::now()]);

        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());

        $this->assertSame(['Neu', 'Alt'], array_column($liste, 'name'));
        $this->assertSame(['ausgecheckt', 'erledigt'], array_column($liste, 'zustand'));
    }

    public function test_pause_arbeitszeit_und_utc_anzeige(): void
    {
        date_default_timezone_set('UTC');
        $ma = $this->ma();
        $a = $this->einsatz();
        // 13:00-22:00 Berlin = 11:00-20:00 UTC => 9:00 anwesend, Regel 30 min, Arbeit 8:30.
        $this->stempeln($a, '13:00', '22:00');

        $liste = $this->dienst()->meineZeiten($ma, Carbon::now());

        $this->assertSame('13:00', $liste[0]['kommen']);
        $this->assertSame('22:00', $liste[0]['gehen']);
        $this->assertSame(30, $liste[0]['pause']);
        $this->assertSame('8:30', $liste[0]['arbeitszeit']);
    }

    public function test_zeitgrenze_am_ortstag_nicht_utc(): void
    {
        date_default_timezone_set('UTC');
        $ma = $this->ma();
        $a = $this->einsatz(901, '2026-07-01', 'Grenztag');
        $this->stempeln($a, '13:50', '22:00');
        // 01.10. 00:30 Berlin = 30.09. 22:30 UTC: Ortstag 01.10. => Grenze 01.07.
        $t = Carbon::parse('2026-10-01 00:30', 'Europe/Berlin');
        Carbon::setTestNow($t);

        $this->assertCount(1, $this->dienst()->meineZeiten($ma, $t));
    }
    private function partial(array $vars): string
    {
        $compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), sys_get_temp_dir());
        $php = $compiler->compileString((string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/_meine-zeiten.blade.php'));
        extract($vars);
        $__env = new class {
            public function addLoop($data): void {}
            public function incrementLoopIndices(): void {}
            public function popLoop(): void {}
            public function getLastLoop() { return null; }
        };
        ob_start();
        try {
            eval('?>' . $php);
        } finally {
            $html = ob_get_clean();
        }

        return $html;
    }

    public function test_partial_zeigt_zeile_vermerk_und_ohne_zeilen_nichts(): void
    {
        $this->assertSame('', trim($this->partial(['meineZeiten' => [], 'duzen' => true])));

        $html = $this->partial(['duzen' => false, 'meineZeiten' => [
            ['tag' => '01.10.2026', 'name' => 'Gala Nacht', 'kommen' => '13:50', 'gehen' => '22:10', 'pause' => 30, 'arbeitszeit' => '7:50', 'zustand' => 'ausgecheckt', 'vermerk' => 'korrigiert'],
            ['tag' => '30.09.2026', 'name' => null, 'kommen' => '13:50', 'gehen' => null, 'pause' => null, 'arbeitszeit' => null, 'zustand' => 'eingecheckt', 'vermerk' => null],
        ]]);

        $this->assertStringContainsString('Meine Zeiten', $html);
        $this->assertStringContainsString('01.10.2026 · Gala Nacht', $html);
        $this->assertStringContainsString('13:50 – 22:10 · ausgecheckt', $html);
        $this->assertStringContainsString('Pause 30 Min, Arbeitszeit 7:50 Std', $html);
        $this->assertStringContainsString('korrigiert', $html);
        $this->assertStringContainsString('30.09.2026', $html);
        $this->assertStringNotContainsString('30.09.2026 ·', $html);
        $this->assertStringContainsString('eingecheckt', $html);
    }

    public function test_fremder_person_typ_mit_gleicher_id_fehlt(): void
    {
        $ma = $this->ma(901);
        $a = $this->einsatz();
        $this->stempeln($a, '13:50', '22:00');
        ZeitEintrag::query()->update(['person_typ' => 'x']);

        $this->assertSame([], $this->dienst()->meineZeiten($ma, Carbon::now()));
    }

    public function test_anderer_bezug_typ_wird_ausgeblendet(): void
    {
        $ma = $this->ma(901);
        $a = $this->einsatz();
        $this->stempeln($a, '13:50', '22:00');
        ZeitEintrag::query()->update(['bezug_typ' => 'x']);

        $this->assertSame([], $this->dienst()->meineZeiten($ma, Carbon::now()));
    }
}
