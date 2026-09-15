<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecConversationHandled;

/**
 * Task 1: Der Erledigt-Stempel liegt in einer eigenen Recruiting-Tabelle
 * (die Thread-Tabelle selbst gehoert platform-crm). Ein Thread darf nur
 * EINEN Stempel tragen — sonst haengt der Zustand davon ab, welche Zeile
 * zuerst gelesen wird.
 */
class ConversationHandledTableTest extends TestCase
{
    private const TEAM = 701;

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

        $migration = require dirname(__DIR__, 2)
            . '/database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php';
        $migration->up();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    public function test_stempel_laesst_sich_schreiben_und_lesen(): void
    {
        $row = RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => 4711,
            'handled_at' => '2026-09-15 10:00:00',
            'handled_by_user_id' => 1,
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);

        $this->assertSame('manual', $row->handled_reason);
        $this->assertSame('2026-09-15 10:00', $row->fresh()->handled_at->format('Y-m-d H:i'));
    }

    public function test_ein_thread_kann_nur_einen_stempel_tragen(): void
    {
        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => 4712,
            'handled_at' => '2026-09-15 10:00:00',
            'handled_reason' => RecConversationHandled::REASON_BACKFILL,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => 4712,
            'handled_at' => '2026-09-15 11:00:00',
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);
    }
}
