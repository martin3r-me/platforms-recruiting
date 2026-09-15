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
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;
use Platform\Recruiting\Services\Comms\InboxRow;

/**
 * Abschluss-Durchsicht, Befund 2 (IMPORTANT) + Befund 3 (IMPORTANT).
 *
 * Befund 2: InboxQuery::matches() verglich $allowed['owner']/['search']
 * (Bewerber-IDs) bisher NUR gegen context_model_id — OHNE zu pruefen, dass
 * context_model ueberhaupt 'rec_applicant' ist. Ein Mitarbeiter-Thread mit
 * derselben Zahl als context_model_id matchte dadurch faelschlich den
 * Owner-/Suchfilter eines Bewerbers (zwei unabhaengige ID-Raeume, bei ~1000
 * Threads reale Kollisionsgefahr). Dieser Test provoziert die Kollision
 * bewusst: Bewerber #601 und Mitarbeiter #601 mit DERSELBEN numerischen ID.
 *
 * Befund 3: der Suchzweig lud bisher ALLE Bewerber des Teams samt CRM-
 * Kontakten (get()->filter()) und loeste NUR Bewerber-Namen auf — ein
 * Mitarbeiter-Chat war per Namenssuche nicht auffindbar. Jetzt laeuft die
 * Suche ueber whereHas()/LIKE in der DB und loest zusaetzlich Mitarbeiter auf.
 */
class InboxOwnerSearchIsolationTest extends TestCase
{
    private const TEAM = 891;
    private const JETZT = 1_757_930_000;

    private static int $channelId = 0;
    private static int $threadBewerber = 0;
    private static int $threadMitarbeiterKollision = 0;
    private static int $threadMitarbeiterEigeneId = 0;

    /** @var array<string, class-string> */
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

        self::$previousMorphMap = Relation::morphMap();
        self::$previousRequireMorphMap = Relation::requiresMorphMap();
        Relation::morphMap(['rec_applicant' => RecApplicant::class]);

        self::runMigrations();
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        Relation::morphMap(self::$previousMorphMap, false);
        Relation::requireMorphMap(self::$previousRequireMorphMap);

        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_owner_filter_trifft_nicht_den_mitarbeiter_mit_kollidierender_id(): void
    {
        // Bewerber #601 gehoert User 42 — der Mitarbeiter-Thread traegt
        // TOTAL zufaellig dieselbe Zahl (601) als context_model_id. Ohne die
        // context_model-Pruefung waere er hier faelschlich mitgekommen.
        $ids = $this->threadIds(new InboxFilter(owner: '42', currentUserId: 42));

        $this->assertContains(self::$threadBewerber, $ids);
        $this->assertNotContains(
            self::$threadMitarbeiterKollision,
            $ids,
            'Der Owner-Filter eines Bewerbers darf niemals einen Mitarbeiter-Thread treffen, '
                . 'nur weil dessen context_model_id zufaellig mit einer Bewerber-ID uebereinstimmt.',
        );
    }

    public function test_namenssuche_nach_bewerber_trifft_nicht_den_kollidierenden_mitarbeiter(): void
    {
        // "Mara" matcht den CRM-Kontakt des Bewerbers #601. Der
        // Mitarbeiter-Thread (context_model_id ebenfalls 601) darf trotzdem
        // nicht auftauchen — er hat einen eigenen Namen (nicht "Mara") UND
        // gehoert in den anderen ID-Raum.
        $ids = $this->threadIds(new InboxFilter(search: 'Mara'));

        $this->assertContains(self::$threadBewerber, $ids);
        $this->assertNotContains(self::$threadMitarbeiterKollision, $ids);
    }

    /**
     * Re-Review-Fix: die Suche nach dem VOLLEN Namen ("Mara Keller" — die
     * naheliegendste Suche ueberhaupt) fand bei der ersten Fassung des
     * Fixes nichts mehr, weil first_name/last_name je EINZELN per LIKE
     * geprueft wurden — kein Feld enthaelt die komplette Eingabe. Fix: die
     * Eingabe wird an Leerzeichen zerlegt, jedes Token muss treffen (UND-
     * verknuepft), "Mara Keller" UND "Keller Mara" (umgekehrte Reihenfolge)
     * treffen damit denselben Kontakt.
     */
    public function test_namenssuche_nach_vollem_namen_trifft(): void
    {
        $idsVornameZuerst = $this->threadIds(new InboxFilter(search: 'Mara Keller'));
        $this->assertContains(
            self::$threadBewerber,
            $idsVornameZuerst,
            'Der volle Name (Vorname zuerst) muss den Bewerber-Chat treffen.',
        );

        $idsNachnameZuerst = $this->threadIds(new InboxFilter(search: 'Keller Mara'));
        $this->assertContains(
            self::$threadBewerber,
            $idsNachnameZuerst,
            'Die Token-Reihenfolge darf keine Rolle spielen.',
        );

        // Beide Token muessen treffen — nur der halbe Name eines ANDEREN
        // Kontakts darf nicht durchrutschen.
        $idsHalberFremderName = $this->threadIds(new InboxFilter(search: 'Mara Hassan'));
        $this->assertNotContains(
            self::$threadBewerber,
            $idsHalberFremderName,
            '"Mara Hassan" darf den Bewerber Mara Keller nicht treffen — "Hassan" gehoert zu niemandem mit Vornamen Mara.',
        );
        $this->assertNotContains(self::$threadMitarbeiterEigeneId, $idsHalberFremderName);
    }

    /** Volle-Namen-Suche muss auch fuer Mitarbeiter greifen (Befund 3 + Re-Review-Fix). */
    public function test_namenssuche_nach_vollem_namen_trifft_auch_mitarbeiter(): void
    {
        $ids = $this->threadIds(new InboxFilter(search: 'Tarek Hassan'));

        $this->assertContains(self::$threadMitarbeiterEigeneId, $ids);
        $this->assertNotContains(self::$threadBewerber, $ids);
    }

    public function test_namenssuche_findet_jetzt_auch_mitarbeiter(): void
    {
        // Befund 3: vorher wurden bei der Namenssuche NUR RecApplicant-Namen
        // aufgeloest — ein Mitarbeiter-Chat war so nicht auffindbar.
        $ids = $this->threadIds(new InboxFilter(search: 'Hassan'));

        $this->assertContains(
            self::$threadMitarbeiterEigeneId,
            $ids,
            'Ein Mitarbeiter-Chat muss ueber seinen Namen auffindbar sein, genau wie ein Bewerber-Chat.',
        );
        $this->assertNotContains(self::$threadBewerber, $ids);
        $this->assertNotContains(self::$threadMitarbeiterKollision, $ids);
    }

    public function test_namenssuche_nach_mitarbeiter_trifft_nicht_zufaellig_bewerber_mit_kollidierender_id(): void
    {
        // Umgekehrte Kollisionsprobe: der NAME des kollidierenden
        // Mitarbeiters (#601) matcht hier nichts (anderer Name), aber falls
        // irgendein Bewerber zufaellig dieselbe ID wie ein gefundener
        // Mitarbeiter haette, duerfte das nicht zum Bewerber durchschlagen.
        // Diese Suche darf ohne Treffer bleiben.
        $ids = $this->threadIds(new InboxFilter(search: 'KeinTrefferXYZ'));

        $this->assertNotContains(self::$threadBewerber, $ids);
        $this->assertNotContains(self::$threadMitarbeiterKollision, $ids);
        $this->assertNotContains(self::$threadMitarbeiterEigeneId, $ids);
    }

    /** @return list<int> */
    private function threadIds(InboxFilter $filter): array
    {
        $result = (new InboxQuery())->page(self::TEAM, $filter, 50, 0, self::JETZT);

        return array_map(fn ($row) => $row->threadId, $result['rows']);
    }

    private static function seedFixtures(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-owner-search-isolation', 'phone_number' => '+49 160 5558001',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5558001', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);

        // Bewerber #601, Owner 42, CRM-Kontakt "Mara Keller".
        Capsule::table('rec_applicants')->insert([
            'id' => 601, 'uuid' => 'uuid-applicant-601', 'team_id' => self::TEAM,
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false,
            'owned_by_user_id' => 42,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contacts')->insert([
            'id' => 6010, 'uuid' => 'uuid-contact-6010', 'first_name' => 'Mara', 'last_name' => 'Keller',
            'team_id' => self::TEAM, 'is_active' => true,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'uuid-link-6010', 'contact_id' => 6010, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => 601, 'linkable_type' => 'rec_applicant',
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        self::$threadBewerber = self::createThread('+49 151 89001601', 'rec_applicant', 601);

        // Mitarbeiter MIT DERSELBEN numerischen ID (601) wie der Bewerber
        // oben — bewusste Kollision der beiden unabhaengigen ID-Raeume.
        Capsule::table('rec_employees')->insert([
            'id' => 601, 'uuid' => 'uuid-employee-601', 'team_id' => self::TEAM,
            'first_name' => 'Employee', 'last_name' => 'Sixzeroone',
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        self::$threadMitarbeiterKollision = self::createThread(
            '+49 151 89001602', \Platform\Recruiting\Models\RecEmployee::class, 601,
        );

        // Zweiter Mitarbeiter mit EIGENER (nicht kollidierender) ID — belegt
        // Befund 3: Mitarbeiter muessen ueber ihren Namen auffindbar sein.
        Capsule::table('rec_employees')->insert([
            'id' => 602, 'uuid' => 'uuid-employee-602', 'team_id' => self::TEAM,
            'first_name' => 'Tarek', 'last_name' => 'Hassan',
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        self::$threadMitarbeiterEigeneId = self::createThread(
            '+49 151 89001603', \Platform\Recruiting\Models\RecEmployee::class, 602,
        );
    }

    private static function createThread(string $phone, string $contextModel, int $contextId): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => self::$channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'context_model' => $contextModel,
            'context_model_id' => $contextId,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000),
            'last_message_preview' => 'Hallo',
        ])->id;
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
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$crm, 'database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
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
