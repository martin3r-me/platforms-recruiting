<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Services\Zas\Dispo\DispoChatTemplateSender;

/**
 * Die drei festen Chat-Vorlagen (Kunde 01.09.): key wird gegen die Config
 * aufgeloest (ausschliesslich diese Templates sind versendbar), {{name}}
 * geht als Named-Parameter mit dem Vornamen raus, Versand ueber den Kanal
 * DES Threads. Echte Migrationen, WhatsAppMetaService als zaehlende
 * Attrappe (Muster DispoConfirmationSenderChannelTest).
 */
class DispoChatTemplateSenderTest extends TestCase
{
    private const TEAM = 701;

    private static int $channelId = 0;

    /** @var object{calls:int,log:array} */
    private object $stub;

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

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => [
                'inbound_team_id' => self::TEAM,
                'dispo_chat_templates' => [
                    ['key' => 'init', 'label' => 'Gespräch starten', 'template' => 't_init'],
                    ['key' => 'wann', 'label' => 'Wann bist du da?', 'template' => 't_wo_bist'],
                ],
            ]],
        ]));

        self::runMigrations();

        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'meta',
            'sender_identifier' => '+49 160 5559999', 'name' => 'Dispo MG', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('integrations_whatsapp_templates')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();

        $this->stub = new class {
            public int $calls = 0;
            /** @var list<array<string,mixed>> */
            public array $log = [];

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                $this->log[] = ['channel' => $channel, 'to' => $to, 'template' => $templateName, 'components' => $components];
                return (object) ['id' => 9000 + $this->calls, 'status' => 'sent'];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
    }

    private function template(string $name, string $body, string $status = 'APPROVED'): void
    {
        Capsule::table('integrations_whatsapp_templates')->insert([
            'uuid' => 'tpl-' . $name . '-' . mt_rand(), 'external_id' => 'ext-' . mt_rand(),
            'whatsapp_account_id' => 1, 'user_id' => 1, 'name' => $name, 'language' => 'de', 'status' => $status,
            'category' => 'UTILITY',
            'components' => json_encode([['type' => 'BODY', 'text' => $body]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function thread(): CommsWhatsAppThread
    {
        $id = (int) Capsule::table('comms_whatsapp_threads')->insertGetId([
            'team_id' => self::TEAM, 'token' => 'tok-' . mt_rand(), 'comms_channel_id' => self::$channelId,
            'remote_phone_number' => '+4917624533557', 'is_unread' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CommsWhatsAppThread::findOrFail($id);
    }

    public function test_sends_named_parameter_with_first_name_over_the_threads_channel(): void
    {
        $this->template('t_init', 'Hi {{name}}, hier ist die Dispo.');

        $r = (new DispoChatTemplateSender())->send($this->thread(), 'init', 'Tristan', null);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(1, $this->stub->calls);
        $call = $this->stub->log[0];
        $this->assertSame('t_init', $call['template']);
        $this->assertSame(self::$channelId, $call['channel']->id, 'Versand ueber den Kanal DES Threads.');
        $this->assertSame('+4917624533557', $call['to']);
        $this->assertSame([
            ['type' => 'body', 'parameters' => [['type' => 'text', 'parameter_name' => 'name', 'text' => 'Tristan']]],
        ], $call['components'], '{{name}} geht als Named-Parameter mit dem Vornamen raus.');
    }

    public function test_template_without_name_variable_sends_without_components(): void
    {
        $this->template('t_wo_bist', 'Wann bist du da?');

        $r = (new DispoChatTemplateSender())->send($this->thread(), 'wann', 'Tristan', null);

        $this->assertTrue($r['ok']);
        $this->assertSame([], $this->stub->log[0]['components']);
    }

    public function test_only_configured_keys_are_sendable(): void
    {
        $r = (new DispoChatTemplateSender())->send($this->thread(), 'boese_vorlage', 'X', null);

        $this->assertFalse($r['ok']);
        $this->assertSame('Unbekannte Vorlage.', $r['error']);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_unapproved_or_missing_template_yields_a_clear_error(): void
    {
        $this->template('t_init', 'Hi {{name}}!', 'PENDING');

        $r = (new DispoChatTemplateSender())->send($this->thread(), 'init', 'Tristan', null);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('nicht freigegeben', (string) $r['error']);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_missing_first_name_blocks_when_template_needs_it(): void
    {
        $this->template('t_init', 'Hi {{name}}!');

        $r = (new DispoChatTemplateSender())->send($this->thread(), 'init', '  ', null);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Vorname', (string) $r['error']);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_meta_failed_status_is_reported_as_error(): void
    {
        $this->template('t_init', 'Hi {{name}}!');
        $this->stub = new class {
            public int $calls = 0;
            public array $log = [];

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                return (object) ['id' => 1, 'status' => 'failed', 'meta_payload' => ['error' => ['message' => 'kaputt']]];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);

        $r = (new DispoChatTemplateSender())->send($this->thread(), 'init', 'Tristan', null);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('kaputt', (string) $r['error']);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(\Platform\Crm\Models\CommsChannel::class);
        $integrations = self::packageRootOf(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class);

        $files = [
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
