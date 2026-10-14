<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersZugang;

/**
 * Wer darf auf /jobs/{token} zusagen? Gesperrt ist die Seite, wenn das Portal
 * irgendeines Datensatzes der Identitaets-Gruppe gesperrt ist — oder wenn der
 * Datensatz hinter dem Link inaktiv ist (ZAS-Status inaktiv, Spec 2026-10-14):
 * ein Inaktiver kann keine Einsaetze annehmen.
 */
class JobOffersZugangTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);

        // Nur die Spalten, die die Pruefung liest — ausdruecklich angelegt.
        Capsule::schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->default(1);
            $t->boolean('is_active')->default(true);
            $t->dateTime('portal_locked_at')->nullable();
            $t->timestamps();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
    }

    private function employee(bool $active = true, bool $locked = false): int
    {
        return (int) Capsule::table('rec_employees')->insertGetId([
            'is_active'        => $active,
            'portal_locked_at' => $locked ? '2026-10-01 10:00:00' : null,
        ]);
    }

    public function test_aktiver_ungesperrter_darf(): void
    {
        $id = $this->employee();

        $this->assertFalse(JobOffersZugang::gesperrt([$id], $id));
    }

    public function test_inaktiver_link_datensatz_ist_gesperrt(): void
    {
        $id = $this->employee(active: false);

        $this->assertTrue(JobOffersZugang::gesperrt([$id], $id));
    }

    public function test_inaktiver_geschwister_sperrt_nicht(): void
    {
        // Der Altdatensatz derselben Person ist inaktiv, der Link gehoert dem
        // aktiven — die Person darf zusagen.
        $aktiv = $this->employee();
        $alt   = $this->employee(active: false);

        $this->assertFalse(JobOffersZugang::gesperrt([$aktiv, $alt], $aktiv));
    }

    public function test_gesperrtes_portal_in_der_gruppe_sperrt_wie_bisher(): void
    {
        $aktiv    = $this->employee();
        $gesperrt = $this->employee(locked: true);

        $this->assertTrue(JobOffersZugang::gesperrt([$aktiv, $gesperrt], $aktiv));
    }

    public function test_seite_nutzt_die_pruefung_beim_laden_und_beim_zusagen(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Public/JobOffers.php');

        $this->assertSame(
            2,
            substr_count($src, '$this->gesperrt()'),
            'mount() und zusagen() pruefen beide ueber gesperrt()'
        );
        $this->assertStringContainsString(
            'JobOffersZugang::gesperrt($this->employeeIds, (int) $this->employeeId)',
            $src
        );
    }
}
