<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\ZeitEintrag;

/** Die Stempel-Aktionen der PortalShell: ohne gueltige Sitzung passiert nichts (Spec §7.2). */
class PortalShellStempelnTest extends ZeitTestCase
{
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
                $t->integer('rec_person_id')->nullable();
                $t->timestamp('portal_locked_at')->nullable();
                $t->timestamp('portal_v2_since')->nullable();
                $t->softDeletes();
                $t->timestamps();
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::table('rec_employees')->delete();
        $container = Container::getInstance();
        $container->instance('session', new Store('test', new ArraySessionHandler(60)));
        Facade::clearResolvedInstance('session');
        $container->instance(ZeitSchalter::class, new class extends ZeitSchalter {
            public function aktiv(): bool
            {
                return true;
            }

            public function teamId(): int
            {
                return 1101;
            }
        });
        Carbon::setTestNow(Carbon::parse('2026-10-01 13:30', 'Europe/Berlin'));
    }

    protected function tearDown(): void
    {
        TestPortalSitzung::aufraeumen();
        $container = Container::getInstance();
        $container->forgetInstance(ZeitSchalter::class);
        unset($container[ZeitSchalter::class]);
        $container->forgetInstance('session');
        unset($container['session']);
        Facade::clearResolvedInstance('session');
        parent::tearDown();
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $s = new PortalShell();
        $s->employeeId = $ma->id;
        $s->state = 'verified';

        return $s;
    }

    private function ma(): RecEmployee
    {
        return RecEmployee::create(['id' => 901, 'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster', 'is_active' => true, 'portal_v2_since' => '2026-09-24 08:00:00']);
    }

    private function einsatz(): int
    {
        return $this->assignment($this->event(), [
            'rec_employee_id' => 901, 'datum' => '2026-10-01', 'von' => '14:00', 'bis' => '22:00',
            'confirmed_at' => '2026-09-30 10:00:00', 'reminder_sent_at' => '2026-09-29 10:00:00',
        ])->id;
    }

    public function test_ohne_sitzung_passiert_nichts(): void
    {
        $ma = $this->ma();
        $id = $this->einsatz();
        $shell = $this->shell($ma);

        $shell->stempelKommen($id);

        $this->assertSame(0, ZeitEintrag::query()->count());
        $this->assertSame('unverified', $shell->state);

        $shell->state = 'verified';
        $shell->stempelGehen($id);
        $this->assertSame(0, ZeitEintrag::query()->count());
        $this->assertSame('unverified', $shell->state);
    }

    public function test_mit_sitzung_wird_gestempelt(): void
    {
        $ma = $this->ma();
        $id = $this->einsatz();
        TestPortalSitzung::oeffne(901);
        $shell = $this->shell($ma);

        $shell->stempelKommen($id);
        $e = ZeitEintrag::query()->first();
        $this->assertNotNull($e);
        $this->assertNotNull($e->kommen_at);
        $this->assertSame('verified', $shell->state);
        $this->assertNull($shell->stempelMeldung);

        $shell->stempelGehen($id);
        $this->assertNotNull(ZeitEintrag::query()->first()->gehen_at);

        // Zweiter Klick: schon, nichts Neues.
        $shell->stempelGehen($id);
        $this->assertSame(1, ZeitEintrag::query()->count());
    }

    public function test_gesperrt_zwischen_aufrufen_verweigert(): void
    {
        $ma = $this->ma();
        $id = $this->einsatz();
        TestPortalSitzung::oeffne(901);
        Capsule::table('rec_employees')->where('id', 901)->update(['portal_locked_at' => '2026-10-01 10:00:00']);
        $shell = $this->shell($ma);

        $shell->stempelKommen($id);

        $this->assertSame(0, ZeitEintrag::query()->count());
        $this->assertSame('gesperrt', $shell->state);
    }

    public function test_fremde_einbuchung_ueber_die_shell_verweigert(): void
    {
        $ma = $this->ma();
        $fremd = $this->assignment($this->event(), [
            'rec_employee_id' => 950, 'datum' => '2026-10-01', 'von' => '14:00', 'bis' => '22:00',
            'confirmed_at' => '2026-09-30 10:00:00', 'reminder_sent_at' => '2026-09-29 10:00:00',
        ])->id;
        TestPortalSitzung::oeffne(901);
        $shell = $this->shell($ma);

        $shell->stempelKommen($fremd);

        $this->assertSame(0, ZeitEintrag::query()->count());
        $this->assertSame('Das geht gerade nicht.', $shell->stempelMeldung);
    }

    public function test_einsatz_seite_bleibt_ohne_stempeln(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            $root . '/src/Livewire/Public/EmployeeAssignments.php',
            $root . '/resources/views/livewire/public/employee-assignments.blade.php',
        ] as $datei) {
            $inhalt = strtolower((string) file_get_contents($datei));
            // „stempel“ allein steht schon in Altcode („Zeitstempel“, „dann stempeln“): die Zeit-Bausteine beim Namen.
            foreach (['stempeluhr', 'portalstempeln', 'stempelkommen', 'stempelgehen', 'stempel-kachel', 'services\\zeit', 'zeiteintrag'] as $wort) {
                $this->assertStringNotContainsString($wort, $inhalt, "{$datei}: {$wort}");
            }
        }
    }

    /** Das Partial ohne View-Fabrik uebersetzen und rendern (reine Variablen-Vorlage). */
    private function kachel(array $vars): string
    {
        $compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), sys_get_temp_dir());
        $php = $compiler->compileString((string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/_stempel-kachel.blade.php'));
        extract($vars);
        // @foreach ruft die Schleifen-Buchfuehrung der View-Fabrik; hier ein stummer Ersatz.
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

    /** Offener Stempel vorbereiten: mit gueltiger Sitzung kommen. */
    private function offenerStempel(): array
    {
        $ma = $this->ma();
        $id = $this->einsatz();
        TestPortalSitzung::oeffne(901);
        $shell = $this->shell($ma);
        $shell->stempelKommen($id);
        $this->assertNotNull(ZeitEintrag::query()->first()?->kommen_at, 'Vorflug: gekommen');

        return [$shell, $id];
    }

    public function test_gehen_ohne_sitzung_bleibt_offen(): void
    {
        [$shell, $id] = $this->offenerStempel();
        TestPortalSitzung::aufraeumen();
        Container::getInstance()->instance('session', new Store('leer', new ArraySessionHandler(60)));
        Facade::clearResolvedInstance('session');

        $shell->stempelGehen($id);

        $this->assertNull(ZeitEintrag::query()->first()->gehen_at);
        $this->assertSame('unverified', $shell->state);
    }

    public function test_gehen_bei_gesperrtem_portal_bleibt_offen(): void
    {
        [$shell, $id] = $this->offenerStempel();
        Capsule::table('rec_employees')->where('id', 901)->update(['portal_locked_at' => '2026-10-01 12:00:00']);

        $shell->stempelGehen($id);

        $this->assertNull(ZeitEintrag::query()->first()->gehen_at);
        $this->assertSame('gesperrt', $shell->state);
    }

    public function test_gehen_bei_inaktivem_mitarbeiter_bleibt_offen(): void
    {
        [$shell, $id] = $this->offenerStempel();
        Capsule::table('rec_employees')->where('id', 901)->update(['is_active' => 0]);

        $shell->stempelGehen($id);

        $this->assertNull(ZeitEintrag::query()->first()->gehen_at);
        $this->assertSame('weg', $shell->state);
    }

    public function test_gehen_mit_gueltiger_sitzung_gegenprobe(): void
    {
        [$shell, $id] = $this->offenerStempel();

        $shell->stempelGehen($id);

        $this->assertNotNull(ZeitEintrag::query()->first()->gehen_at);
    }

    public function test_meldung_steht_in_der_kachel_auch_ohne_zeilen(): void
    {
        $ma = $this->ma();
        $id = $this->einsatz();
        TestPortalSitzung::oeffne(901);
        $shell = $this->shell($ma);
        // Fenster ist inzwischen zu: kein Eintrag, keine Zeile.
        Carbon::setTestNow(Carbon::parse('2026-10-01 23:30', 'Europe/Berlin'));

        $shell->stempelKommen($id);

        $this->assertSame('Das geht gerade nicht.', $shell->stempelMeldung);
        $html = $this->kachel(['stempelZeilen' => [], 'stempelMeldung' => $shell->stempelMeldung, 'duzen' => true]);
        $this->assertStringContainsString('Das geht gerade nicht.', $html);
        $leer = $this->kachel(['stempelZeilen' => [], 'stempelMeldung' => null, 'duzen' => true]);
        $this->assertSame('', trim($leer));
    }

    private function zeile(array $attr = []): array
    {
        return array_merge([
            'assignment_id' => 5, 'name' => 'Gala Nacht', 'tag' => '01.10.2026', 'soll' => '14:00–22:00',
            'zustand' => 'eingecheckt', 'kommen' => '13:52', 'gehen' => null, 'pause' => null, 'arbeitszeit' => null,
            'kann_kommen' => false, 'kann_gehen' => false, 'heute' => true, 'fenster_vorbei' => false,
        ], $attr);
    }

    public function test_kachel_fensterhinweis_und_ueberschrift(): void
    {
        $vergessen = $this->zeile(['heute' => false, 'fenster_vorbei' => true]);
        $du = $this->kachel(['stempelZeilen' => [$vergessen], 'stempelMeldung' => null, 'duzen' => true]);
        $this->assertStringContainsString('Zeitfenster vorbei – bitte melde dich bei der Disposition.', $du);
        $this->assertStringContainsString('Dein Einsatz</', $du);
        $this->assertStringNotContainsString('Gehen</button>', $du);
        $sie = $this->kachel(['stempelZeilen' => [$vergessen], 'stempelMeldung' => null, 'duzen' => false]);
        $this->assertStringContainsString('Zeitfenster vorbei – bitte melden Sie sich bei der Disposition.', $sie);
        $this->assertStringContainsString('Ihr Einsatz</', $sie);

        $heute = $this->kachel(['stempelZeilen' => [$this->zeile(['kann_gehen' => true])], 'stempelMeldung' => null, 'duzen' => true]);
        $this->assertStringContainsString('Dein Einsatz heute</', $heute);
        $gemischt = $this->kachel(['stempelZeilen' => [$this->zeile(), $vergessen], 'stempelMeldung' => null, 'duzen' => false]);
        $this->assertStringContainsString('Ihr Einsatz</', $gemischt);
    }
}
