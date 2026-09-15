<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Services\Comms\ConversationBulkHandler;

/**
 * Task 9, Fix-Runde 1, Befund 2: Sammel-Erledigen buendelt die Team-Pruefung
 * (EIN whereIn gegen comms_whatsapp_threads) und das Stempel-Schreiben (EIN
 * upsert) statt N Einzel-Queries je markiertem Chat.
 *
 * Der wichtigste Fall bleibt derselbe wie im gesamten Paket: KEIN Weg darf
 * einen Thread eines fremden Teams abhaken — auch nicht im gebuendelten Pfad,
 * wo die Pruefung nicht mehr in einer Schleife steckt, sondern in einem
 * einzigen whereIn().
 */
class ConversationBulkHandlerTest extends TestCase
{
    private const TEAM = 703;
    private const FREMDES_TEAM = 909;

    private static int $channelId = 0;
    private static int $threadEigenesTeam1 = 0;
    private static int $threadEigenesTeam2 = 0;
    private static int $threadFremdesTeam = 0;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([]));

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

        self::runMigrations();

        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559001', 'is_active' => true,
        ]);

        self::$threadEigenesTeam1 = self::createThread(self::TEAM, self::$channelId, '+49 151 80000001');
        self::$threadEigenesTeam2 = self::createThread(self::TEAM, self::$channelId, '+49 151 80000002');
        self::$threadFremdesTeam = self::createThread(self::FREMDES_TEAM, self::$channelId, '+49 151 80000003');
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_stempelt_nur_threads_des_eigenen_teams_und_meldet_das_zurueck(): void
    {
        $handler = new ConversationBulkHandler();

        $handled = $handler->markManyHandled(
            self::TEAM,
            [self::$threadEigenesTeam1, self::$threadFremdesTeam, 999999],
            42,
        );

        $this->assertSame(
            [self::$threadEigenesTeam1],
            $handled,
            'Der Rueckgabewert muss die fremde ID und die nicht existierende ID herausfiltern.',
        );

        $eigene = RecConversationHandled::query()
            ->where('comms_whatsapp_thread_id', self::$threadEigenesTeam1)
            ->first();
        $this->assertNotNull($eigene);
        $this->assertSame(self::TEAM, (int) $eigene->team_id);
        $this->assertSame(42, (int) $eigene->handled_by_user_id);

        $this->assertSame(
            0,
            RecConversationHandled::query()->where('comms_whatsapp_thread_id', self::$threadFremdesTeam)->count(),
            'Ein Thread eines fremden Teams darf durch das gebuendelte Abhaken NIEMALS gestempelt werden.',
        );
        $this->assertSame(
            0,
            RecConversationHandled::query()->where('comms_whatsapp_thread_id', 999999)->count(),
            'Eine nicht existierende ID darf keine Zeile erzeugen.',
        );
    }

    public function test_leere_liste_stempelt_nichts_und_kracht_nicht(): void
    {
        $handler = new ConversationBulkHandler();

        $this->assertSame([], $handler->markManyHandled(self::TEAM, [], 1));
    }

    public function test_liste_nur_aus_fremden_ids_stempelt_nichts(): void
    {
        $handler = new ConversationBulkHandler();

        $this->assertSame([], $handler->markManyHandled(self::TEAM, [self::$threadFremdesTeam], 1));
    }

    public function test_erneuter_aufruf_aktualisiert_den_bestehenden_stempel_ohne_dublette(): void
    {
        $handler = new ConversationBulkHandler();

        $handler->markManyHandled(self::TEAM, [self::$threadEigenesTeam2], 5);
        $handler->markManyHandled(self::TEAM, [self::$threadEigenesTeam2], 9);

        $rows = RecConversationHandled::query()
            ->where('comms_whatsapp_thread_id', self::$threadEigenesTeam2)
            ->get();

        $this->assertCount(
            1,
            $rows,
            'upsert() darf keine zweite Zeile fuer denselben Thread anlegen (unique auf comms_whatsapp_thread_id).',
        );
        $this->assertSame(9, (int) $rows->first()->handled_by_user_id);
    }

    private static function createThread(int $teamId, int $channelId, string $phone): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => $teamId,
            'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'is_unread' => false,
        ])->id;
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);

        $files = [
            [$own, 'database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        return dirname((new \ReflectionClass($class))->getFileName(), 3);
    }
}
