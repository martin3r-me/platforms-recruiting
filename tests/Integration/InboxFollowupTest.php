<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\User;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Livewire\Conversations\Inbox;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationFollowup;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\FollowupMarker;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;
use Platform\Recruiting\Services\Comms\InboxRow;

/**
 * „Offen lassen“ (Spec §3, Teil B): HR markiert einen Chat bewusst als noch
 * zu erledigen. Pille „Offen N“ zeigt markierte Chats unabhaengig von
 * gelesen/ungelesen; geloest wird der Marker NUR ueber „Erledigt“ — eine
 * Antwort von HR loest ihn nicht (Sebastian 16.10.).
 */
class InboxFollowupTest extends TestCase
{
    private const TEAM = 907;
    private const FREMD = 908;
    // Eigenes Team fuer Tests, die neue Threads anlegen — sonst wandern deren
    // Marker je nach Reihenfolge in die Zaehler von TEAM.
    private const SPIEL = 909;
    public const CLARA = 41;
    private const JETZT = 1_757_930_000;

    private static int $channel = 0;
    private static int $gelesenMarkiert = 0;   // gelesen, von Clara offen gelassen
    private static int $ungelesenMarkiert = 0; // ungelesen + offen gelassen
    private static int $maMarkiert = 0;        // Mitarbeiter-Chat (Filiale 200), gelesen, offen gelassen
    private static int $nurUngelesen = 0;      // ungelesen, kein Marker
    private static int $erledigt = 0;          // Marker bereits erledigt
    private static int $beantwortet = 0;       // offen gelassen, danach hat HR geantwortet

    private static array $previousMorphMap = [];

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting' => ['filialen' => [200 => ['code' => 'MGL', 'name' => 'Mönchengladbach']]],
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
        Relation::morphMap(['rec_applicant' => RecApplicant::class]);

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        $integrations = dirname((new \ReflectionClass(IntegrationsWhatsAppTemplate::class))->getFileName(), 3);
        foreach ([
            [$own, '2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, '2026_09_15_000002_create_rec_conversation_handled_table.php'],
            [$own, '2026_10_17_000001_create_rec_conversation_topics_table.php'],
            [$own, '2026_10_17_000002_create_rec_conversation_followups_table.php'],
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
            [$crm, '2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, '2024_01_01_000020_create_crm_contact_links_table.php'],
        ] as [$root, $file]) {
            (require $root . '/database/migrations/' . $file)->up();
        }
        $capsule->schema()->create('users', function ($t) {
            $t->id();
            $t->string('name')->nullable();
        });

        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Carbon::setTestNow();
        Relation::morphMap(self::$previousMorphMap, false);
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Container::getInstance()->forgetInstance('auth');
        Container::getInstance()->forgetInstance('session');
        Facade::clearResolvedInstance('auth');
        Facade::clearResolvedInstance('session');
    }

    /** @return array<int, InboxRow> threadId => Zeile */
    private function zeilen(InboxFilter $filter = new InboxFilter()): array
    {
        $out = [];
        foreach ((new InboxQuery())->snapshot(self::TEAM, $filter, 50, 0, self::JETZT)['rows'] as $row) {
            $out[$row->threadId] = $row;
        }

        return $out;
    }

    private function ids(InboxFilter $filter): array
    {
        $ids = array_keys($this->zeilen($filter));
        sort($ids);

        return $ids;
    }

    public function test_markieren_legt_eine_zeile_mit_benutzer_an_und_kein_zweites_mal(): void
    {
        $thread = self::thread('+4915100000901', true, team: self::SPIEL);
        $marker = new FollowupMarker();

        $marker->markieren(self::SPIEL, $thread, self::CLARA);
        $marker->markieren(self::SPIEL, $thread, self::CLARA);

        $zeilen = RecConversationFollowup::query()->where('comms_whatsapp_thread_id', $thread)->get();
        $this->assertCount(1, $zeilen, 'Solange der Marker offen ist, gibt es keine zweite Zeile.');
        $this->assertSame(self::CLARA, (int) $zeilen[0]->marked_by_user_id);
        $this->assertSame(self::SPIEL, (int) $zeilen[0]->team_id);
        $this->assertNull($zeilen[0]->cleared_at);
    }

    public function test_offene_fuer_liefert_name_und_zeit(): void
    {
        $offen = (new FollowupMarker())->offeneFuer(self::TEAM, [self::$gelesenMarkiert, self::$nurUngelesen, self::$erledigt]);

        $this->assertSame([self::$gelesenMarkiert], array_keys($offen));
        $this->assertSame('Clara Beispiel', $offen[self::$gelesenMarkiert]['by']);
        $this->assertSame(self::JETZT - 1_800, $offen[self::$gelesenMarkiert]['at']);
    }

    public function test_erledigen_setzt_cleared_und_der_marker_ist_weg(): void
    {
        $thread = self::thread('+4915100000902', false, team: self::SPIEL);
        $marker = new FollowupMarker();
        $marker->markieren(self::SPIEL, $thread, self::CLARA);

        $this->assertTrue($marker->erledigen(self::SPIEL, $thread, 42));

        $zeile = RecConversationFollowup::query()->where('comms_whatsapp_thread_id', $thread)->firstOrFail();
        $this->assertNotNull($zeile->cleared_at);
        $this->assertSame(42, (int) $zeile->cleared_by_user_id);
        $this->assertSame([], $marker->offeneFuer(self::SPIEL, [$thread]));
        $this->assertFalse($marker->erledigen(self::SPIEL, $thread, 42), 'Zweimal erledigen aendert nichts.');

        // Danach darf wieder neu markiert werden — als neue Zeile.
        $marker->markieren(self::SPIEL, $thread, self::CLARA);
        $this->assertCount(2, RecConversationFollowup::query()->where('comms_whatsapp_thread_id', $thread)->get());
        $this->assertArrayHasKey($thread, $marker->offeneFuer(self::SPIEL, [$thread]));
    }

    public function test_fremdes_team_kann_nicht_erledigen_und_nicht_markieren(): void
    {
        $marker = new FollowupMarker();

        $this->assertFalse($marker->erledigen(self::FREMD, self::$gelesenMarkiert, 99));
        $this->assertArrayHasKey(self::$gelesenMarkiert, $marker->offeneFuer(self::TEAM, [self::$gelesenMarkiert]));

        $marker->markieren(self::FREMD, self::$nurUngelesen, 99);
        $this->assertSame(0, RecConversationFollowup::query()->where('comms_whatsapp_thread_id', self::$nurUngelesen)->count(),
            'Ein Thread eines anderen Teams wird nicht markiert.');
    }

    public function test_menschliche_antwort_loest_den_marker_nicht(): void
    {
        $offen = (new FollowupMarker())->offeneFuer(self::TEAM, [self::$beantwortet]);
        $this->assertArrayHasKey(self::$beantwortet, $offen, 'Nur „Erledigt“ loest den Marker, keine Antwort.');

        $this->assertContains(self::$beantwortet, $this->ids(new InboxFilter(level: 'followup')));
        $this->assertNotNull($this->zeilen()[self::$beantwortet]->followup);
    }

    public function test_filter_offen_zeigt_markierte_auch_gelesene(): void
    {
        $erwartet = [self::$gelesenMarkiert, self::$ungelesenMarkiert, self::$maMarkiert, self::$beantwortet];
        sort($erwartet);

        $this->assertSame($erwartet, $this->ids(new InboxFilter(level: 'followup')));
    }

    public function test_filter_offen_wirkt_mit_art_und_filiale_zusammen(): void
    {
        $this->assertSame([self::$maMarkiert], $this->ids(new InboxFilter(level: 'followup', kind: 'employees')));
        $this->assertSame([self::$maMarkiert], $this->ids(new InboxFilter(level: 'followup', filiale: '200')));
        $this->assertSame([], $this->ids(new InboxFilter(level: 'followup', topic: 'lohn')));
    }

    public function test_zaehler_offen_unabhaengig_von_gelesen(): void
    {
        $counts = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT)['counts'];
        $this->assertSame(4, $counts['followup']);

        $mgl = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(filiale: '200'), 50, 0, self::JETZT)['counts'];
        $this->assertSame(1, $mgl['followup'], 'Die Filiale schneidet auch diesen Zaehler.');
    }

    public function test_zeile_und_kopfzeile_tragen_den_marker(): void
    {
        $zeilen = $this->zeilen();
        $this->assertSame(['by' => 'Clara Beispiel', 'at' => self::JETZT - 1_800], $zeilen[self::$gelesenMarkiert]->followup);
        $this->assertNull($zeilen[self::$nurUngelesen]->followup);
        $this->assertNull($zeilen[self::$erledigt]->followup);

        $kopf = (new InboxQuery())->rowForThread(CommsWhatsAppThread::findOrFail(self::$gelesenMarkiert), self::TEAM, self::JETZT);
        $this->assertSame('Clara Beispiel', $kopf->followup['by']);
    }

    public function test_ohne_tabelle_laeuft_die_liste_weiter_ohne_marker(): void
    {
        $schema = Capsule::schema();
        $gesichert = Capsule::table('rec_conversation_followups')->get()->map(fn ($r) => (array) $r)->all();
        $schema->drop('rec_conversation_followups');
        try {
            $counts = (new InboxQuery())->counts(self::TEAM, self::JETZT);
            $snap = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(level: 'followup'), 50, 0, self::JETZT);
            $alle = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        } finally {
            (require dirname(__DIR__, 2) . '/database/migrations/2026_10_17_000002_create_rec_conversation_followups_table.php')->up();
            Capsule::table('rec_conversation_followups')->insert($gesichert);
        }

        $this->assertSame(0, $counts['followup']);
        $this->assertSame([], $snap['rows']);
        $this->assertNotEmpty($alle['rows']);
    }

    public function test_inbox_offen_lassen_und_erledigt_im_team_kontext(): void
    {
        $thread = self::thread('+4915100000903', false, team: self::SPIEL);
        self::bindFakeAuthAndSession(self::SPIEL);

        $inbox = new Inbox();
        $inbox->selectedThreadId = $thread;
        $inbox->offenLassen();

        $offen = (new FollowupMarker())->offeneFuer(self::SPIEL, [$thread]);
        $this->assertArrayHasKey($thread, $offen);
        $this->assertSame(self::CLARA, (int) RecConversationFollowup::query()->where('comms_whatsapp_thread_id', $thread)->value('marked_by_user_id'));
        $this->assertSame($thread, $inbox->selectedThreadId, 'Offen lassen schliesst den Chat nicht.');

        $inbox->followupErledigen();
        $this->assertSame([], (new FollowupMarker())->offeneFuer(self::SPIEL, [$thread]));
        $this->assertSame(self::CLARA, (int) RecConversationFollowup::query()->where('comms_whatsapp_thread_id', $thread)->value('cleared_by_user_id'));
        $this->assertFalse((bool) CommsWhatsAppThread::query()->whereKey($thread)->value('is_unread'),
            'Erledigt fasst den Lesestatus nicht an.');
    }

    public function test_inbox_aus_fremdem_team_aendert_nichts(): void
    {
        self::bindFakeAuthAndSession(self::FREMD);

        $inbox = new Inbox();
        $inbox->selectedThreadId = self::$gelesenMarkiert;
        $inbox->followupErledigen();
        $inbox->selectedThreadId = self::$nurUngelesen;
        $inbox->offenLassen();

        $offen = (new FollowupMarker())->offeneFuer(self::TEAM, [self::$gelesenMarkiert, self::$nurUngelesen]);
        $this->assertSame([self::$gelesenMarkiert], array_keys($offen));
    }

    public function test_antwort_ueber_den_echten_sendeweg_loest_den_marker_nicht(): void
    {
        // Review Task 6, I1: nicht nur der Lesepfad — auch der Sendeweg der
        // Inbox darf den Marker nicht anfassen. Nur „Erledigt“ loest ihn.
        $thread = (int) CommsWhatsAppThread::create([
            'team_id' => self::SPIEL, 'comms_channel_id' => self::$channel, 'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => '+4915100000904', 'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', time() - 600),
        ])->id;
        (new FollowupMarker())->markieren(self::SPIEL, $thread, self::CLARA);

        $gesendet = new \ArrayObject();
        $meta = new class($gesendet) extends WhatsAppMetaService {
            public function __construct(private \ArrayObject $calls) {}

            public function sendText(CommsChannel $channel, string $to, string $message, ?User $sender = null): CommsWhatsAppMessage
            {
                $this->calls[] = $message;
                $m = new CommsWhatsAppMessage();
                $m->status = 'sent';
                $m->meta_payload = [];

                return $m;
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $meta);
        self::bindFakeAuthAndSession(self::SPIEL);
        try {
            // #[Computed] selectedThread loest Livewire nur im Request-Lebenszyklus
            // auf; ausserhalb davon reicht die Unterklasse ihn an die Methode durch.
            $inbox = new class extends Inbox {
                public function __get($property)
                {
                    return $property === 'selectedThread' ? $this->selectedThread() : parent::__get($property);
                }
            };
            $inbox->selectedThreadId = $thread;
            $inbox->replyText = 'Kümmere mich morgen darum.';
            $inbox->sendReply();
        } finally {
            Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        }

        $this->assertNull($inbox->sendError);
        $this->assertSame(['Kümmere mich morgen darum.'], $gesendet->getArrayCopy(), 'Die Antwort ging wirklich raus.');
        $this->assertArrayHasKey($thread, (new FollowupMarker())->offeneFuer(self::SPIEL, [$thread]),
            'Eine Antwort aus dem Chat loest den Marker nicht.');
        $this->assertSame(1, RecConversationFollowup::query()->where('comms_whatsapp_thread_id', $thread)->whereNull('cleared_at')->count());
    }

    public function test_sidebar_zaehler_fragt_die_marker_nicht_ab(): void
    {
        // Review Task 6, M2: die Navigation links (30s-Poll auf jeder Seite)
        // zeigt „Offen“ nicht — also auch keine Marker-Abfrage.
        $n = 0;
        Capsule::connection()->listen(function ($q) use (&$n) {
            if (str_contains($q->sql, 'rec_conversation_followups')) {
                $n++;
            }
        });
        (new InboxQuery())->counts(self::TEAM, self::JETZT);
        Capsule::connection()->getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        $this->assertSame(0, $n);
    }

    public function test_marker_abfrage_einmal_je_durchlauf_und_bei_wenigen_threads_eingeschraenkt(): void
    {
        $sql = [];
        Capsule::connection()->listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'rec_conversation_followups')) {
                $sql[] = $q->sql;
            }
        });
        (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        (new InboxQuery())->rowForThread(CommsWhatsAppThread::findOrFail(self::$gelesenMarkiert), self::TEAM, self::JETZT);
        Capsule::connection()->getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        $this->assertCount(2, $sql, 'Je Durchlauf genau eine Marker-Abfrage.');
        foreach ($sql as $q) {
            $this->assertStringContainsString('"comms_whatsapp_thread_id" in', $q, 'Kleine Thread-Mengen gehen per IN an die DB.');
        }
    }

    public function test_grosse_thread_menge_liest_ohne_in_liste_und_schneidet_trotzdem(): void
    {
        $ids = array_merge([self::$gelesenMarkiert], range(100_000, 100_500));
        $sql = [];
        Capsule::connection()->listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'rec_conversation_followups')) {
                $sql[] = $q->sql;
            }
        });
        $offen = (new FollowupMarker())->offeneFuer(self::TEAM, $ids);
        Capsule::connection()->getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        $this->assertSame([self::$gelesenMarkiert], array_keys($offen), 'Nicht gesuchte markierte Threads fallen in PHP heraus.');
        $this->assertCount(1, $sql);
        $this->assertStringNotContainsString('"comms_whatsapp_thread_id" in', $sql[0]);
    }

    private static function thread(string $nr, bool $unread, ?string $model = null, ?int $id = null, int $team = self::TEAM): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => $team, 'comms_channel_id' => self::$channel, 'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $nr, 'context_model' => $model, 'context_model_id' => $id,
            'is_unread' => $unread, 'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 3_600),
        ])->id;
    }

    private static function bindFakeAuthAndSession(int $teamId): void
    {
        // Echter User (nicht nur ein Objekt): der Sendeweg reicht ihn als
        // ?User an WhatsAppMetaService::sendText() weiter.
        $user = new class extends User {
            public $currentTeam;
        };
        $user->id = self::CLARA;
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
            public function flash(string $key, $value): void {}
        };

        Container::getInstance()->instance('auth', $authStub);
        Container::getInstance()->instance('session', $sessionStub);
    }

    private static function seed(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-followup', 'phone_number' => '+49 160 5559701',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        self::$channel = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559701', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create(['team_id' => self::TEAM, 'settings' => ['auto_pilot_wa_account_id' => $accountId]]);
        Capsule::table('users')->insert(['id' => self::CLARA, 'name' => 'Clara Beispiel']);

        Capsule::table('rec_employees')->insert([
            'id' => 821, 'uuid' => 'ma-f821', 'team_id' => self::TEAM, 'is_active' => true, 'cost_center' => '201',
        ]);

        self::$gelesenMarkiert = self::thread('+4915100000801', false);
        self::$ungelesenMarkiert = self::thread('+4915100000802', true);
        self::$maMarkiert = self::thread('+4915100000803', false, RecEmployee::class, 821);
        self::$nurUngelesen = self::thread('+4915100000804', true);
        self::$erledigt = self::thread('+4915100000805', false);
        self::$beantwortet = self::thread('+4915100000806', false);

        $marker = new FollowupMarker();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::JETZT - 1_800));
        foreach ([self::$gelesenMarkiert, self::$ungelesenMarkiert, self::$maMarkiert, self::$erledigt, self::$beantwortet] as $id) {
            $marker->markieren(self::TEAM, $id, self::CLARA);
        }
        Carbon::setTestNow(Carbon::createFromTimestamp(self::JETZT - 1_200));
        $marker->erledigen(self::TEAM, self::$erledigt, self::CLARA);
        Carbon::setTestNow();

        // HR antwortet NACH dem Markieren — menschlich, keine Auto-Quittung.
        CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => self::$beantwortet,
            'direction' => 'outbound',
            'is_auto_reply' => false,
            'body' => 'Kümmere mich morgen darum.',
            'message_type' => 'text',
            'sent_at' => date('Y-m-d H:i:s', self::JETZT - 600),
            'created_at' => date('Y-m-d H:i:s', self::JETZT - 600),
        ]);
    }
}
