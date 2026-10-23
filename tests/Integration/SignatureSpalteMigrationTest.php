<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Hotfix 23.10.2026: die Migration laeuft durch und laesst eine Unterschrift
 * ueber 64 KB zu. SQLite kennt die TEXT-Grenze von MySQL nicht — gemessen
 * wird hier, dass die Migration auf beiden Tabellen ohne Fehler laeuft und
 * der Wert unverkuerzt zurueckkommt; die eigentliche Grenze sieht nur MySQL.
 */
final class SignatureSpalteMigrationTest extends TestCase
{
    public function test_migration_laeuft_und_grosse_unterschrift_bleibt_ganz(): void
    {
        $container = Container::getInstance();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        foreach (['rec_contracts', 'rec_document_recipients'] as $t) {
            Capsule::schema()->create($t, function ($table) {
                $table->increments('id');
                $table->text('signature_data')->nullable();
            });
        }

        (require dirname(__DIR__, 2) . '/database/migrations/2026_10_23_000001_widen_signature_data_columns.php')->up();

        $gross = 'data:image/png;base64,' . str_repeat('A', 200000);
        Capsule::table('rec_contracts')->insert(['signature_data' => $gross]);
        $this->assertSame(strlen($gross), strlen((string) Capsule::table('rec_contracts')->value('signature_data')));
        Facade::clearResolvedInstances();
    }
}
