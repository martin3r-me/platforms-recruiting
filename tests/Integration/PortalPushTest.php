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
use Platform\Recruiting\Jobs\SendPortalPush;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPushAbo;
use Platform\Recruiting\Services\Push\PushAbos;
use Platform\Recruiting\Services\Push\PushTransport;
use Platform\Recruiting\Services\Push\WebPortalPush;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Push im Server (Spec Jobs anbieten 6.7, Markus 13.11.2026): Abos speichern,
 * Job sendet ueber eine Transport-Attrappe — die echte Bibliothek ist im
 * Test-vendor nicht vorhanden und wird hier nie geladen.
 */
class PortalPushTest extends TestCase
{
    private const TEAM = 601;

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
        self::config([]);
        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_push_abos', 'rec_employees', 'crm_contact_links'] as $t) {
            Capsule::table($t)->delete();
        }
        self::config([]);
    }

    private static function config(array $vapid): void
    {
        Container::getInstance()->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM], 'push' => ['vapid' => $vapid]],
        ]));
    }

    private function employee(string $pnr): int
    {
        return (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Anna', 'last_name' => 'Muster', 'personnel_number' => $pnr,
            'phone' => '+49 172 1', 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ])->id;
    }

    private function link(int $employeeId, int $contactId): void
    {
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'lnk-' . $employeeId . '-' . $contactId, 'contact_id' => $contactId, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => $employeeId, 'linkable_type' => (new RecEmployee())->getMorphClass(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function abo(int $employeeId, string $geraet): RecPushAbo
    {
        $endpoint = 'https://push.example.org/' . $geraet;

        return RecPushAbo::create(['rec_employee_id' => $employeeId, 'endpoint' => $endpoint, 'endpoint_hash' => RecPushAbo::hash($endpoint),
            'p256dh' => 'BPkey-' . $geraet, 'auth' => 'auth-' . $geraet]);
    }

    /** Transport-Attrappe: Antwort je Endpunkt, merkt sich jede Sendung, wirft auf Wunsch. */
    private function transport(string $antwort = 'ok', bool $wirft = false): PushTransport
    {
        return new class($antwort, $wirft) implements PushTransport {
            /** @var list<array{endpoint:string, payload:array}> */
            public array $sendungen = [];

            public function __construct(private string $antwort, private bool $wirft)
            {
            }

            public function senden(RecPushAbo $abo, array $payload): string
            {
                $this->sendungen[] = ['endpoint' => $abo->endpoint, 'payload' => $payload];
                if ($this->wirft) {
                    throw new \RuntimeException('Push-Dienst weg');
                }

                return $this->antwort;
            }
        };
    }

    private function laufen(SendPortalPush $job, PushTransport $transport): void
    {
        $job->handle($transport, new DispoIdentityResolver());
    }

    public function test_person_mit_zwei_abos_bekommt_zwei_sendungen_und_last_ok_at(): void
    {
        $anna = $this->employee('RG1');
        $this->abo($anna, 'handy');
        $this->abo($anna, 'tablet');
        $t = $this->transport('ok');

        $this->laufen(new SendPortalPush([$anna], 'angebote'), $t);

        $this->assertCount(2, $t->sendungen);
        $this->assertSame(2, RecPushAbo::query()->whereNotNull('last_ok_at')->count());
    }

    public function test_payload_text_exakt_und_ohne_einsatzdetails(): void
    {
        $anna = $this->employee('RG2');
        $this->abo($anna, 'handy');
        $t = $this->transport('ok');

        $this->laufen(new SendPortalPush([$anna], 'angebote'), $t);

        $this->assertSame([
            'title' => 'Neue Einsätze für dich',
            'body' => 'Schau ins Portal und sag zu.',
            'url' => '/recruiting/mitarbeiter/neu/tok-RG2?tab=jobs',
        ], $t->sendungen[0]['payload']);
    }

    public function test_probe_text_exakt(): void
    {
        $anna = $this->employee('RG3');
        $this->abo($anna, 'handy');
        $t = $this->transport('ok');

        $this->laufen(new SendPortalPush([$anna], 'probe'), $t);

        $this->assertSame([
            'title' => 'Benachrichtigungen sind eingeschaltet',
            'body' => 'So melden wir uns bei neuen Einsätzen.',
            'url' => '/recruiting/mitarbeiter/neu/tok-RG3?tab=jobs',
        ], $t->sendungen[0]['payload']);
    }

    public function test_erloschen_loescht_abo(): void
    {
        $anna = $this->employee('RG4');
        $this->abo($anna, 'handy');

        $this->laufen(new SendPortalPush([$anna], 'angebote'), $this->transport('erloschen'));

        $this->assertSame(0, RecPushAbo::query()->count());
    }

    public function test_fehler_laesst_abo_stehen_und_wirft_nicht(): void
    {
        $anna = $this->employee('RG5');
        $this->abo($anna, 'handy');

        $this->laufen(new SendPortalPush([$anna], 'angebote'), $this->transport('fehler'));

        $abo = RecPushAbo::query()->sole();
        $this->assertNull($abo->last_ok_at);
    }

    public function test_werfende_attrappe_beendet_job_ohne_ausnahme_und_versucht_alle(): void
    {
        $anna = $this->employee('RG6');
        $this->abo($anna, 'handy');
        $this->abo($anna, 'tablet');
        $t = $this->transport('ok', true);

        $this->laufen(new SendPortalPush([$anna], 'angebote'), $t);

        $this->assertCount(2, $t->sendungen);
        $this->assertSame(2, RecPushAbo::query()->count());
    }

    public function test_abo_am_alt_datensatz_derselben_gruppe_wird_erreicht_mit_dessen_token(): void
    {
        $rg = $this->employee('RG7');
        $ma = $this->employee('MA7');
        $this->link($rg, 900);
        $this->link($ma, 900);
        $this->abo($rg, 'altes-handy');
        $t = $this->transport('ok');

        $this->laufen(new SendPortalPush([$ma], 'angebote'), $t);

        $this->assertCount(1, $t->sendungen);
        $this->assertSame('/recruiting/mitarbeiter/neu/tok-RG7?tab=jobs', $t->sendungen[0]['payload']['url']);
    }

    public function test_doppelte_person_in_der_liste_sendet_je_abo_einmal(): void
    {
        $rg = $this->employee('RG8');
        $ma = $this->employee('MA8');
        $this->link($rg, 901);
        $this->link($ma, 901);
        $this->abo($rg, 'handy');
        $t = $this->transport('ok');

        $this->laufen(new SendPortalPush([$rg, $ma], 'angebote'), $t);

        $this->assertCount(1, $t->sendungen);
    }

    public function test_fremde_person_bekommt_nichts(): void
    {
        $anna = $this->employee('RG9');
        $ben = $this->employee('RG10');
        $this->abo($ben, 'handy');
        $t = $this->transport('ok');

        $this->laufen(new SendPortalPush([$anna], 'angebote'), $t);

        $this->assertSame([], $t->sendungen);
    }

    public function test_speichern_gleicher_endpunkt_zwei_personen_eine_zeile_zweite_person(): void
    {
        $anna = $this->employee('RG11');
        $ben = $this->employee('RG12');
        $abo = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'BPkey', 'auth' => 'authkey']];

        $this->assertTrue((new PushAbos())->speichern($anna, $abo, 'Firefox'));
        $this->assertTrue((new PushAbos())->speichern($ben, $abo, 'Safari'));

        $zeile = RecPushAbo::query()->sole();
        $this->assertSame($ben, $zeile->rec_employee_id);
        $this->assertSame(RecPushAbo::hash($abo['endpoint']), $zeile->endpoint_hash);
        $this->assertSame('Safari', $zeile->user_agent);
    }

    /** Gesamtpruefung Minor 4: hoechstens 10 Abos je Person (Identitaets-Gruppe); Wiederholung zaehlt nicht. */
    public function test_speichern_hoechstens_zehn_abos_je_gruppe(): void
    {
        $anna = $this->employee('RG40');
        $annaAlt = $this->employee('RG41');
        $ben = $this->employee('RG42');
        $abos = new PushAbos();
        $keys = ['p256dh' => 'BPkey', 'auth' => 'authkey'];
        $ep = fn (string $g) => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $g, 'keys' => $keys];

        for ($i = 1; $i <= 6; $i++) {
            $this->assertTrue($abos->speichern($anna, $ep('a' . $i), null, [$anna, $annaAlt]));
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->assertTrue($abos->speichern($annaAlt, $ep('alt' . $i), null, [$anna, $annaAlt]), 'Alt-Datensatz zaehlt zur Gruppe');
        }

        $this->assertFalse($abos->speichern($anna, $ep('elftes'), null, [$anna, $annaAlt]), 'elftes neues Abo abgelehnt');
        $this->assertTrue($abos->speichern($anna, $ep('a3'), 'neu', [$anna, $annaAlt]), 'vorhandenen Endpunkt erneut speichern zaehlt nicht');
        $this->assertTrue($abos->speichern($anna, $ep('alt2'), null, [$anna, $annaAlt]), 'Abo innerhalb der Gruppe umhaengen zaehlt nicht');
        $this->assertSame(10, RecPushAbo::query()->count());

        $this->assertTrue($abos->speichern($ben, $ep('ben1'), null, [$ben]), 'andere Person hat eigene Grenze');
        $this->assertTrue($abos->speichern($ben, $ep('a1'), null, [$ben]), 'Besitzerwechsel eines Geraets zu einer Person unter der Grenze geht');
    }

    public function test_speichern_lehnt_http_und_kaputte_abos_ab(): void
    {
        $anna = $this->employee('RG13');
        $abos = new PushAbos();
        $keys = ['p256dh' => 'BPkey', 'auth' => 'authkey'];

        $this->assertFalse($abos->speichern($anna, ['endpoint' => 'http://push.example.org/x', 'keys' => $keys], null));
        $this->assertFalse($abos->speichern($anna, ['endpoint' => 'https://push.example.org/' . str_repeat('x', 2100), 'keys' => $keys], null));
        $this->assertFalse($abos->speichern($anna, ['endpoint' => 'https://push.example.org/x', 'keys' => ['p256dh' => '', 'auth' => 'a']], null));
        $this->assertFalse($abos->speichern($anna, ['endpoint' => 'https://push.example.org/x', 'keys' => ['p256dh' => str_repeat('a', 300), 'auth' => 'a']], null));
        $this->assertFalse($abos->speichern($anna, ['endpoint' => 'https://push.example.org/x'], null));
        $this->assertSame(0, RecPushAbo::query()->count());
    }

    public function test_loeschen_nur_in_der_eigenen_gruppe(): void
    {
        $anna = $this->employee('RG14');
        $ben = $this->employee('RG15');
        $fremd = $this->abo($ben, 'bens-handy');
        $eigen = $this->abo($anna, 'annas-handy');
        $abos = new PushAbos();

        $abos->loeschen($anna, [$anna], $fremd->endpoint);
        $this->assertSame(2, RecPushAbo::query()->count(), 'Fremdes Abo bleibt.');

        $abos->loeschen($anna, [$anna], $eigen->endpoint);
        $this->assertSame([$fremd->id], RecPushAbo::query()->pluck('id')->map(fn ($v) => (int) $v)->all());
    }

    /** WebPortalPush mit Einreih-Naht: merkt sich Jobs statt sie abzuschicken; Bibliothek wahlweise „da“. */
    private function portalPush(bool $bibliothek): WebPortalPush
    {
        return new class($bibliothek) extends WebPortalPush {
            /** @var list<SendPortalPush> */
            public array $eingereiht = [];

            public function __construct(private bool $bibliothek)
            {
            }

            protected function bibliothekVorhanden(): bool
            {
                return $this->bibliothek;
            }

            protected function einreihen(SendPortalPush $job): void
            {
                $this->eingereiht[] = $job;
            }
        };
    }

    public function test_aktiv_false_ohne_schluessel_und_dann_kein_einreihen(): void
    {
        $push = $this->portalPush(true);

        $this->assertFalse($push->aktiv());
        $push->neueAngebote([1, 2]);
        $push->probe(1);

        $this->assertSame([], $push->eingereiht);
    }

    public function test_aktiv_false_ohne_bibliothek_auch_mit_schluesseln(): void
    {
        self::config(['public' => 'pub', 'private' => 'priv', 'subject' => 'mailto:hr@example.org']);

        $this->assertFalse($this->portalPush(false)->aktiv());
        // Die echte Klasse folgt dem, was installiert ist — seit dem Bump vom
        // 13.11.2026 liegt die Bibliothek im Test-vendor, der Test darf nicht
        // davon abhaengen, ob sie da ist.
        $this->assertSame(class_exists(\Minishlink\WebPush\WebPush::class), (new WebPortalPush())->aktiv());
    }

    public function test_aktiv_reiht_angebote_und_probe_ein_leere_liste_nicht(): void
    {
        self::config(['public' => 'pub', 'private' => 'priv', 'subject' => 'mailto:hr@example.org']);
        $push = $this->portalPush(true);

        $this->assertTrue($push->aktiv());
        $push->neueAngebote([]);
        $push->neueAngebote([5, 6]);
        $push->probe(7);

        $this->assertCount(2, $push->eingereiht);
        $this->assertSame([5, 6], $push->eingereiht[0]->employeeIds);
        $this->assertSame('angebote', $push->eingereiht[0]->art);
        $this->assertSame([7], $push->eingereiht[1]->employeeIds);
        $this->assertSame('probe', $push->eingereiht[1]->art);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContactLink::class);
        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_11_13_000002_create_rec_push_abos_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
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
        $dir = dirname((string) (new \ReflectionClass($class))->getFileName());
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
