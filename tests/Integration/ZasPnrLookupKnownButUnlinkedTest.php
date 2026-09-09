<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasEmployeeMatcher;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * Der Kern von `recruiting:zas-pnr-lookup --unresolved`: bei einem Einsatz mit
 * „PNr unbekannt" muessen zwei Lagen unterscheidbar sein.
 *
 *  - Die Nummer ist bei uns NICHT bekannt → ZAS hat den Mitarbeiter nie
 *    geliefert. Frage an ZAS, kein Fehler bei uns.
 *  - Die Nummer IST bekannt und der Einsatz haengt trotzdem in der Luft → ein
 *    Zuordnungsfehler auf unserer Seite, behebbar mit dispo-reprocess.
 *
 * Der Unterschied entscheidet, wen man fragt, und ohne diese Auskunft wuerde
 * man den eigenen Fehler bei ZAS reklamieren. Der zweite Fall ist realistisch,
 * weil ZAS dieselbe Nummer in zwei Schreibweisen liefert: im Dispo-Export
 * gekuerzt (MA1000000124 → MA124), im Mitarbeiter-Export ungekuerzt. Genau
 * diese Form stand am 09.09.2026 mit drei ZUKUENFTIGEN Einsaetzen in der
 * unaufgeloesten Liste.
 */
class ZasPnrLookupKnownButUnlinkedTest extends TestCase
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
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $capsule->getConnection()->getSchemaBuilder()->create('rec_employees', function ($table) {
            $table->increments('id');
            $table->string('uuid', 36)->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('personnel_number', 32)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
        });

        Capsule::table('rec_employees')->insert([
            // Bestand traegt die GEKUERZTE Form ...
            ['id' => 1, 'uuid' => 'u-1', 'team_id' => 3, 'personnel_number' => 'MA124', 'first_name' => 'Bekannt', 'last_name' => 'Gekuerzt'],
            ['id' => 2, 'uuid' => 'u-2', 'team_id' => 3, 'personnel_number' => 'RG18292', 'first_name' => 'Bekannt', 'last_name' => 'Exakt'],
            // ... und einer traegt sie praefixlos (Bestand vor der Praefix-Umstellung)
            ['id' => 3, 'uuid' => 'u-3', 'team_id' => 3, 'personnel_number' => '14', 'first_name' => 'Bekannt', 'last_name' => 'Blank'],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    private function known(string $pnrFromAssignment): ?string
    {
        $prefix = ZasPersonnelNumber::DEFAULT_PREFIX;
        $normalized = ZasPersonnelNumber::normalize($pnrFromAssignment, $prefix) ?? $pnrFromAssignment;
        $match = (new ZasEmployeeMatcher())->match(null, $normalized, 3, $prefix);

        return $match['employee'] !== null ? (string) $match['via'] : null;
    }

    public function test_ungekuerzte_dispo_nummer_findet_den_gekuerzten_bestand(): void
    {
        $this->assertSame('shortened', $this->known('MA1000000124'));
    }

    public function test_exakte_nummer_wird_exakt_erkannt(): void
    {
        $this->assertSame('exact', $this->known('RG18292'));
    }

    public function test_praefixlose_bestandsnummer_wird_gefunden(): void
    {
        $this->assertSame('bare', $this->known('RG14'));
    }

    public function test_wirklich_unbekannte_nummer_bleibt_unbekannt(): void
    {
        $this->assertNull($this->known('MA17042'));
    }
}
