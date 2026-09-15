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
use Platform\Recruiting\Livewire\Conversations\Inbox;
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
        Container::getInstance()->forgetInstance('auth');
        Container::getInstance()->forgetInstance('session');
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        // 'auth'/'session' werden nur von den Fix-Runde-1-Tests gebunden
        // (Inbox::linkToApplicant() braucht Auth::user()->currentTeam->id und
        // session()->flash()) — nach jedem Test wieder loesen, damit keine
        // Bindung in einen anderen Test dieser Klasse durchsickert. Gezielt
        // ueber clearResolvedInstance() statt clearResolvedInstances(): das
        // trifft nur 'auth'/'session', nicht den 'config'-Cache, den dieselbe
        // Klasse fuer die gesamte Laufzeit haelt.
        Container::getInstance()->forgetInstance('auth');
        Container::getInstance()->forgetInstance('session');
        Facade::clearResolvedInstance('auth');
        Facade::clearResolvedInstance('session');
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

    /**
     * Fix-Runde 1, Befund 1 (CRITICAL): $applicantId kommt in
     * Inbox::linkToApplicant() vom Client — Livewire-Methoden sind mit
     * beliebigen Parametern aufrufbar, nicht nur mit dem, was im Panel
     * gerendert wurde. Ohne Team-Pruefung liesse sich ein Chat des EIGENEN
     * Teams an einen Bewerber eines FREMDEN Teams haengen (RecApplicant hat
     * keinen automatischen Team-Scope, addContext() im CRM prueft die ID
     * ueberhaupt nicht). Dieser Test ruft die echte Livewire-Methode auf —
     * nicht nur ApplicantThreadLinker direkt — damit die Team-Sperre in
     * Inbox.php selbst getroffen wird, nicht nur ihre Bausteine.
     */
    public function test_bewerber_eines_fremden_teams_wird_nicht_zugeordnet(): void
    {
        $eigenesTeam = 706;
        $fremdesTeam = 707;

        $threadId = $this->seedUnassignedThread($eigenesTeam, '+49 151 70009002', 999002);

        // Bewerber existiert, gehoert aber zu einem ANDEREN Team.
        Capsule::table('rec_applicants')->insert([
            'id' => 801, 'uuid' => 'uuid-applicant-801', 'team_id' => $fremdesTeam,
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false,
            'owned_by_user_id' => null,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);

        $session = self::bindFakeAuthAndSession($eigenesTeam);

        $inbox = new Inbox();
        $inbox->linkingThreadId = $threadId;
        $inbox->linkToApplicant(801);

        $this->assertArrayHasKey(
            'error',
            $session->flashed,
            'Die Zuordnung an einen fremden Bewerber muss als Fehler gemeldet werden, nicht kommentarlos scheitern.',
        );

        $frisch = CommsWhatsAppThread::query()->whereKey($threadId)->first();
        $this->assertSame(
            'Platform\\Crm\\Models\\CrmContact',
            $frisch->context_model,
            'Legacy-Spalten duerfen sich nicht aendern — die Zuordnung darf nicht gegriffen haben.',
        );
        $this->assertSame(999002, (int) $frisch->context_model_id);

        $this->assertSame(
            0,
            Capsule::table('comms_thread_contexts')
                ->where('thread_id', $threadId)
                ->where('context_model_id', 801)
                ->count(),
            'Es darf auch keine Pivot-Zeile fuer den fremden Bewerber entstanden sein.',
        );
    }

    /**
     * Fix-Runde 1, Befund 2 (CRITICAL): ein Thread mit echtem Fremd-Kontext
     * (contextLabel gesetzt, z.B. hcm_onboarding) ist bewusst sichtbar, aber
     * keine herrenlose Bewerbung — er darf nicht versehentlich einem
     * Bewerber zugeordnet werden. Die Anzeige (Knopf ausgeblendet) reicht
     * nicht, weil die Methode direkt aufrufbar ist — deshalb hier ein
     * direkter Aufruf von linkToApplicant() OHNE den Knopf je gesehen zu
     * haben, mit einem gueltigen Bewerber des EIGENEN Teams.
     */
    public function test_thread_mit_fremdkontext_wird_nicht_ueber_diesen_weg_zugeordnet(): void
    {
        $team = 708;

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-fremdkontext', 'phone_number' => '+49 160 5554003',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        $channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => $team, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5554003', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create([
            'team_id' => $team,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);

        // Gueltiger Bewerber DESSELBEN Teams — die Sperre muss trotzdem
        // greifen, weil der Thread einem fremden Fachprozess gehoert.
        Capsule::table('rec_applicants')->insert([
            'id' => 802, 'uuid' => 'uuid-applicant-802', 'team_id' => $team,
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false,
            'owned_by_user_id' => null,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);

        $threadId = (int) CommsWhatsAppThread::create([
            'team_id' => $team,
            'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => '+49 151 70009003',
            'context_model' => 'hcm_onboarding',
            'context_model_id' => 999003,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000),
            'last_message_preview' => 'Onboarding-Rueckfrage',
        ])->id;

        // Vorpruefung: die Zeile zeigt genau die Konstellation, die den
        // Knopf in der Blade ausblendet — subjectType bleibt 'unassigned',
        // aber contextLabel ist gesetzt.
        $row = (new InboxQuery())->rowForThread(
            CommsWhatsAppThread::find($threadId),
            $team,
        );
        $this->assertNotNull($row);
        $this->assertSame('unassigned', $row->subjectType);
        $this->assertSame('hcm_onboarding', $row->contextLabel);

        $session = self::bindFakeAuthAndSession($team);

        $inbox = new Inbox();
        $inbox->linkingThreadId = $threadId;
        $inbox->linkToApplicant(802);

        $this->assertArrayHasKey(
            'error',
            $session->flashed,
            'Ein Fremdkontext-Thread muss die Zuordnung als Fehler melden, nicht als Erfolg.',
        );

        $frisch = CommsWhatsAppThread::query()->whereKey($threadId)->first();
        $this->assertSame('hcm_onboarding', $frisch->context_model);
        $this->assertSame(999003, (int) $frisch->context_model_id);

        $this->assertSame(
            0,
            Capsule::table('comms_thread_contexts')
                ->where('thread_id', $threadId)
                ->where('context_model_id', 802)
                ->count(),
        );
    }

    /** Legt einen Kanal + Thread an, der noch am blossen CrmContact haengt (kein Bewerber). */
    private function seedUnassignedThread(int $teamId, string $phone, int $bareContactId): int
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-team-' . $teamId, 'phone_number' => $phone,
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        $channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => $teamId, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => $phone, 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create([
            'team_id' => $teamId,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);

        return (int) CommsWhatsAppThread::create([
            'team_id' => $teamId,
            'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'context_model' => 'Platform\\Crm\\Models\\CrmContact',
            'context_model_id' => $bareContactId,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000),
            'last_message_preview' => 'Hallo',
        ])->id;
    }

    /**
     * Bindet ein minimales 'auth'- und 'session'-Fake in den Container, damit
     * Inbox::teamId() (Auth::user()->currentTeam->id) und
     * session()->flash(...) ohne vollen Laravel-Boot funktionieren — genau
     * wie in der Produktivklasse, nur ohne echten Guard/Session-Handler.
     *
     * @return object{flashed: array<string, string>} Das Session-Fake, zum
     *         spaeteren Auslesen der geflashten Nachricht.
     */
    private static function bindFakeAuthAndSession(int $teamId): object
    {
        $user = new class {
            public int $id = 1;
            public $currentTeam;
        };
        $user->currentTeam = (object) ['id' => $teamId];

        $authStub = new class($user) {
            public function __construct(private object $user) {}
            public function user(): object
            {
                return $this->user;
            }
            public function id(): int
            {
                return (int) $this->user->id;
            }
        };

        $sessionStub = new class {
            public array $flashed = [];
            public function flash(string $key, $value): void
            {
                $this->flashed[$key] = $value;
            }
        };

        Container::getInstance()->instance('auth', $authStub);
        Container::getInstance()->instance('session', $sessionStub);

        return $sessionStub;
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
