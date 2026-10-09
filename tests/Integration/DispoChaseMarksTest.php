<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Services\Zas\Dispo\DispoChaseMarks;

/**
 * „Wo bist du?" in der VA (Kunde 09.10.): welche Person hat an welchem Tag
 * die Vorlage bekommen — Grundlage der eingefaerbten Sprechblase.
 */
class DispoChaseMarksTest extends TestCase
{
    private const TEMPLATE = 'wo_bist_du';

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
        self::setTemplates([
            ['key' => 'init', 'label' => 'Gespräch starten', 'template' => 't_init'],
            ['key' => 'wann', 'label' => 'Wann bist du da?', 'template' => 't_wo_bist'],
            ['key' => 'wo', 'label' => 'Wo bist du?', 'template' => self::TEMPLATE],
        ]);

        $crm = self::packageRootOf(CrmContactLink::class);
        foreach ([
            'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php',
            'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php',
        ] as $relative) {
            (require $crm . '/' . $relative)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('comms_whatsapp_messages')->delete();
    }

    public function test_template_name_comes_from_the_wo_entry_of_the_chat_templates(): void
    {
        $this->assertSame(self::TEMPLATE, DispoChaseMarks::templateName());

        self::setTemplates([['key' => 'wann', 'label' => 'Wann bist du da?', 'template' => 't_wo_bist']]);
        try {
            $this->assertNull(DispoChaseMarks::templateName());
        } finally {
            self::setTemplates([
                ['key' => 'wann', 'label' => 'Wann bist du da?', 'template' => 't_wo_bist'],
                ['key' => 'wo', 'label' => 'Wo bist du?', 'template' => self::TEMPLATE],
            ]);
        }
    }

    public function test_marks_the_send_day_with_the_last_time_of_that_day(): void
    {
        $this->message(10, self::TEMPLATE, '2026-10-10 09:15:00');
        $this->message(10, self::TEMPLATE, '2026-10-10 14:32:00');
        $this->message(10, self::TEMPLATE, '2026-10-11 08:05:00');

        $this->assertSame(
            [1 => ['2026-10-10' => '14:32', '2026-10-11' => '08:05']],
            DispoChaseMarks::forPersons([1 => [10]], self::TEMPLATE, '2026-10-10', '2026-10-12')
        );
    }

    public function test_counts_every_conversation_of_the_person_and_keeps_persons_apart(): void
    {
        $this->message(10, self::TEMPLATE, '2026-10-10 09:00:00');
        $this->message(11, self::TEMPLATE, '2026-10-11 09:00:00'); // zweites Gespraech (andere Filiale)
        $this->message(20, self::TEMPLATE, '2026-10-12 09:00:00'); // andere Person

        $this->assertSame(
            [
                1 => ['2026-10-10' => '09:00', '2026-10-11' => '09:00'],
                2 => ['2026-10-12' => '09:00'],
            ],
            DispoChaseMarks::forPersons([1 => [10, 11], 2 => [20]], self::TEMPLATE, '2026-10-10', '2026-10-12')
        );
    }

    public function test_ignores_other_templates_inbound_failed_foreign_threads_and_days_outside_the_event(): void
    {
        $this->message(10, 't_wo_bist', '2026-10-10 09:00:00');                // „Wann bist du da?"
        $this->message(10, self::TEMPLATE, '2026-10-10 09:01:00', 'inbound');
        $this->message(10, self::TEMPLATE, '2026-10-10 09:02:00', 'outbound', 'failed');
        $this->message(99, self::TEMPLATE, '2026-10-10 09:03:00');             // Gespraech einer fremden Person
        $this->message(10, self::TEMPLATE, '2026-10-09 23:59:00');             // vor der VA
        $this->message(10, self::TEMPLATE, '2026-10-13 00:00:00');             // nach der VA

        $this->assertSame([], DispoChaseMarks::forPersons([1 => [10]], self::TEMPLATE, '2026-10-10', '2026-10-12'));
    }

    public function test_counts_sent_delivered_and_read(): void
    {
        $this->message(10, self::TEMPLATE, '2026-10-10 09:00:00', 'outbound', 'sent');
        $this->message(11, self::TEMPLATE, '2026-10-11 09:00:00', 'outbound', 'delivered');
        $this->message(12, self::TEMPLATE, '2026-10-12 09:00:00', 'outbound', 'read');

        $this->assertSame(
            [1 => ['2026-10-10' => '09:00', '2026-10-11' => '09:00', '2026-10-12' => '09:00']],
            DispoChaseMarks::forPersons([1 => [10, 11, 12]], self::TEMPLATE, '2026-10-10', '2026-10-12')
        );
    }

    public function test_no_conversations_means_no_marks(): void
    {
        $this->message(10, self::TEMPLATE, '2026-10-10 09:00:00');

        $this->assertSame([], DispoChaseMarks::forPersons([], self::TEMPLATE, '2026-10-10', '2026-10-12'));
        $this->assertSame([], DispoChaseMarks::forPersons([1 => []], self::TEMPLATE, '2026-10-10', '2026-10-12'));
    }

    private function message(int $threadId, string $template, string $at, string $direction = 'outbound', string $status = 'delivered'): void
    {
        Capsule::table('comms_whatsapp_messages')->insert([
            'comms_whatsapp_thread_id' => $threadId,
            'direction'     => $direction,
            'message_type'  => 'template',
            'template_name' => $template,
            'status'        => $status,
            'created_at'    => $at,
            'updated_at'    => $at,
        ]);
    }

    private static function setTemplates(array $templates): void
    {
        Container::getInstance()->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['dispo_chat_templates' => $templates]],
        ]));
        Facade::clearResolvedInstance('config');
    }

    /** Wurzel des Composer-Pakets einer geladenen Klasse (Modulmuster). */
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
