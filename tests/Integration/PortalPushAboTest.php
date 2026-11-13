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
use Platform\Recruiting\Http\Controllers\PortalAppController;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPushAbo;
use Platform\Recruiting\Services\Push\PortalPush;
use Platform\Recruiting\Services\Push\PushAbos;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Portal als App und Push einschalten (Spec Jobs anbieten 6.6/6.7, Markus
 * 13.11.2026): Manifest je Token, Abo speichern/loeschen aus der Portal-Huelle.
 * Gespeichert wird nur mit gueltiger Sitzung; geloescht nur in der eigenen
 * Identitaets-Gruppe, die der Server selbst rechnet.
 */
class PortalPushAboTest extends TestCase
{
    private const TEAM = 602;

    /** Push-Attrappe: zaehlt die Proben, sendet nie. */
    private object $push;

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
        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('config');
        $c->forgetInstance(PortalPush::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_push_abos', 'rec_employees', 'crm_contact_links'] as $t) {
            Capsule::table($t)->delete();
        }
        $c = Container::getInstance();
        $c->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM], 'push' => ['vapid' => ['public' => 'BPub']]],
        ]));
        $this->push = new class implements PortalPush {
            /** @var list<int> */
            public array $proben = [];

            public function aktiv(): bool
            {
                return true;
            }

            public function neueAngebote(array $employeeIds): void
            {
            }

            public function probe(int $employeeId): void
            {
                $this->proben[] = $employeeId;
            }
        };
        $c->instance(PortalPush::class, $this->push);
    }

    protected function tearDown(): void
    {
        TestPortalSitzung::aufraeumen();
    }

    private function employee(string $pnr): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Anna', 'last_name' => 'Muster', 'personnel_number' => $pnr,
            'phone' => '+49 172 1', 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
            'portal_v2_since' => '2026-11-01 08:00:00',
        ]);
    }

    private function link(int $employeeId, int $contactId): void
    {
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'lnk-' . $employeeId . '-' . $contactId, 'contact_id' => $contactId, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => $employeeId, 'linkable_type' => (new RecEmployee())->getMorphClass(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function shell(RecEmployee $ma, bool $angemeldet = true): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = (int) $ma->id;
        $shell->token = (string) $ma->portal_token;
        if ($angemeldet) {
            $shell->state = 'verified';
            TestPortalSitzung::oeffne((int) $ma->id);
        }

        return $shell;
    }

    /** Genau die Form von PushSubscription.toJSON() im Browser. */
    private static function abo(string $geraet): array
    {
        return [
            'endpoint' => 'https://push.example.org/' . $geraet,
            'expirationTime' => null,
            'keys' => ['p256dh' => 'BPkey-' . $geraet, 'auth' => 'auth-' . $geraet],
        ];
    }

    private function aboAnlegen(int $employeeId, string $geraet): void
    {
        $this->assertTrue((new PushAbos())->speichern($employeeId, self::abo($geraet), null));
    }

    // --- Manifest ---------------------------------------------------------

    public function test_manifest_fuer_bekannten_token_startet_genau_dort(): void
    {
        $this->employee('M1');

        $antwort = (new PortalAppController())->manifest('tok-M1');
        $daten = json_decode((string) $antwort->getContent(), true);

        $this->assertSame(200, $antwort->getStatusCode());
        $this->assertStringStartsWith('application/manifest+json', (string) $antwort->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $antwort->headers->get('Cache-Control'));
        $this->assertSame('/recruiting/mitarbeiter/neu/tok-M1', $daten['start_url']);
        $this->assertSame('/recruiting/', $daten['scope']);
        $this->assertSame('Mein Rheingedeck', $daten['name']);
    }

    public function test_manifest_fuer_unbekannten_token_ist_404(): void
    {
        $this->employee('M2');

        $this->expectException(NotFoundHttpException::class);
        (new PortalAppController())->manifest('tok-gibt-es-nicht');
    }

    // --- Abo speichern ----------------------------------------------------

    public function test_ohne_anmeldung_wird_kein_abo_gespeichert(): void
    {
        $anna = $this->employee('S1');
        $shell = $this->shell($anna, angemeldet: false);

        $shell->pushAboSpeichern(self::abo('handy'));

        $this->assertSame(0, RecPushAbo::query()->count());
        $this->assertSame([], $this->push->proben);
    }

    public function test_verfaelschter_zustand_ohne_sitzung_speichert_nichts(): void
    {
        $anna = $this->employee('S2');
        $shell = $this->shell($anna, angemeldet: false);
        $shell->state = 'verified'; // Zustand gefaelscht, Sitzung fehlt
        TestPortalSitzung::oeffne(999999); // es gibt eine Sitzung — nur nicht fuer Anna

        $shell->pushAboSpeichern(self::abo('handy'));

        $this->assertSame(0, RecPushAbo::query()->count());
        $this->assertSame([], $this->push->proben);
    }

    public function test_angemeldet_entsteht_ein_abo_und_genau_eine_probe(): void
    {
        $anna = $this->employee('S3');

        $this->shell($anna)->pushAboSpeichern(self::abo('handy'));

        $abo = RecPushAbo::query()->sole();
        $this->assertSame((int) $anna->id, (int) $abo->rec_employee_id);
        $this->assertSame('https://push.example.org/handy', $abo->endpoint);
        $this->assertSame([(int) $anna->id], $this->push->proben);
    }

    public function test_unbrauchbares_abo_aus_dem_browser_speichert_nichts_und_probt_nicht(): void
    {
        $anna = $this->employee('S4');
        $shell = $this->shell($anna);

        foreach ([
            [],
            ['endpoint' => 'http://push.example.org/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']],
            ['endpoint' => 'https://push.example.org/x', 'keys' => 'kaputt'],
            ['endpoint' => ['https://push.example.org/x'], 'keys' => ['p256dh' => 'a', 'auth' => 'b']],
            ['endpoint' => 'https://push.example.org/x', 'keys' => ['p256dh' => ['a'], 'auth' => 'b']],
        ] as $roh) {
            $shell->pushAboSpeichern($roh);
        }

        $this->assertSame(0, RecPushAbo::query()->count());
        $this->assertSame([], $this->push->proben);
    }

    // --- Abo loeschen -----------------------------------------------------

    public function test_loeschen_entfernt_nur_das_eigene(): void
    {
        $anna = $this->employee('L1');
        $bert = $this->employee('L2');
        $this->aboAnlegen((int) $anna->id, 'anna-handy');
        $this->aboAnlegen((int) $bert->id, 'bert-handy');

        $shell = $this->shell($anna);
        $shell->pushAboLoeschen('https://push.example.org/bert-handy'); // fremdes Geraet
        $this->assertSame(2, RecPushAbo::query()->count(), 'fremdes Abo bleibt');

        $shell->pushAboLoeschen('https://push.example.org/anna-handy');
        $this->assertSame(['https://push.example.org/bert-handy'], RecPushAbo::query()->pluck('endpoint')->all());
    }

    public function test_loeschen_reicht_ueber_die_vom_server_gerechnete_gruppe(): void
    {
        $alt = $this->employee('G1');
        $neu = $this->employee('G2');
        $this->link((int) $alt->id, 77);
        $this->link((int) $neu->id, 77);
        $this->aboAnlegen((int) $alt->id, 'handy');

        $this->assertContains((int) $alt->id, app(DispoIdentityResolver::class)->groupFor((int) $neu->id));

        $this->shell($neu)->pushAboLoeschen('https://push.example.org/handy');

        $this->assertSame(0, RecPushAbo::query()->count(), 'Abo am Alt-Datensatz derselben Person geht mit aus');
    }

    public function test_loeschen_ohne_anmeldung_tut_nichts(): void
    {
        $anna = $this->employee('L3');
        $this->aboAnlegen((int) $anna->id, 'handy');

        $this->shell($anna, angemeldet: false)->pushAboLoeschen('https://push.example.org/handy');

        $this->assertSame(1, RecPushAbo::query()->count());
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContactLink::class);
        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php'],
            [$own, 'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php'],
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
