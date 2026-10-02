<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Services\Comms\Forward\ForwardCompletion;

/**
 * Erledigt im HR-Chat erledigt die offenen Weiterleitungen (Runde 2).
 */
class ForwardCompletionTest extends TestCase
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

    private function forward(array $attrs): RecConversationForward
    {
        return RecConversationForward::create(array_merge([
            'team_id' => self::TEAM, 'source_thread_id' => 1, 'phone' => '+491700000001',
            'display_name' => 'X', 'messages' => [], 'forwarded_at' => '2026-10-02 09:00:00',
        ], $attrs));
    }

    public function test_erledigt_offene_hr_weiterleitungen_des_chats(): void
    {
        $a = $this->forward(['target_thread_id' => 50]);
        $b = $this->forward(['target_thread_id' => 50]);
        $n = (new ForwardCompletion())->completeForThreads(self::TEAM, [50], 7);
        $this->assertSame(2, $n);
        $this->assertNotNull($a->fresh()->done_at);
        $this->assertSame(7, (int) $b->fresh()->done_by_user_id);
    }

    public function test_fremdes_team_anderes_ziel_anderer_chat_bleiben_offen(): void
    {
        $fremd = $this->forward(['team_id' => self::TEAM + 1, 'target_thread_id' => 50]);
        $ziel = $this->forward(['target' => 'lohn', 'target_thread_id' => 50]);
        $chat = $this->forward(['target_thread_id' => 51]);
        $ohne = $this->forward(['target_thread_id' => null]);
        (new ForwardCompletion())->completeForThreads(self::TEAM, [50], 7);
        foreach ([$fremd, $ziel, $chat, $ohne] as $f) {
            $this->assertNull($f->fresh()->done_at);
        }
    }

    public function test_bereits_erledigte_bleiben_unveraendert(): void
    {
        $f = $this->forward(['target_thread_id' => 50, 'done_at' => '2026-10-01 08:00:00', 'done_by_user_id' => 3]);
        $this->assertSame(0, (new ForwardCompletion())->completeForThreads(self::TEAM, [50], 7));
        $this->assertSame('2026-10-01 08:00:00', $f->fresh()->done_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, (int) $f->fresh()->done_by_user_id);
    }

    public function test_leere_und_ungueltige_ids(): void
    {
        $this->forward(['target_thread_id' => 50]);
        $this->assertSame(0, (new ForwardCompletion())->completeForThreads(self::TEAM, [], 7));
        $this->assertSame(0, (new ForwardCompletion())->completeForThreads(self::TEAM, [0, -1], 7));
    }
}
