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
use Platform\Recruiting\Models\RecDeliveryFailureAck;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DeliveryFailureReason;
use Platform\Recruiting\Services\Comms\UndeliveredQuery;

/**
 * Reiter "Nicht zugestellt": wer steht drin, wer nicht.
 *
 * Kernfall: frischer Bewerber, erstes Template scheitert, er schreibt nie —
 * in der Chat-Liste (nur Threads mit Eingang) unsichtbar. Hier muss er stehen.
 */
class UndeliveredQueryTest extends TestCase
{
    private const TEAM = 731;
    private const TEAM_OHNE_KANAL = 732;
    private const JETZT = 1_757_930_000;

    private static int $channelId = 0;
    private static int $fremdChannelId = 0;
    /** @var array<string, int> */
    private static array $t = [];

    private static array $previousMorphMap = [];
    private static bool $previousRequireMorphMap = false;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

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

        // Wie InboxQueryCompletenessTest: Alias setzen, Zustand danach exakt zurueck.
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

    public function test_frischer_bewerber_ohne_antwort_mit_gescheitertem_template_steht_drin(): void
    {
        $row = $this->row(self::$t['frisch']);

        $this->assertNotNull($row, 'Der Kernfall: nie geantwortet, erstes Template gescheitert.');
        $this->assertSame('applicant', $row['subject_type']);
        $this->assertSame('Selin Yildiz', $row['name']);
        $this->assertSame(42, $row['owner_user_id']);
        $this->assertSame(131026, $row['reason']->code);
        $this->assertSame(DeliveryFailureReason::WHO_PERSON, $row['reason']->who);
        $this->assertSame('Vorlage „t_welcome“', $row['what']);
    }

    public function test_von_spaetem_sent_ueberschriebenes_failed_wird_ueber_den_payload_gefunden(): void
    {
        $row = $this->row(self::$t['ueberschrieben']);

        $this->assertNotNull($row, 'Altfall vor CRM-Fix #924: Status sent, Fehler nur noch im Payload.');
        $this->assertSame(131026, $row['reason']->code);
    }

    public function test_sofort_abgelehnter_versand_mit_einzelnem_error_objekt(): void
    {
        $row = $this->row(self::$t['sofort']);

        $this->assertNotNull($row);
        $this->assertSame(132001, $row['reason']->code);
        $this->assertSame(DeliveryFailureReason::WHO_SETUP, $row['reason']->who);
    }

    public function test_failed_ohne_code_steht_mit_unbekanntem_grund_drin(): void
    {
        $row = $this->row(self::$t['ohneCode']);

        $this->assertNotNull($row);
        $this->assertNull($row['reason']->code);
    }

    public function test_spaetere_zugestellte_nachricht_raeumt_ab(): void
    {
        $this->assertNull($this->row(self::$t['spaeterZugestellt']));
    }

    public function test_person_hat_danach_geschrieben(): void
    {
        $this->assertNull($this->row(self::$t['danachGeschrieben']));
    }

    public function test_zugestellt_trotz_fehler_im_payload_zaehlt_nicht(): void
    {
        $this->assertNull($this->row(self::$t['zugestelltMitFehler']));
    }

    public function test_aelter_als_das_zeitfenster(): void
    {
        $this->assertNull($this->row(self::$t['alt']));
    }

    public function test_fremder_kanal_bleibt_draussen(): void
    {
        $this->assertNull($this->row(self::$t['fremd']));
    }

    public function test_normal_gesendet_steht_nicht_drin(): void
    {
        $this->assertNull($this->row(self::$t['ok']));
    }

    public function test_zaehler_passt_zur_liste(): void
    {
        $query = new UndeliveredQuery();
        $this->assertSame(
            count($query->rows(self::TEAM, self::JETZT)),
            $query->count(self::TEAM, self::JETZT),
        );
        $this->assertSame(
            ['frisch', 'mitarbeiter', 'ohneCode', 'quittiert', 'sofort', 'ueberschrieben'],
            $this->namesOf(array_column($query->rows(self::TEAM, self::JETZT), 'thread_id')),
            'Alt-Quittung (message_id 0) deckt den Fehlschlag von "quittiert" nicht.',
        );
    }

    public function test_neueste_zuerst(): void
    {
        $ids = array_column((new UndeliveredQuery())->rows(self::TEAM, self::JETZT), 'thread_id');

        $this->assertSame(self::$t['ohneCode'], $ids[0], 'ohneCode ist der juengste Fehlschlag.');
    }

    public function test_erledigt_gilt_bis_zur_naechsten_gescheiterten_nachricht(): void
    {
        $query = new UndeliveredQuery();
        // Eigener Thread, damit dieser Ablauf den Bestand der anderen Tests nicht veraendert.
        $thread = self::thread(self::$channelId, '+49 151 80000099', 'rec_applicant', 699, null);
        self::message($thread, 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 4 * 86400);

        $this->assertNotNull($this->row($thread));
        $this->assertTrue($query->acknowledge(self::TEAM, $thread, 7, self::JETZT));
        $this->assertNull($this->row($thread), 'Nach Erledigt verschwindet der Eintrag.');

        // Neue Nachricht scheitert -> wieder da.
        self::message($thread, 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 60);
        $this->assertNotNull($this->row($thread), 'Ein neuer Fehlschlag holt ihn zurueck.');

        // Thread wieder neutralisieren: zugestellte Nachricht obendrauf.
        self::message($thread, 'delivered', [], self::JETZT - 30, null, true);
        $this->assertNull($this->row($thread));
    }

    public function test_aelterer_fehlschlag_aber_neueste_nachricht_nur_gesendet(): void
    {
        $this->assertNull($this->row(self::$t['neuesteGesendet']), 'Entscheidend ist nur die LETZTE ausgehende Nachricht.');
    }

    public function test_eingang_zur_selben_sekunde_gilt_als_geantwortet(): void
    {
        $this->assertNull($this->row(self::$t['gleichzeitig']));
    }

    public function test_mitarbeiter_kontext_wird_aufgeloest(): void
    {
        $row = $this->row(self::$t['mitarbeiter']);

        $this->assertNotNull($row);
        $this->assertSame('employee', $row['subject_type']);
        $this->assertSame('Ali Demir', $row['name']);
        $this->assertNull($row['owner_user_id']);
    }

    public function test_nicht_zugeordnet_zeigt_die_nummer(): void
    {
        $row = $this->row(self::$t['ohneCode']);

        $this->assertSame('unassigned', $row['subject_type']);
        $this->assertNull($row['subject_id']);
        $this->assertSame('+49 151 80000004', $row['name']);
    }

    public function test_fremdes_team_auf_demselben_kanal_bleibt_draussen(): void
    {
        $this->assertNull($this->row(self::$t['fremdesTeam']));
        $this->assertNull((new UndeliveredQuery())->threadForTeam(self::TEAM, self::$t['fremdesTeam']));
    }

    public function test_rueckfall_ohne_kanal_nimmt_nur_bewerber_und_mitarbeiter(): void
    {
        $ids = array_column((new UndeliveredQuery())->rows(self::TEAM_OHNE_KANAL, self::JETZT), 'thread_id');

        $this->assertSame([self::$t['rueckfallBewerber']], $ids, 'Ohne Konto: nur zugeordnete Bewerber/Mitarbeiter, kein blosser Kontakt.');
    }

    /** @param list<int> $ids @return list<string> */
    private function namesOf(array $ids): array
    {
        $names = array_map(fn (int $id) => (string) array_search($id, self::$t, true), $ids);
        sort($names);

        return $names;
    }

    public function test_erledigt_fuer_fremden_oder_unauffaelligen_thread_tut_nichts(): void
    {
        $query = new UndeliveredQuery();

        $this->assertFalse($query->acknowledge(self::TEAM, self::$t['ok'], 7, self::JETZT));
        $this->assertFalse($query->acknowledge(self::TEAM, self::$t['fremd'], 7, self::JETZT));
        $this->assertSame(0, RecDeliveryFailureAck::query()->where('comms_whatsapp_thread_id', self::$t['ok'])->count());
    }

    public function test_thread_for_team_sperrt_fremden_kanal(): void
    {
        $query = new UndeliveredQuery();

        $this->assertNotNull($query->threadForTeam(self::TEAM, self::$t['frisch']));
        $this->assertNull($query->threadForTeam(self::TEAM, self::$t['fremd']));
        $this->assertNull($query->threadForTeam(999, self::$t['frisch']));
    }

    private function row(int $threadId): ?array
    {
        foreach ((new UndeliveredQuery())->rows(self::TEAM, self::JETZT) as $row) {
            if ($row['thread_id'] === $threadId) {
                return $row;
            }
        }

        return null;
    }

    private static function seedFixtures(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-rec-undelivered', 'phone_number' => '+49 160 5554001',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        self::$channelId = self::channel('+49 160 5554001', $accountId);
        self::$fremdChannelId = self::channel('+49 160 5554999', 999999);
        RecApplicantSettings::create(['team_id' => self::TEAM, 'settings' => ['auto_pilot_wa_account_id' => $accountId]]);

        $tag = 86400;

        self::$t['frisch'] = self::thread(self::$channelId, '+49 151 80000001', 'rec_applicant', 655, null);
        self::message(self::$t['frisch'], 'failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]], self::JETZT - 3600, 't_welcome');
        Capsule::table('rec_applicants')->insert([
            'id' => 655, 'uuid' => 'uuid-applicant-655', 'team_id' => self::TEAM,
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false, 'owned_by_user_id' => 42,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contacts')->insert([
            'id' => 960, 'uuid' => 'uuid-contact-960', 'first_name' => 'Selin', 'last_name' => 'Yildiz',
            'team_id' => self::TEAM, 'is_active' => true,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'uuid-link-960', 'contact_id' => 960, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => 655, 'linkable_type' => 'rec_applicant',
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);

        self::$t['ueberschrieben'] = self::thread(self::$channelId, '+49 151 80000002', 'rec_applicant', 656, null);
        self::message(self::$t['ueberschrieben'], 'sent', ['messages' => [['id' => 'wamid.x']], 'errors' => [['code' => 131026]]], self::JETZT - 2 * $tag);

        self::$t['sofort'] = self::thread(self::$channelId, '+49 151 80000003', 'rec_applicant', 657, null);
        self::message(self::$t['sofort'], 'failed', ['error' => ['code' => 132001, 'message' => 'Template name does not exist']], self::JETZT - 3 * $tag);

        self::$t['ohneCode'] = self::thread(self::$channelId, '+49 151 80000004', null, null, null);
        self::message(self::$t['ohneCode'], 'failed', null, self::JETZT - 600);

        self::$t['spaeterZugestellt'] = self::thread(self::$channelId, '+49 151 80000005', 'rec_applicant', 658, null);
        self::message(self::$t['spaeterZugestellt'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 2 * $tag);
        self::message(self::$t['spaeterZugestellt'], 'delivered', ['messages' => []], self::JETZT - $tag, null, true);

        self::$t['danachGeschrieben'] = self::thread(self::$channelId, '+49 151 80000006', 'rec_applicant', 659, self::JETZT - 1000);
        self::message(self::$t['danachGeschrieben'], 'failed', ['errors' => [['code' => 131047]]], self::JETZT - 5000);

        self::$t['zugestelltMitFehler'] = self::thread(self::$channelId, '+49 151 80000007', 'rec_applicant', 660, null);
        self::message(self::$t['zugestelltMitFehler'], 'delivered', ['errors' => [['code' => 131026]]], self::JETZT - $tag, null, true);

        self::$t['alt'] = self::thread(self::$channelId, '+49 151 80000008', 'rec_applicant', 661, null);
        self::message(self::$t['alt'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - (UndeliveredQuery::DAYS + 1) * $tag);

        self::$t['fremd'] = self::thread(self::$fremdChannelId, '+49 151 80000009', 'rec_applicant', 662, null);
        self::message(self::$t['fremd'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 3600);

        self::$t['ok'] = self::thread(self::$channelId, '+49 151 80000010', 'rec_applicant', 663, null);
        self::message(self::$t['ok'], 'sent', ['messages' => [['id' => 'wamid.y']]], self::JETZT - 3600);

        self::$t['neuesteGesendet'] = self::thread(self::$channelId, '+49 151 80000012', 'rec_applicant', 665, null);
        self::message(self::$t['neuesteGesendet'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 2 * $tag);
        self::message(self::$t['neuesteGesendet'], 'sent', ['messages' => [['id' => 'wamid.z']]], self::JETZT - $tag);

        self::$t['gleichzeitig'] = self::thread(self::$channelId, '+49 151 80000013', 'rec_applicant', 666, self::JETZT - 7200);
        self::message(self::$t['gleichzeitig'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 7200);

        self::$t['mitarbeiter'] = self::thread(self::$channelId, '+49 151 80000014', RecEmployee::class, 77, null);
        self::message(self::$t['mitarbeiter'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 5 * $tag);
        Capsule::table('rec_employees')->insert([
            'id' => 77, 'uuid' => 'uuid-employee-77', 'team_id' => self::TEAM, 'first_name' => 'Ali', 'last_name' => 'Demir', 'is_active' => true,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);

        // Gleicher Kanal, anderes Team (darf nie auftauchen).
        self::$t['fremdesTeam'] = self::thread(self::$channelId, '+49 151 80000015', 'rec_applicant', 667, null, 733);
        self::message(self::$t['fremdesTeam'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 3600);

        // Team ohne Kanal-Konfiguration -> Rueckfall ueber den Kontext.
        self::$t['rueckfallBewerber'] = self::thread(self::$fremdChannelId, '+49 151 80000016', 'rec_applicant', 668, null, self::TEAM_OHNE_KANAL);
        self::message(self::$t['rueckfallBewerber'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 3600);
        self::$t['rueckfallKontakt'] = self::thread(self::$fremdChannelId, '+49 151 80000017', 'Platform\\Crm\\Models\\CrmContact', 5, null, self::TEAM_OHNE_KANAL);
        self::message(self::$t['rueckfallKontakt'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 3600);

        self::$t['quittiert'] = self::thread(self::$channelId, '+49 151 80000011', 'rec_applicant', 664, null);
        self::message(self::$t['quittiert'], 'failed', ['errors' => [['code' => 131026]]], self::JETZT - 4 * $tag);
        RecDeliveryFailureAck::create([
            'team_id' => self::TEAM, 'comms_whatsapp_thread_id' => self::$t['quittiert'],
            'comms_whatsapp_message_id' => 0, 'acknowledged_at' => date('Y-m-d H:i:s', self::JETZT - 5 * $tag),
        ]);
    }

    private static function channel(string $sender, int $accountId): int
    {
        return (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => $sender, 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
    }

    private static function thread(int $channelId, string $phone, ?string $contextModel, ?int $contextId, ?int $lastInboundAt, int $teamId = self::TEAM): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => $teamId,
            'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'context_model' => $contextModel,
            'context_model_id' => $contextId,
            'is_unread' => false,
            'last_inbound_at' => $lastInboundAt !== null ? date('Y-m-d H:i:s', $lastInboundAt) : null,
        ])->id;
    }

    private static function message(int $threadId, string $status, ?array $payload, int $at, ?string $template = null, bool $delivered = false): void
    {
        Capsule::table('comms_whatsapp_messages')->insert([
            'comms_whatsapp_thread_id' => $threadId,
            'direction' => 'outbound',
            'body' => $template !== null ? 'Template: ' . $template : 'Hallo',
            'message_type' => $template !== null ? 'template' : 'text',
            'template_name' => $template,
            'status' => $status,
            'meta_payload' => $payload !== null ? json_encode($payload) : null,
            'delivered_at' => $delivered ? date('Y-m-d H:i:s', $at + 5) : null,
            'created_at' => date('Y-m-d H:i:s', $at),
            'updated_at' => date('Y-m-d H:i:s', $at),
        ]);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_10_12_000001_create_rec_delivery_failure_acks_table.php'],
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
