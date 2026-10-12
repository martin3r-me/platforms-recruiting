<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Datenleck im alten Portal: mount() lud die Stammdaten (IBAN, Adresse, ...)
 * in die oeffentliche Eigenschaft $fieldValues, BEVOR geprueft war, ob der
 * Aufrufer angemeldet ist. Livewire schreibt jede oeffentliche Eigenschaft in
 * den wire:snapshot — wer nur den Token-Link kannte, bekam alles, ohne
 * Geburtsdatum und Ausweisziffern.
 *
 * Bewusst eigenstaendig (eigene Capsule, nur Migrationen, die auch auf main
 * liegen), damit der Fix samt Test auf main uebernommen werden kann.
 */
final class EmployeePortalVorAnmeldungTest extends TestCase
{
    private const TEAM = 612;
    private const IBAN = 'DE89370400440532013000';

    private Store $sitzung;

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
        foreach ([
            'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php',
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000001_add_full_field_set_to_rec_employees.php',
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
        ] as $relative) {
            (require $own . '/' . $relative)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
    }

    protected function setUp(): void
    {
        $c = Container::getInstance();
        $this->sitzung = new Store('test', new ArraySessionHandler(60));
        $c->instance('session', $this->sitzung);
        $c->instance('cache', new CacheRepository(new ArrayStore()));
        Facade::clearResolvedInstances();
        Capsule::table('rec_employees')->delete();
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('session');
        $c->forgetInstance('cache');
        Facade::clearResolvedInstances();
    }

    private function mitarbeiter(): RecEmployee
    {
        return RecEmployee::create([
            'team_id'      => self::TEAM,
            'first_name'   => 'Erika',
            'last_name'    => 'Muster',
            'is_active'    => true,
            'portal_token' => 'tok-vor-anmeldung',
            'iban'         => self::IBAN,
            'street'       => 'Geheimweg',
            'city'         => 'Duesseldorf',
        ]);
    }

    /** Alle oeffentlichen Eigenschaften — das, was Livewire in den Snapshot schreibt. */
    private function snapshot(EmployeePortal $portal): string
    {
        $werte = [];
        foreach ((new \ReflectionObject($portal))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic() || !$p->isInitialized($portal)) {
                continue;
            }
            $werte[$p->getName()] = $p->getValue($portal);
        }

        return (string) json_encode($werte);
    }

    public function test_nur_mit_dem_token_kommen_keine_stammdaten_in_den_snapshot(): void
    {
        $this->mitarbeiter();

        $portal = new EmployeePortal();
        $portal->mount('tok-vor-anmeldung');

        $this->assertSame('unverified', $portal->state);
        $this->assertSame([], $portal->fieldValues);
        $snapshot = $this->snapshot($portal);
        $this->assertStringNotContainsString(self::IBAN, $snapshot);
        $this->assertStringNotContainsString('Geheimweg', $snapshot);
        $this->assertStringNotContainsString('Muster', $snapshot, 'auch der Name erst nach der Anmeldung');
    }

    public function test_mit_gueltiger_sitzung_sind_die_stammdaten_da(): void
    {
        $ma = $this->mitarbeiter();
        $this->sitzung->put('employee_portal_verified:' . $ma->id, true);

        $portal = new EmployeePortal();
        $portal->mount('tok-vor-anmeldung');

        $this->assertSame('verified', $portal->state);
        $this->assertSame(self::IBAN, $portal->fieldValues['iban'] ?? null);
        $this->assertSame('Erika Muster', $portal->displayName);
    }

    public function test_abmelden_raeumt_die_stammdaten_wieder_ab(): void
    {
        $ma = $this->mitarbeiter();
        $this->sitzung->put('employee_portal_verified:' . $ma->id, true);
        $portal = new EmployeePortal();
        $portal->mount('tok-vor-anmeldung');

        $portal->logout();

        $this->assertSame('unverified', $portal->state);
        $this->assertSame([], $portal->fieldValues);
        $this->assertStringNotContainsString(self::IBAN, $this->snapshot($portal));
    }

    /**
     * Der #[Locked]-$state steht im Client-Snapshot und laeuft nie ab. Ist die
     * Sitzung weg (Logout im anderen Tab, abgelaufene Laravel-Sitzung), darf
     * der naechste Aufruf mit dem alten 'verified'-Snapshot keine Daten mehr
     * zeigen. hydrate() ist der Livewire-Hook, der bei jedem Folgeaufruf laeuft.
     */
    public function test_alter_verified_snapshot_ohne_sitzung_zeigt_beim_naechsten_aufruf_nichts(): void
    {
        $ma = $this->mitarbeiter();
        $this->sitzung->put('employee_portal_verified:' . $ma->id, true);
        $portal = new EmployeePortal();
        $portal->mount('tok-vor-anmeldung');
        $this->assertSame('verified', $portal->state, 'Vorbedingung');

        $this->sitzung->forget('employee_portal_verified:' . $ma->id);
        $portal->hydrate();

        $this->assertSame('unverified', $portal->state);
        $this->assertSame([], $portal->fieldValues);
        $this->assertSame('', $portal->displayName);
        $this->assertStringNotContainsString(self::IBAN, $this->snapshot($portal));
    }

    public function test_mit_lebender_sitzung_laesst_hydrate_alles_stehen(): void
    {
        $ma = $this->mitarbeiter();
        $this->sitzung->put('employee_portal_verified:' . $ma->id, true);
        $portal = new EmployeePortal();
        $portal->mount('tok-vor-anmeldung');

        $portal->hydrate();

        $this->assertSame('verified', $portal->state);
        $this->assertSame(self::IBAN, $portal->fieldValues['iban'] ?? null);
    }
}
