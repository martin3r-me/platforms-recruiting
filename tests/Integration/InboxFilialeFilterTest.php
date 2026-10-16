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
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;

/**
 * Filter nach Filiale in der HR-Kommunikation (Markus 16.10.2026). Filiale =
 * Hunderterblock der Kostenstelle; die Unternummer (401, 103 …) bleibt an der
 * Akte, zaehlt hier aber zum Block. Anders als Owner/Suche schneidet die
 * Filiale auch die Zaehler: bei "MGL" zeigen die Pillen nur MGL.
 */
class InboxFilialeFilterTest extends TestCase
{
    private const TEAM = 905;
    private const JETZT = 1_757_930_000;

    private static int $bewerberMgl = 0;      // Bewerbung an Stelle mit Kostenstelle 200
    private static int $mitarbeiterCgn = 0;   // MA-Thread, eigene Kostenstelle 401
    private static int $bewerberMitMa = 0;    // Bewerbung (Stelle 200), aktive Anstellung mit 103
    private static int $maUeberStelle = 0;    // MA ohne eigene Kostenstelle, Stelle 400
    private static int $ohneZuordnung = 0;

    private static array $previousMorphMap = [];

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

        self::$previousMorphMap = Relation::morphMap();
        Relation::morphMap(['rec_applicant' => RecApplicant::class]);

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        $integrations = dirname((new \ReflectionClass(IntegrationsWhatsAppTemplate::class))->getFileName(), 3);
        foreach ([
            [$own, '2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, '2026_09_15_000002_create_rec_conversation_handled_table.php'],
            [$own, '2026_02_09_000001_create_rec_positions_table.php'],
            [$own, '2026_05_21_000006_add_cost_center_to_rec_positions.php'],
            [$own, '2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, '2026_08_18_000001_add_rec_position_id_to_rec_applicants.php'],
            [$own, '2026_05_20_000001_create_rec_employees_table.php'],
            [$own, '2026_07_07_000001_add_cost_center_to_rec_employees.php'],
            [$crm, '2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, '2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, '2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$crm, '2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$integrations, '2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            // hydrate() laedt Bewerber samt CRM-Kontakt fuer die Namen.
            [$crm, '2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, '2024_01_01_000020_create_crm_contact_links_table.php'],
        ] as [$root, $file]) {
            (require $root . '/database/migrations/' . $file)->up();
        }

        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Relation::morphMap(self::$previousMorphMap, false);
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_jeder_chat_landet_in_seinem_block(): void
    {
        $snap = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);

        // 100: Bewerbung mit Anstellung 103 · 200: Bewerbung an Stelle 200
        // 400: MA mit 401 + MA ueber Stelle 400 · ohne: Chat ohne Kontext
        $this->assertSame(['100' => 1, '200' => 1, '400' => 2, 'ohne' => 1], $snap['filialen']);
    }

    public function test_filter_zeigt_nur_die_filiale_und_die_zaehler_gehen_mit(): void
    {
        $cgn = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(filiale: '400'), 50, 0, self::JETZT);

        $ids = array_map(fn ($r) => $r->threadId, $cgn['rows']);
        sort($ids);
        $erwartet = [self::$mitarbeiterCgn, self::$maUeberStelle];
        sort($erwartet);
        $this->assertSame($erwartet, $ids);
        $this->assertSame(2, $cgn['counts']['total']);
        $this->assertSame(2, $cgn['counts']['unread']);
        $this->assertSame(2, $cgn['counts']['employees']);
        $this->assertSame(0, $cgn['counts']['applicants']);
        // Die Auswahl zeigt weiter die Zahlen ALLER Filialen.
        $this->assertSame(5, array_sum($cgn['filialen']));

        $alle = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        $this->assertSame(5, $alle['counts']['total']);
        $this->assertSame(5, $alle['counts']['unread']);
    }

    public function test_bewerbung_mit_aktiver_anstellung_zaehlt_mit_der_kostenstelle_der_anstellung(): void
    {
        $dus = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(filiale: '100'), 50, 0, self::JETZT);
        $mgl = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(filiale: '200'), 50, 0, self::JETZT);

        $this->assertSame([self::$bewerberMitMa], array_map(fn ($r) => $r->threadId, $dus['rows']));
        $this->assertSame([self::$bewerberMgl], array_map(fn ($r) => $r->threadId, $mgl['rows']));
    }

    public function test_ohne_filiale_ist_eigener_filter(): void
    {
        $ohne = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(filiale: 'ohne'), 50, 0, self::JETZT);

        $this->assertSame([self::$ohneZuordnung], array_map(fn ($r) => $r->threadId, $ohne['rows']));
    }

    private static function seed(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-filiale', 'phone_number' => '+49 160 5559501',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        $channel = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559501', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create(['team_id' => self::TEAM, 'settings' => ['auto_pilot_wa_account_id' => $accountId]]);

        $stelle = fn (int $kst) => (int) Capsule::table('rec_positions')->insertGetId([
            'uuid' => 'pos-' . $kst, 'team_id' => self::TEAM, 'title' => 'Stelle ' . $kst, 'cost_center' => $kst,
        ]);
        $s200 = $stelle(200);
        $s400 = $stelle(400);

        $bewerbung = fn (int $id, int $positionId) => Capsule::table('rec_applicants')->insert([
            'id' => $id, 'uuid' => 'app-' . $id, 'team_id' => self::TEAM, 'progress' => 0, 'is_active' => true,
            'auto_pilot' => false, 'rec_position_id' => $positionId,
        ]);
        $bewerbung(701, $s200);
        $bewerbung(702, $s200);

        $ma = fn (int $id, ?string $kst, ?int $positionId, ?int $applicantId) => Capsule::table('rec_employees')->insert([
            'id' => $id, 'uuid' => 'ma-' . $id, 'team_id' => self::TEAM, 'is_active' => true,
            'cost_center' => $kst, 'rec_position_id' => $positionId, 'rec_applicant_id' => $applicantId,
        ]);
        $ma(801, '401', $s200, null);   // eigene 401 gewinnt vor Stelle 200
        $ma(802, null, $s400, null);    // nur ueber Stelle
        $ma(803, '103', null, 702);     // Anstellung zur Bewerbung 702

        $thread = fn (string $nr, ?string $model, ?int $id) => (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => $channel, 'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $nr, 'context_model' => $model, 'context_model_id' => $id,
            'is_unread' => true, 'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 3_600),
        ])->id;
        self::$bewerberMgl = $thread('+4915100000701', 'rec_applicant', 701);
        self::$bewerberMitMa = $thread('+4915100000702', 'rec_applicant', 702);
        self::$mitarbeiterCgn = $thread('+4915100000801', RecEmployee::class, 801);
        self::$maUeberStelle = $thread('+4915100000802', RecEmployee::class, 802);
        self::$ohneZuordnung = $thread('+4915100000999', null, null);
    }
}
