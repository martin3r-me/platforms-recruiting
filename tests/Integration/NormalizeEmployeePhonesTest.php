<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\NormalizeEmployeePhonesCommand;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;

/**
 * Bestands-Fix + Sendeweg-Normalisierung (Befund 01.09.): der Befehl bringt
 * rec_employees.phone nach E.164 (Festnetz/Unparsebares nur gelistet), das
 * Gateway liefert Sendewegen immer E.164 — auch fuer Rohbestand. Ende zu Ende
 * gegen echte Migrationen, kein Testbench (Muster DispoIdentityResolverTest).
 */
class NormalizeEmployeePhonesTest extends TestCase
{
    private const TEAM = 501;

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

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));
        // Log-Attrappe: der Befehl loggt die Zusammenfassung des Scharf-Laufs.
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });
        Facade::clearResolvedInstance('log');

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
    }

    private function employee(string $pnr, ?string $phone, bool $active = true): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'T', 'last_name' => $pnr,
            'personnel_number' => $pnr, 'phone' => $phone,
            'portal_token' => 'tok-' . $pnr, 'is_active' => $active,
        ]);
    }

    public function test_dry_run_changes_nothing_and_counts_correctly(): void
    {
        $this->employee('RG1', '017624533557');
        $this->employee('RG2', '+4915738762915');
        $this->employee('RG3', '02161823900');
        $this->employee('RG4', 'kaputt');

        $counts = (new NormalizeEmployeePhonesCommand())->normalize(true, null, fn ($t, $x) => null);

        $this->assertSame(['total' => 4, 'fixed' => 1, 'ok' => 1, 'fixed_line' => 1, 'unparseable' => 1], $counts);
        $this->assertSame('017624533557', RecEmployee::where('personnel_number', 'RG1')->value('phone'), 'Dry-Run schreibt nichts.');
    }

    public function test_live_run_fixes_only_the_fixable_and_is_idempotent(): void
    {
        $this->employee('RG1', '017624533557');
        $this->employee('RG2', '17661258620');
        $this->employee('RG3', '02161823900');
        $this->employee('RG4', 'kaputt');
        $this->employee('RG5', '0176 99999999', false); // inaktiv -> unberuehrt

        $cmd = new NormalizeEmployeePhonesCommand();
        $counts = $cmd->normalize(false, null, fn ($t, $x) => null);

        $this->assertSame(2, $counts['fixed']);
        $this->assertSame('+4917624533557', RecEmployee::where('personnel_number', 'RG1')->value('phone'));
        $this->assertSame('+4917661258620', RecEmployee::where('personnel_number', 'RG2')->value('phone'));
        $this->assertSame('02161823900', RecEmployee::where('personnel_number', 'RG3')->value('phone'), 'Festnetz bleibt stehen (nur gelistet).');
        $this->assertSame('kaputt', RecEmployee::where('personnel_number', 'RG4')->value('phone'), 'Unparsebares bleibt stehen.');
        $this->assertSame('0176 99999999', RecEmployee::where('personnel_number', 'RG5')->value('phone'), 'Inaktive bleiben unberuehrt.');

        $again = $cmd->normalize(false, null, fn ($t, $x) => null);
        $this->assertSame(0, $again['fixed'], 'Zweiter Lauf findet nichts mehr (idempotent).');
    }

    public function test_team_filter_limits_the_run(): void
    {
        $this->employee('RG1', '017624533557');
        $other = $this->employee('RG9', '017611111111');
        Capsule::table('rec_employees')->where('id', $other->id)->update(['team_id' => 999]);

        $counts = (new NormalizeEmployeePhonesCommand())->normalize(false, self::TEAM, fn ($t, $x) => null);

        $this->assertSame(1, $counts['total']);
        $this->assertSame('017611111111', RecEmployee::where('personnel_number', 'RG9')->value('phone'));
    }

    public function test_gateway_hands_send_paths_e164_even_for_raw_stock(): void
    {
        $e = $this->employee('RG1', '017624533557');
        $bad = $this->employee('RG2', 'kaputt');

        $contacts = (new DispoEmployeeGateway())->contacts([$e->id, $bad->id]);

        $this->assertSame('+4917624533557', $contacts[$e->id]['phone'], 'Sendeweg bekommt E.164, egal was gespeichert ist.');
        $this->assertSame('kaputt', $contacts[$bad->id]['phone'], 'Unparsebares geht unveraendert raus (faellt als failed auf).');
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }
}
