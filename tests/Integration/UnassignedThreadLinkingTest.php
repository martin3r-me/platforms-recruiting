<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\ApplicantThreadLinker;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;

/**
 * Task 10 — der fachlich wichtigste Test des Pakets.
 *
 * Ein Thread, der noch am blossen CrmContact haengt (Fall 2474: 41 Threads,
 * davon 22 verlorene WhatsApp-Bewerbungen), wird ueber ApplicantThreadLinker
 * einem Bewerber zugeordnet. Danach MUSS der Chat weiterhin in der
 * InboxQuery-Liste stehen — jetzt als 'applicant' mit Bewerbernamen, nicht
 * mehr als 'unassigned'. Laeuft bewusst NUR ueber ApplicantThreadLinker +
 * InboxQuery, ohne Livewire (Inbox::linkToApplicant() ruft exakt denselben
 * Linker auf einem exakt so geladenen Thread auf — siehe threadForTeam()).
 */
class UnassignedThreadLinkingTest extends TestCase
{
    private const TEAM = 704;
    private const JETZT = 1_757_930_000;

    /** @var array<string, class-string> Zustand vor diesem Test — siehe tearDownAfterClass(). */
    private static array $previousMorphMap = [];
    private static bool $previousRequireMorphMap = false;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

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

        // Gleiche Begruendung wie in InboxQueryCompletenessTest::setUpBeforeClass():
        // morphMap() (nicht enforceMorphMap()) registriert 'rec_applicant' als
        // Alias fuer RecApplicant, damit ApplicantThreadLinker::link() (nutzt
        // getMorphClass()) denselben Alias schreibt, den die Fixtures und
        // ThreadContextGate::isBareContactContext() erwarten.
        self::$previousMorphMap = Relation::morphMap();
        self::$previousRequireMorphMap = Relation::requiresMorphMap();

        Relation::morphMap(['rec_applicant' => RecApplicant::class]);

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Relation::morphMap(self::$previousMorphMap, false);
        Relation::requireMorphMap(self::$previousRequireMorphMap);

        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_thread_am_blossen_crmcontact_wird_bewerber_zugeordnet_und_bleibt_sichtbar(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-unassigned-link', 'phone_number' => '+49 160 5554001',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);

        $channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5554001', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);

        // Bewerber, dem der Thread nachtraeglich zugeordnet werden soll — inkl.
        // CRM-Kontakt, damit die Zeile nach der Zuordnung einen echten Namen
        // zeigt (nicht nur einen Telefonnummer-Fallback).
        Capsule::table('rec_applicants')->insert([
            'id' => 701, 'uuid' => 'uuid-applicant-701', 'team_id' => self::TEAM,
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false,
            'owned_by_user_id' => null,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contacts')->insert([
            'id' => 950, 'uuid' => 'uuid-contact-950', 'first_name' => 'Amara', 'last_name' => 'Diallo',
            'team_id' => self::TEAM, 'is_active' => true,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'uuid-link-950', 'contact_id' => 950, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => 701, 'linkable_type' => 'rec_applicant',
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);

        // Der Chat selbst: haengt noch am blossen CrmContact eines ANDEREN,
        // unbeteiligten Kontakts (die Person schreibt zum ersten Mal, das CRM
        // haengt automatisch Contact-as-Context an — Fall 2474).
        $threadId = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => '+49 151 70009001',
            'context_model' => 'Platform\\Crm\\Models\\CrmContact',
            'context_model_id' => 999001,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000),
            'last_message_preview' => 'Hallo, ich bewerbe mich...',
        ])->id;

        $query = new InboxQuery();

        // Vorher: unsichtbar unter "zugeordnet" -- aber die neue Grundmenge
        // zeigt ihn trotzdem, als 'unassigned'. Das ist genau der Punkt aus
        // Task-Beschreibung: sichtbar, aber (noch) nicht zuordenbar markiert.
        $vorher = $query->page(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        $vorherRow = self::findRow($vorher['rows'], $threadId);
        $this->assertNotNull($vorherRow, 'Chat am blossen CrmContact muss schon vor dem Zuordnen sichtbar sein.');
        $this->assertSame('unassigned', $vorherRow->subjectType);
        $this->assertNull($vorherRow->subjectId);

        // Zuordnen — EIN Mechanismus, siehe ApplicantThreadLinker-Docblock.
        // threadForTeam()-Aequivalent: der Thread wird hier ganz normal aus
        // der DB geladen, exakt wie es Inbox::threadForTeam() tut.
        $thread = CommsWhatsAppThread::query()
            ->whereKey($threadId)
            ->where('team_id', self::TEAM)
            ->first();
        $this->assertNotNull($thread);

        ApplicantThreadLinker::link($thread, 701, 'inbox_manual');

        // Legacy-Spalten muessen jetzt auf den Bewerber zeigen (Beforderung,
        // weil der alte Kontext ein blosser CrmContact war).
        $frisch = CommsWhatsAppThread::query()->whereKey($threadId)->first();
        $this->assertSame('rec_applicant', $frisch->context_model);
        $this->assertSame(701, (int) $frisch->context_model_id);

        // Danach: weiterhin sichtbar, jetzt als Bewerber mit Namen.
        $nachher = $query->page(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        $nachherRow = self::findRow($nachher['rows'], $threadId);

        $this->assertNotNull(
            $nachherRow,
            'Der Chat darf nach dem Zuordnen NICHT aus der Liste verschwinden — genau das war Fall 2474.',
        );
        $this->assertSame('applicant', $nachherRow->subjectType);
        $this->assertSame(701, $nachherRow->subjectId);
        $this->assertSame('Amara Diallo', $nachherRow->title);
    }

    /** @param list<\Platform\Recruiting\Services\Comms\InboxRow> $rows */
    private static function findRow(array $rows, int $threadId): ?\Platform\Recruiting\Services\Comms\InboxRow
    {
        foreach ($rows as $row) {
            if ($row->threadId === $threadId) {
                return $row;
            }
        }

        return null;
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php'],
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_01_14_000004_create_comms_email_threads_table.php'],
            [$crm, 'database/migrations/2026_02_05_000001_add_context_to_comms_email_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$crm, 'database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$crm, 'database/migrations/2026_03_20_000001_create_comms_thread_contexts_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
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
