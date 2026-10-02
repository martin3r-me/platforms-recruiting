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
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\ApplicantTemplateSender;
use Platform\Recruiting\Services\Comms\Forward\ForwardFirstContact;
use Platform\Recruiting\Services\Comms\Forward\ForwardHrThreadLookup;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;

/**
 * Erstnachricht aus der Weiterleitung (Spec 02.10.2026). Versand-Attrappe nach
 * Muster ApplicantTemplateSenderAccountScopeTest. HoldingTemplateSender wird
 * mit einem konstruktorlosen WhatsAppMetaService gebaut — resolveTemplate()
 * fasst den Dienst nicht an.
 */
class ForwardFirstContactTest extends TestCase
{
    private const TEAM = 713;
    private const PHONE = '+4917663854907';

    private static int $channelId = 0;
    private static int $templateId = 0;
    private object $stub;

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

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        $int = dirname((new \ReflectionClass(IntegrationsWhatsAppTemplate::class))->getFileName(), 3);
        foreach ([
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_01_14_000004_create_comms_email_threads_table.php'],
            [$crm, 'database/migrations/2026_02_05_000001_add_context_to_comms_email_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_03_20_000001_create_comms_thread_contexts_table.php'],
            [$int, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$int, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ] as [$root, $rel]) {
            (require $root . '/' . $rel)->up();
        }

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-forward', 'phone_number' => '+49 160 5559713',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559713', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create(['team_id' => self::TEAM, 'settings' => ['auto_pilot_wa_account_id' => $accountId]]);
        self::$templateId = (int) Capsule::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-fwd', 'external_id' => 'ext-fwd', 'whatsapp_account_id' => $accountId, 'user_id' => 1,
            'name' => 't_com_gen', 'language' => 'de', 'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => json_encode([[
                'type' => 'BODY', 'text' => 'Hallo {{name}}, hier ist die Personalabteilung.',
                'example' => ['body_text_named_params' => [['param_name' => 'name', 'example' => 'Hans']]],
            ]]),
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
        Capsule::table('rec_conversation_forwards')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();
        Capsule::table('comms_thread_contexts')->delete();
        Capsule::table('rec_employees')->delete();

        $this->stub = new class {
            public int $calls = 0;
            public string $status = 'sent';
            public array $letzteComponents = [];
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null): object
            {
                $this->calls++;
                $this->letzteComponents = $components;
                return (object) ['id' => 9000 + $this->calls, 'status' => $this->status, 'meta_payload' => ['error' => ['message' => '131026']]];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
    }

    private function service(): ForwardFirstContact
    {
        $meta = (new \ReflectionClass(WhatsAppMetaService::class))->newInstanceWithoutConstructor();

        return new ForwardFirstContact(new HoldingTemplateSender($meta), new ApplicantTemplateSender(), new ForwardHrThreadLookup());
    }

    private function forward(?int $employeeId, int $teamId = self::TEAM): RecConversationForward
    {
        return RecConversationForward::create([
            'team_id' => $teamId, 'source_thread_id' => 1, 'rec_employee_id' => $employeeId,
            'phone' => self::PHONE, 'display_name' => 'Jonas Stein',
            'messages' => [['message_id' => 1, 'body' => 'Gehalt?', 'media_type' => null, 'received_at' => '']],
            'forwarded_at' => now(),
        ]);
    }

    private function employee(string $firstName = 'Jonas'): int
    {
        return (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => $firstName, 'last_name' => 'Stein',
            'personnel_number' => 'MA1', 'is_active' => true,
        ])->id;
    }

    private function hrThread(string $phone, ?string $lastInbound): int
    {
        return (int) Capsule::table('comms_whatsapp_threads')->insertGetId([
            'team_id' => self::TEAM, 'token' => 'tok-' . bin2hex(random_bytes(6)), 'comms_channel_id' => self::$channelId,
            'remote_phone_number' => $phone, 'is_unread' => false, 'last_inbound_at' => $lastInbound,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_sendet_vorlage_legt_hr_thread_an_und_schreibt_datensatz_fort(): void
    {
        $f = $this->forward($this->employee());

        $r = $this->service()->send($f, (object) ['id' => 3, 'name' => 'Clara']);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(1, $this->stub->calls);
        $f->refresh();
        $this->assertNotNull($f->first_contact_at);
        $this->assertSame(3, (int) $f->first_contact_by_user_id);
        $thread = CommsWhatsAppThread::findOrFail($f->target_thread_id);
        $this->assertSame(self::$channelId, (int) $thread->comms_channel_id);
        $this->assertSame(self::PHONE, $thread->remote_phone_number);
        $this->assertSame(1, Capsule::table('comms_thread_contexts')
            ->where('thread_id', $thread->id)->where('context_model', (new RecEmployee())->getMorphClass())->count());
        $this->assertStringContainsString('Jonas', json_encode($this->stub->letzteComponents));
    }

    public function test_versand_nutzt_immer_den_plus_thread(): void
    {
        // Vorhandener "+"-Thread wird wiederverwendet.
        $existing = $this->hrThread(self::PHONE, null);
        $f = $this->forward($this->employee());

        $this->assertTrue($this->service()->send($f, null)['ok']);

        $this->assertSame($existing, (int) $f->fresh()->target_thread_id);
        $this->assertSame(1, Capsule::table('comms_whatsapp_threads')->count());
    }

    public function test_altthread_ohne_plus_wird_nicht_versandziel(): void
    {
        // Meta schreibt die Vorlage in den "+"-Thread (findOrCreateForPhone mit
        // E.164) — ein Altthread "49…" darf nicht als Ziel gemerkt werden.
        $alt = $this->hrThread('4917663854907', null);
        $f = $this->forward($this->employee());

        $this->assertTrue($this->service()->send($f, null)['ok']);

        $this->assertSame(2, Capsule::table('comms_whatsapp_threads')->count());
        $target = CommsWhatsAppThread::findOrFail($f->fresh()->target_thread_id);
        $this->assertNotSame($alt, (int) $target->id);
        $this->assertSame(self::PHONE, $target->remote_phone_number);
    }

    public function test_zweite_weiterleitung_derselben_person_sendet_nicht_und_haengt_sich_an(): void
    {
        $emp = $this->employee();
        $first = $this->forward($emp);
        $second = $this->forward($emp);

        $this->assertTrue($this->service()->send($first, (object) ['id' => 4])['ok']);
        $this->assertSame(1, $this->stub->calls);
        $first->refresh();

        $this->assertSame((int) $first->id, (int) $this->service()->siblingFirstContact($second)?->id);
        $r = $this->service()->send($second, (object) ['id' => 5]);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertNull($r['error']);
        $this->assertSame(1, $this->stub->calls);
        $second->refresh();
        $this->assertSame((int) $first->target_thread_id, (int) $second->target_thread_id);
        $this->assertNotNull($second->first_contact_at);
        $this->assertSame($first->first_contact_at->format('Y-m-d H:i:s'), $second->first_contact_at->format('Y-m-d H:i:s'));
        $this->assertSame(4, (int) $second->first_contact_by_user_id);
    }

    public function test_geschwister_wird_ueber_beide_schreibweisen_gefunden(): void
    {
        $emp = $this->employee();
        $first = $this->forward($emp);
        $this->service()->send($first, null);
        $second = $this->forward($emp);
        $second->update(['phone' => '4917663854907']);

        $this->assertSame((int) $first->id, (int) $this->service()->siblingFirstContact($second->fresh())?->id);
    }

    public function test_geschwister_anderer_teams_oder_ziele_zaehlen_nicht(): void
    {
        $emp = $this->employee();
        $thread = $this->hrThread(self::PHONE, null);
        $fremdesTeam = $this->forward($emp, self::TEAM + 1);
        $fremdesTeam->update(['first_contact_at' => now(), 'target_thread_id' => $thread]);
        $fremdesZiel = $this->forward($emp);
        $fremdesZiel->update(['target' => 'lohn', 'first_contact_at' => now(), 'target_thread_id' => $thread]);
        // Ohne target_thread_id ist es kein Geschwister (nur geclaimt, nie zugeordnet).
        $ohneThread = $this->forward($emp);
        $ohneThread->update(['first_contact_at' => now()]);

        $f = $this->forward($emp);
        $this->assertNull($this->service()->siblingFirstContact($f));

        $this->assertTrue($this->service()->send($f, null)['ok']);
        $this->assertSame(1, $this->stub->calls);
    }

    public function test_claim_verhindert_versand_wenn_parallel_schon_gesetzt(): void
    {
        $stale = $this->forward($this->employee());
        // Zweiter HR-User / Doppelklick: in der DB schon gesetzt, im Speicher noch leer.
        Capsule::table('rec_conversation_forwards')->where('id', $stale->id)->update(['first_contact_at' => now()]);
        $this->assertNull($stale->first_contact_at);

        $r = $this->service()->send($stale, null);

        $this->assertFalse($r['ok']);
        $this->assertSame('Die Erstnachricht wurde schon gesendet.', $r['error']);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_ohne_konfiguriertes_konto_wird_nicht_gesendet(): void
    {
        $team = self::TEAM + 2;
        RecApplicantSettings::query()->where('team_id', $team)->delete();
        RecApplicantSettings::create(['team_id' => $team, 'settings' => ['auto_pilot_wa_account_id' => null]]);
        $f = $this->forward($this->employee(), $team);

        $r = $this->service()->send($f, null);

        $this->assertFalse($r['ok']);
        $this->assertSame('Kein WhatsApp-Konto für die HR-Kommunikation eingestellt.', $r['error']);
        $this->assertSame(0, $this->stub->calls);
        $this->assertNull($f->fresh()->first_contact_at);
    }

    public function test_hr_kanal_der_zugleich_dispo_kanal_ist_wird_gesperrt(): void
    {
        // Dispo-Bestaetigungsvorlage auf demselben Konto -> DispoChannelResolver
        // meldet den HR-Kanal als Dispo-Kanal.
        $config = Container::getInstance()->make('config');
        $settings = RecApplicantSettings::query()->where('team_id', self::TEAM)->firstOrFail();
        $original = $settings->settings;
        $config->set('recruiting.zas.inbound_team_id', self::TEAM);
        $settings->update(['settings' => array_merge($original, ['dispo_confirmation_template_id' => self::$templateId])]);
        try {
            $existing = $this->hrThread(self::PHONE, '2026-10-02 09:00:00');
            $f = $this->forward($this->employee());

            $r = $this->service()->send($f, null);

            $this->assertFalse($r['ok']);
            $this->assertSame('Der HR-Kanal ist zugleich ein Dispo-Kanal – Versand gesperrt.', $r['error']);
            $this->assertSame(0, $this->stub->calls);
            $this->assertNull($f->fresh()->first_contact_at);
            // Der Lookup liest keine Dispo-Threads als HR-Threads.
            $this->assertNull((new ForwardHrThreadLookup())->find(self::TEAM, self::PHONE));
            $this->assertNotSame(0, $existing);
        } finally {
            $config->set('recruiting.zas.inbound_team_id', null);
            $settings->update(['settings' => $original]);
        }
    }

    public function test_ohne_ma_wird_nicht_gesendet(): void
    {
        $f = $this->forward(null);

        $r = $this->service()->send($f, null);

        $this->assertFalse($r['ok']);
        $this->assertSame('Kein MA zugeordnet – die Vorlage braucht den Vornamen.', $r['error']);
        $this->assertSame(0, $this->stub->calls);
        $this->assertNull($f->fresh()->first_contact_at);
    }

    public function test_abgelehnter_versand_setzt_nichts_und_merkt_fehler(): void
    {
        $this->stub->status = 'failed';
        $f = $this->forward($this->employee());

        $r = $this->service()->send($f, null);

        $this->assertFalse($r['ok']);
        $f->refresh();
        $this->assertNull($f->first_contact_at);
        $this->assertNull($f->target_thread_id);
        $this->assertStringContainsString('131026', (string) $f->last_error);
    }

    public function test_abgelehnter_versand_auf_db_geladenem_datensatz_nimmt_claim_zurueck(): void
    {
        // Echter Pfad: Forwards::forwardForTeam() laedt aus der DB — dort sind
        // die Original-Werte null, ein Model-update(null) faellt still weg.
        $id = (int) $this->forward($this->employee())->id;
        $this->stub->status = 'failed';

        $r = $this->service()->send(RecConversationForward::query()->findOrFail($id), (object) ['id' => 7]);

        $this->assertFalse($r['ok']);
        $f = RecConversationForward::query()->findOrFail($id);
        $this->assertNull($f->first_contact_at);
        $this->assertNull($f->first_contact_by_user_id);
        $this->assertStringContainsString('131026', (string) $f->last_error);

        // Nicht dauerhaft blockiert: der naechste Versand geht durch.
        $this->stub->status = 'sent';
        $r2 = $this->service()->send(RecConversationForward::query()->findOrFail($id), (object) ['id' => 7]);

        $this->assertTrue($r2['ok'], (string) $r2['error']);
        $this->assertSame(2, $this->stub->calls);
        $this->assertNotNull(RecConversationForward::query()->findOrFail($id)->first_contact_at);
    }

    public function test_zweiter_aufruf_sendet_nicht_erneut(): void
    {
        $f = $this->forward($this->employee());
        $this->service()->send($f, null);

        $r = $this->service()->send($f->fresh(), null);

        $this->assertFalse($r['ok']);
        $this->assertSame(1, $this->stub->calls);
    }

    public function test_erledigte_weiterleitung_sendet_nicht(): void
    {
        $f = $this->forward($this->employee());
        $f->update(['done_at' => now()]);

        $this->assertFalse($this->service()->send($f->fresh(), null)['ok']);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_nicht_hr_ziel_sendet_nicht(): void
    {
        $f = $this->forward($this->employee());
        $f->update(['target' => 'lohn']);

        $r = $this->service()->send($f->fresh(), null);

        $this->assertFalse($r['ok']);
        $this->assertSame('Erstnachricht gibt es nur für HR-Weiterleitungen.', $r['error']);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_window_open_nur_bei_eingang_der_letzten_24h(): void
    {
        $f = $this->forward($this->employee());
        $now = new \DateTimeImmutable('2026-10-02 12:00:00');

        $this->assertNull($this->service()->windowOpen($f, $now));

        $id = $this->hrThread(self::PHONE, '2026-10-02 09:00:00');
        $this->assertSame($id, (int) $this->service()->windowOpen($f, $now)?->id);

        Capsule::table('comms_whatsapp_threads')->where('id', $id)->update(['last_inbound_at' => '2026-09-30 09:00:00']);
        $this->assertNull($this->service()->windowOpen($f, $now));
    }

    public function test_open_for_team_zeigt_keine_fremden(): void
    {
        $this->forward(null, self::TEAM + 1);

        $this->assertSame(0, RecConversationForward::query()->openForTeam(self::TEAM)->count());
    }
}
