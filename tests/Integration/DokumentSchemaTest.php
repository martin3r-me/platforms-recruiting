<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Die Welt kommt aus den Migrationen (Muster TriggerStateSchemaTest): ein
 * handgebautes Schema koennte eine Spalte vergessen, und SQLite macht aus einem
 * fehlenden Spaltennamen kein Fehler, sondern ein String-Literal.
 */
final class DokumentSchemaTest extends TestCase
{
    private const DOKUMENTE  = 'database/migrations/2026_10_09_000001_create_rec_documents_table.php';
    private const EMPFAENGER = 'database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php';

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        (require dirname(__DIR__, 2) . '/' . self::DOKUMENTE)->up();
        (require dirname(__DIR__, 2) . '/' . self::EMPFAENGER)->up();
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_dokumente_tabelle_hat_alle_spalten(): void
    {
        foreach (['id', 'team_id', 'uuid', 'title', 'category', 'action', 'disk', 'stored_path',
                  'original_filename', 'file_sha256', 'file_size', 'rec_dispo_event_id',
                  'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'] as $spalte) {
            $this->assertTrue(Schema::hasColumn('rec_documents', $spalte), "Spalte fehlt: {$spalte}");
        }
    }

    public function test_empfaenger_tabelle_hat_alle_spalten(): void
    {
        foreach (['id', 'team_id', 'uuid', 'rec_document_id', 'rec_employee_id', 'person_key',
                  'notified_at', 'notify_error', 'first_viewed_at', 'acknowledged_at', 'signed_at',
                  'signature_data', 'withdrawn_at', 'created_at', 'updated_at'] as $spalte) {
            $this->assertTrue(Schema::hasColumn('rec_document_recipients', $spalte), "Spalte fehlt: {$spalte}");
        }
    }

    public function test_eine_anstellung_bekommt_ein_dokument_nur_einmal(): void
    {
        $docId = Capsule::table('rec_documents')->insertGetId([
            'team_id' => 1, 'uuid' => 'd-1', 'title' => 'T', 'category' => 'other', 'action' => 'none',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
            'created_at' => '2026-10-09 10:00:00', 'updated_at' => '2026-10-09 10:00:00',
        ]);
        $zeile = ['team_id' => 1, 'uuid' => 'r-1', 'rec_document_id' => $docId, 'rec_employee_id' => 7,
                  'created_at' => '2026-10-09 10:00:00', 'updated_at' => '2026-10-09 10:00:00'];
        Capsule::table('rec_document_recipients')->insert($zeile);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Capsule::table('rec_document_recipients')->insert(array_merge($zeile, ['uuid' => 'r-2']));
    }

    public function test_down_raeumt_beide_tabellen(): void
    {
        (require dirname(__DIR__, 2) . '/' . self::EMPFAENGER)->down();
        (require dirname(__DIR__, 2) . '/' . self::DOKUMENTE)->down();
        $this->assertFalse(Schema::hasTable('rec_documents'));
        $this->assertFalse(Schema::hasTable('rec_document_recipients'));
    }
}
