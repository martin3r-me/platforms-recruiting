<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Livewire\Dispo\Conversations\Index;
use Platform\Recruiting\Models\RecConversationForward;

/**
 * Weiterleitung HR -> Dispo, Seite Dispo-Postfach (Spec 16.10.2026): Oeffnen
 * erledigt die Weiterleitung, „ungelesen schliessen“ holt genau diese zurueck —
 * dieselbe Regel wie im HR-Postfach. Eine Weiterleitung AN HR auf demselben
 * Thread-Wert darf das Dispo-Postfach nie anfassen.
 */
class DispoInboxForwardFromHrTest extends TestCase
{
    private const TEAM = 896;

    private static int $channelId = 0;

    private static int $andereKanalId = 0;

    private static int $dritteKanalId = 0;

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

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        foreach ([
            $crm . '/database/migrations/2026_01_14_000003_create_comms_channels_table.php',
            $crm . '/database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php',
            $own . '/database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php',
            $own . '/database/migrations/2026_10_16_100001_add_target_channel_id_to_rec_conversation_forwards.php',
            $own . '/database/migrations/2026_08_24_000001_create_rec_dispo_filiale_settings_table.php',
        ] as $path) {
            (require $path)->up();
        }

        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => 'RheinGedeck Disposition CGN', 'sender_identifier' => '+49 211 78177729', 'is_active' => true,
        ]);
        // Zweite und dritte Niederlassung fuer Dispo -> Dispo (18.11.2026). Bewusst
        // ohne Filial-Zuordnung: die Beschriftung faellt auf den Kanalnamen zurueck.
        self::$andereKanalId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => 'Düsseldorf', 'sender_identifier' => '+49 211 78177725', 'is_active' => true,
        ]);
        self::$dritteKanalId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => 'Hamburg', 'sender_identifier' => '+49 40 78177725', 'is_active' => true,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('auth');
        Facade::clearResolvedInstances();
    }

    public function test_oeffnen_erledigt_die_hr_weiterleitung_und_ungelesen_schliessen_holt_sie_zurueck(): void
    {
        $threadId = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => self::$channelId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+4917663854907',
            'is_unread' => true,
        ])->id;
        $vonHr = $this->forward(['source' => 'hr', 'target' => 'dispo', 'target_channel_id' => self::$channelId, 'target_thread_id' => $threadId]);
        $vorherErledigt = $this->forward([
            'source' => 'hr', 'target' => 'dispo', 'target_channel_id' => self::$channelId, 'target_thread_id' => $threadId,
            'done_at' => date('Y-m-d H:i:s', time() - 800), 'done_by_user_id' => 1,
        ]);
        // Gleicher Thread-Wert, aber Ziel HR — gehoert nicht der Dispo.
        $anHr = $this->forward(['target_thread_id' => $threadId]);

        self::bindFakeAuth();
        $inbox = new DispoInboxMitFestemKanal();

        $inbox->select($threadId);

        $this->assertFalse((bool) CommsWhatsAppThread::query()->whereKey($threadId)->value('is_unread'));
        $this->assertNotNull($vonHr->fresh()->done_at, 'Oeffnen muss die Weiterleitung von HR erledigen.');
        $this->assertNull($anHr->fresh()->done_at, 'Eine Weiterleitung AN HR darf das Dispo-Postfach nicht erledigen.');
        $this->assertSame([(int) $vonHr->id], $inbox->openedForwardIds);

        // Die Karte muss NACH dem Oeffnen noch da sein — Oeffnen erledigt sie,
        // ohne erledigte Karten saehe die Dispo den Inhalt nie (Review 16.10., K1).
        $karten = $inbox->kartenVonHr($threadId);
        $this->assertCount(2, $karten, 'Beide Weiterleitungen von HR, auch die erledigte — nie die an HR.');
        $this->assertSame([true, true], array_map(fn ($k) => $k['card']['done'], $karten));
        $this->assertSame(['Von HR', 'Von HR'], array_map(fn ($k) => $k['card']['from'], $karten));

        $inbox->select($threadId);
        $this->assertSame([(int) $vonHr->id], $inbox->openedForwardIds, 'Erneutes Oeffnen verliert die gemerkte ID nicht.');

        $inbox->markUnreadAndClose();

        $this->assertTrue((bool) CommsWhatsAppThread::query()->whereKey($threadId)->value('is_unread'));
        $this->assertNull($vonHr->fresh()->done_at, '„ungelesen schliessen“ muss die Weiterleitung zurueckholen.');
        $this->assertNotNull($vorherErledigt->fresh()->done_at);
        $this->assertSame([], $inbox->openedForwardIds);
    }

    public function test_karte_und_chip_nennen_die_absendende_niederlassung(): void
    {
        $quelle = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => self::$andereKanalId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+4915112345678',
        ])->id;
        $ziel = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => self::$channelId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+4915112345678', 'is_unread' => true,
        ])->id;
        $this->forward([
            'source' => 'dispo', 'target' => 'dispo', 'source_thread_id' => $quelle,
            'target_channel_id' => self::$channelId, 'target_thread_id' => $ziel,
        ]);
        $this->forward([
            'source' => 'hr', 'target' => 'dispo', 'source_thread_id' => 999,
            'target_channel_id' => self::$channelId, 'target_thread_id' => $ziel,
            'forwarded_at' => date('Y-m-d H:i:s', time() - 300),
        ]);
        // Quell-Thread gibt es nicht mehr: nie „Von HR“, sondern schlicht „Von Dispo“.
        $this->forward([
            'source' => 'dispo', 'target' => 'dispo', 'source_thread_id' => 424242,
            'target_channel_id' => self::$channelId, 'target_thread_id' => $ziel,
            'forwarded_at' => date('Y-m-d H:i:s', time() - 100),
        ]);

        self::bindFakeAuth();
        $karten = (new DispoInboxMitFestemKanal())->kartenVonHr($ziel);

        $this->assertSame(
            ['Von Dispo Düsseldorf', 'Von HR', 'Von Dispo'],
            array_map(fn ($k) => $k['card']['from'], $karten),
        );
    }

    public function test_ziele_im_fenster_sind_hr_und_jede_andere_niederlassung_nie_die_eigene(): void
    {
        $eigener = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => self::$channelId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+4915100000077',
        ])->id;

        self::bindFakeAuth();
        $inbox = new DispoInboxMitFestemKanal();

        $this->assertSame([
            ['value' => 'hr', 'label' => 'HR'],
            ['value' => (string) self::$andereKanalId, 'label' => 'Dispo Düsseldorf'],
            ['value' => (string) self::$dritteKanalId, 'label' => 'Dispo Hamburg'],
        ], $inbox->zieleFuer($eigener));

        // Von der anderen Niederlassung aus gesehen: dieselbe Liste, nur mit getauschtem Platz.
        $drueben = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => self::$andereKanalId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+4915100000077',
        ])->id;
        $this->assertSame(
            ['hr', (string) self::$channelId, (string) self::$dritteKanalId],
            array_column($inbox->zieleFuer($drueben), 'value'),
        );
    }

    private function chat(int $kanal, string $nr): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => $kanal,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => $nr, 'is_unread' => true,
        ])->id;
    }

    public function test_wert_aus_dem_browser_wird_nur_als_angebotenes_ziel_angenommen(): void
    {
        $eigener = $this->chat(self::$channelId, '+4915100000101');
        self::bindFakeAuth();
        $inbox = new DispoInboxMitFestemKanal();

        $this->assertSame(['target' => 'hr', 'channel_id' => null], $inbox->zielFuer('hr', $eigener));
        $this->assertSame(
            ['target' => 'dispo', 'channel_id' => self::$andereKanalId],
            $inbox->zielFuer((string) self::$andereKanalId, $eigener),
        );
        foreach ([(string) self::$channelId, '0' . self::$andereKanalId, self::$andereKanalId . 'abc', '999999', '', 'dispo'] as $wert) {
            $this->assertNull($inbox->zielFuer($wert, $eigener), 'Nicht angeboten: "' . $wert . '"');
        }
    }

    public function test_chips_in_der_liste_nennen_ziel_und_herkunft(): void
    {
        $quelle = $this->chat(self::$andereKanalId, '+4915100000201');
        $ziel = $this->chat(self::$channelId, '+4915100000201');
        $nurHr = $this->chat(self::$channelId, '+4915100000202');
        $ohne = $this->chat(self::$channelId, '+4915100000203');
        $offen = $this->forward([
            'source' => 'dispo', 'target' => 'dispo', 'source_thread_id' => $quelle,
            'target_channel_id' => self::$channelId, 'target_thread_id' => $ziel,
        ]);
        $this->forward(['source' => 'dispo', 'target' => 'hr', 'source_thread_id' => $nurHr]);
        // Der Ziel-Chat hat selbst frueher etwas an HR gegeben: solange die
        // eingehende Weiterleitung offen ist, hat sie Vorrang.
        $this->forward([
            'source' => 'dispo', 'target' => 'hr', 'source_thread_id' => $ziel,
            'forwarded_at' => date('Y-m-d H:i:s', time() - 5000),
        ]);

        self::bindFakeAuth();
        $inbox = new DispoInboxMitFestemKanal();
        $chips = $inbox->chipsFuer([$quelle, $ziel, $nurHr, $ohne]);

        // „Dispo RheinGedeck Disposition CGN“: Kanal ohne Filial-Zuordnung traegt seinen Namen.
        $this->assertSame(['state' => 'open', 'label' => 'bei Dispo RheinGedeck Disposition CGN'], $chips[$quelle]);
        $this->assertSame(['state' => 'open', 'label' => 'von Dispo Düsseldorf'], $chips[$ziel]);
        $this->assertSame(['state' => 'open', 'label' => 'bei HR'], $chips[$nurHr]);
        $this->assertArrayNotHasKey($ohne, $chips);

        $offen->update(['done_at' => date('Y-m-d H:i:s'), 'done_by_user_id' => 2]);
        $chips = $inbox->chipsFuer([$quelle, $ziel]);
        $this->assertSame(['state' => 'done', 'label' => 'Dispo RheinGedeck Disposition CGN erledigt'], $chips[$quelle]);
        $this->assertSame(['state' => 'open', 'label' => 'bei HR'], $chips[$ziel], 'Eingehende erledigt: der eigene Chip gilt wieder.');
    }

    public function test_der_absender_erledigt_seine_eigene_weiterleitung_nicht_ein_anderer_schon(): void
    {
        $quelle = $this->chat(self::$andereKanalId, '+4915100000301');
        $ziel = $this->chat(self::$channelId, '+4915100000301');
        $f = $this->forward([
            'source' => 'dispo', 'target' => 'dispo', 'source_thread_id' => $quelle,
            'target_channel_id' => self::$channelId, 'target_thread_id' => $ziel,
            'forwarded_by_user_id' => 1,
        ]);

        // Nutzer 1 hat weitergeleitet und schaut in den Ziel-Chat.
        self::bindFakeAuth(1);
        $absender = new DispoInboxMitFestemKanal();
        $absender->select($ziel);

        $this->assertNull($f->fresh()->done_at, 'Der Absender darf seine eigene Weiterleitung nicht erledigen.');
        $this->assertTrue((bool) CommsWhatsAppThread::query()->whereKey($ziel)->value('is_unread'), 'Fuer die Empfaenger bleibt der Chat ungelesen.');
        $this->assertSame([], $absender->openedForwardIds);

        // Nutzer 2 (Empfaenger) oeffnet ihn: erledigt und gelesen.
        self::bindFakeAuth(2);
        $empfaenger = new DispoInboxMitFestemKanal();
        $empfaenger->select($ziel);

        $this->assertNotNull($f->fresh()->done_at);
        $this->assertSame(2, (int) $f->fresh()->done_by_user_id);
        $this->assertFalse((bool) CommsWhatsAppThread::query()->whereKey($ziel)->value('is_unread'));

        // „ungelesen schliessen“ holt sie zurueck — wie bei Weiterleitungen von HR.
        $empfaenger->markUnreadAndClose();
        $this->assertNull($f->fresh()->done_at);
    }

    private function forward(array $attrs): RecConversationForward
    {
        return RecConversationForward::create(array_merge([
            'team_id' => self::TEAM, 'source_thread_id' => 1, 'phone' => '+4917663854907',
            'display_name' => 'Jonas Stein', 'messages' => [], 'forwarded_at' => date('Y-m-d H:i:s', time() - 600),
        ], $attrs));
    }

    private static function bindFakeAuth(int $userId = 1): void
    {
        $user = new class {
            public int $id = 1;
            public $currentTeam;
        };
        $user->id = $userId;
        $user->currentTeam = (object) ['id' => self::TEAM];
        Facade::clearResolvedInstance('auth');

        Container::getInstance()->instance('auth', new class($user) {
            public function __construct(private object $user) {}
            public function user(): object
            {
                return $this->user;
            }
            public function id(): int
            {
                return (int) $this->user->id;
            }
        });
    }

    public static function kanal(): int
    {
        return self::$channelId;
    }

    /** @return list<int> alle Dispo-Kanaele des Tests */
    public static function alleKanaele(): array
    {
        return [self::$channelId, self::$andereKanalId, self::$dritteKanalId];
    }
}

/** Kanal-Set fest statt ueber DispoChannelResolver (braucht Vorlagen-Einstellung + Konto). */
class DispoInboxMitFestemKanal extends Index
{
    public function channelIds(): array
    {
        return [DispoInboxForwardFromHrTest::kanal()];
    }

    protected function dispoZiele(): \Platform\Recruiting\Services\Comms\Forward\DispoForwardChannels
    {
        return new \Platform\Recruiting\Services\Comms\Forward\DispoForwardChannels(DispoInboxForwardFromHrTest::alleKanaele());
    }

    /** Nur fuer den Test: Chips der Liste. */
    public function chipsFuer(array $threadIds): array
    {
        return $this->forwardChips($threadIds, 896);
    }

    /** Nur fuer den Test: Wert aus dem Browser -> Ziel. */
    public function zielFuer(string $wert, int $threadId): ?array
    {
        return $this->forwardZielAufloesen($wert, CommsWhatsAppThread::findOrFail($threadId));
    }

    /** Nur fuer den Test: Ziel-Liste des Fensters fuer einen bestimmten Chat. */
    public function zieleFuer(int $threadId): array
    {
        return $this->forwardZieleFuer(CommsWhatsAppThread::findOrFail($threadId));
    }

    /** Nur fuer den Test — die Methode selbst bleibt fuer den Browser unerreichbar (protected). */
    public function kartenVonHr(int $threadId): array
    {
        return $this->forwardCardsFromHr(CommsWhatsAppThread::findOrFail($threadId));
    }
}
