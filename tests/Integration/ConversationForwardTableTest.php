<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecConversationForward;

/**
 * Weiterleitung Dispo → HR (Spec 02.10.2026): eigene Tabelle, Kopie der
 * Texte als JSON, offen = done_at leer.
 */
class ConversationForwardTableTest extends TestCase
{
    private const TEAM = 711;

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

        (require dirname(__DIR__, 2) . '/database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php')->up();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_conversation_forwards')->delete();
    }

    private function forward(int $teamId, ?string $doneAt = null): RecConversationForward
    {
        return RecConversationForward::create([
            'team_id' => $teamId, 'source_thread_id' => 5, 'phone' => '+4917600000001',
            'display_name' => 'Jonas Stein',
            'messages' => [['message_id' => 1, 'body' => 'Wann kommt das Gehalt?', 'media_type' => null, 'received_at' => '2026-10-01T19:24:00+02:00']],
            'forwarded_at' => '2026-10-02 09:14:00', 'done_at' => $doneAt,
        ]);
    }

    public function test_messages_kommen_als_array_zurueck(): void
    {
        $f = $this->forward(self::TEAM)->fresh();
        $this->assertSame('Wann kommt das Gehalt?', $f->messages[0]['body']);
        $this->assertTrue($f->isOpen());
        $this->assertSame('dispo', $f->source);
        $this->assertSame('hr', $f->target);
    }

    public function test_open_for_team_filtert_auf_das_ziel(): void
    {
        $hr = $this->forward(self::TEAM);
        $this->forward(self::TEAM)->update(['target' => 'lohn']);

        $this->assertSame([(int) $hr->id], array_map('intval', RecConversationForward::query()->openForTeam(self::TEAM)->pluck('id')->all()));
        $this->assertSame(1, RecConversationForward::query()->openForTeam(self::TEAM, 'lohn')->count());
    }

    public function test_open_for_team_ignoriert_erledigte_und_fremde(): void
    {
        $offen = $this->forward(self::TEAM);
        $this->forward(self::TEAM, '2026-10-02 10:00:00');
        $this->forward(self::TEAM + 1);

        $ids = RecConversationForward::query()->openForTeam(self::TEAM)->pluck('id')->all();
        $this->assertSame([(int) $offen->id], array_map('intval', $ids));
    }
}
