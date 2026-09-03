<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoInfoSender;

/**
 * Crew-Info-Versand (Kunde 03.09.): {{name}} als Named-Parameter nur wenn der
 * Body ihn traegt, Portal-Token immer als URL-Button-Parameter, failed je
 * Empfaenger gesammelt. Kanal-Aufloesung und Meta-Call als Attrappen
 * (Muster DispoChatTemplateSenderTest) — die Kanal-Logik selbst deckt
 * DispoConfirmationSenderChannelTest ab.
 */
class DispoInfoSenderTest extends TestCase
{
    /** @var object{calls:int,log:array,failFor:array} */
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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => []]]));
        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('config');
        $c->forgetInstance(WhatsAppMetaService::class);
        $c->forgetInstance(DispoChannelResolver::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('integrations_whatsapp_templates')->delete();
        Capsule::table('rec_dispo_events')->delete();

        $this->stub = new class {
            public int $calls = 0;
            /** @var list<array<string,mixed>> */
            public array $log = [];
            /** @var list<string> */
            public array $failFor = [];

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                $this->log[] = ['to' => $to, 'template' => $templateName, 'components' => $components];
                if (in_array($to, $this->failFor, true)) {
                    return (object) ['id' => 1, 'status' => 'failed', 'meta_payload' => ['error' => ['message' => 'kaputt']]];
                }
                return (object) ['id' => 7000 + $this->calls, 'status' => 'sent'];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);

        // Kanal-Aufloesung: irgendein Objekt reicht — der Meta-Stub prueft es nicht.
        Container::getInstance()->instance(DispoChannelResolver::class, new class {
            public function resolveForEvent($event) { return new \Platform\Crm\Models\CommsChannel(); }
        });
    }

    private function template(string $body, string $status = 'APPROVED'): int
    {
        return (int) Capsule::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-' . mt_rand(), 'external_id' => 'ext-' . mt_rand(), 'user_id' => 1,
            'whatsapp_account_id' => 1, 'name' => 't_va_info', 'language' => 'de', 'status' => $status,
            'category' => 'UTILITY',
            'components' => json_encode([['type' => 'BODY', 'text' => $body]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function recipient(int $id = 1, string $phone = '+4917624533557'): array
    {
        return ['employee_id' => $id, 'phone' => $phone, 'first_name' => 'Tristan', 'portal_token' => 'tok-' . $id];
    }

    public function test_sends_name_param_and_token_button(): void
    {
        $templateId = $this->template('Hi {{name}}, es gibt neue Infos.');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-INFO-1']);

        $r = (new DispoInfoSender())->send($event, [$this->recipient()], $templateId);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['sent']);
        $this->assertSame([
            ['type' => 'body', 'parameters' => [['type' => 'text', 'parameter_name' => 'name', 'text' => 'Tristan']]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'tok-1']]],
        ], $this->stub->log[0]['components']);
    }

    public function test_body_without_name_gets_only_the_button(): void
    {
        $templateId = $this->template('Es gibt neue Infos zu deinem Einsatz.');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-INFO-2']);

        $r = (new DispoInfoSender())->send($event, [$this->recipient()], $templateId);

        $this->assertTrue($r['ok']);
        $this->assertSame([
            ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'tok-1']]],
        ], $this->stub->log[0]['components']);
    }

    public function test_failed_recipients_are_collected_and_do_not_abort(): void
    {
        $templateId = $this->template('Hi {{name}}!');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-INFO-3']);
        $this->stub->failFor[] = '+4915738762915';

        $r = (new DispoInfoSender())->send($event, [
            $this->recipient(1, '+4915738762915'),
            $this->recipient(2, '+4917624533557'),
        ], $templateId);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['sent']);
        $this->assertSame(1, $r['failed'][0]['employee_id']);
        $this->assertStringContainsString('kaputt', $r['failed'][0]['error']);
        $this->assertSame(2, $this->stub->calls, 'Der zweite Empfaenger wird trotz Fehler beim ersten bedient.');
    }

    public function test_unapproved_template_blocks_the_whole_send(): void
    {
        $templateId = $this->template('Hi {{name}}!', 'PENDING');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-INFO-4']);

        $r = (new DispoInfoSender())->send($event, [$this->recipient()], $templateId);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('nicht freigegeben', (string) $r['message']);
        $this->assertSame(0, $this->stub->calls);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $integrations = self::packageRootOf(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
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
