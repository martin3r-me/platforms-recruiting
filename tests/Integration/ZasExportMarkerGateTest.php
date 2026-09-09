<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\ZasExportMarkerCleanup;
use Platform\Recruiting\Observers\RecApplicantExportObserver;

/**
 * Das Gate am ZAS-Export-Marker und die Bereinigung des Altbestands.
 *
 * Vorher markierte der Observer bedingungslos, der Endpunkt filterte die
 * Zeilen wieder weg — am 09.09.2026 waren 1935 von 2252 gesetzten Markern per
 * Konstruktion nicht lieferbar. Ausgeliefert wurde nie etwas Falsches, aber
 * die einzige Kennzahl, an der man einen Auslieferungsstau erkennt, war damit
 * wertlos. Beide Bedingungen (versendeter Vertrag, kein Testdatensatz) muessen
 * dieselben sein wie in ZasExportController::fetchChangedApplicants — deshalb
 * dieser Test.
 */
class ZasExportMarkerGateTest extends TestCase
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

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('rec_applicants', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamp('export_changed_at')->nullable();
        });
        $schema->create('rec_contracts', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('rec_applicant_id');
            $table->dateTime('sent_at')->nullable();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_contracts')->delete();
        Capsule::table('rec_applicants')->delete();

        Capsule::table('rec_applicants')->insert([
            ['id' => 1, 'team_id' => 3, 'is_test' => false, 'export_changed_at' => null], // Vertrag versendet
            ['id' => 2, 'team_id' => 3, 'is_test' => false, 'export_changed_at' => null], // Vertrag nur angelegt
            ['id' => 3, 'team_id' => 3, 'is_test' => false, 'export_changed_at' => null], // gar kein Vertrag
            ['id' => 4, 'team_id' => 3, 'is_test' => true,  'export_changed_at' => null], // Testdatensatz mit Vertrag
        ]);
        Capsule::table('rec_contracts')->insert([
            ['rec_applicant_id' => 1, 'sent_at' => '2026-05-04 10:00:00'],
            ['rec_applicant_id' => 2, 'sent_at' => null],
            ['rec_applicant_id' => 4, 'sent_at' => '2026-05-04 10:00:00'],
        ]);
    }

    private function marker(int $id): ?string
    {
        return Capsule::table('rec_applicants')->where('id', $id)->value('export_changed_at');
    }

    public function test_only_deliverable_applicants_get_marked(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            (new MarkerProbe())->probeMark($id);
        }

        $this->assertNotNull($this->marker(1), 'Versendeter Vertrag muss markiert werden.');
        $this->assertNull($this->marker(2), 'Vertrag ohne sent_at ist nicht lieferbar.');
        $this->assertNull($this->marker(3), 'Ohne Vertrag ist nichts lieferbar.');
        $this->assertNull($this->marker(4), 'Testdatensaetze filtert der Endpunkt weg.');
    }

    /** Sobald der Vertrag rausgeht, greift der Marker — nichts geht verloren. */
    public function test_marking_works_once_the_contract_is_sent(): void
    {
        (new MarkerProbe())->probeMark(2);
        $this->assertNull($this->marker(2));

        Capsule::table('rec_contracts')
            ->where('rec_applicant_id', 2)
            ->update(['sent_at' => '2026-06-01 09:00:00']);

        (new MarkerProbe())->probeMark(2);
        $this->assertNotNull($this->marker(2));
    }

    public function test_cleanup_splits_deliverable_from_noise(): void
    {
        Capsule::table('rec_applicants')->update(['export_changed_at' => '2026-08-18 21:02:01']);

        $probe = new CleanupProbe();

        $this->assertSame([1], $probe->probeQuery(null, true)->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([2, 3, 4], $probe->probeQuery(null, false)->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all());
    }

    public function test_cleanup_nulls_only_the_noise(): void
    {
        Capsule::table('rec_applicants')->update(['export_changed_at' => '2026-08-18 21:02:01']);

        $affected = (new CleanupProbe())->probeQuery(null, false)->update(['export_changed_at' => null]);

        $this->assertSame(3, $affected);
        $this->assertNotNull($this->marker(1), 'Der lieferbare Marker bleibt stehen.');
        $this->assertNull($this->marker(2));
        $this->assertNull($this->marker(3));
        $this->assertNull($this->marker(4));
    }

    public function test_cleanup_respects_team_filter(): void
    {
        Capsule::table('rec_applicants')->update(['export_changed_at' => '2026-08-18 21:02:01']);
        Capsule::table('rec_applicants')->where('id', 3)->update(['team_id' => 9]);

        $this->assertSame([2, 4], (new CleanupProbe())->probeQuery(3, false)
            ->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all());
    }
}

final class MarkerProbe extends RecApplicantExportObserver
{
    public function probeMark(int $applicantId): void
    {
        self::markApplicantId($applicantId);
    }
}

final class CleanupProbe extends ZasExportMarkerCleanup
{
    /** @return \Illuminate\Database\Query\Builder */
    public function probeQuery(?int $teamId, bool $deliverable)
    {
        return $this->query($teamId, $deliverable);
    }
}
