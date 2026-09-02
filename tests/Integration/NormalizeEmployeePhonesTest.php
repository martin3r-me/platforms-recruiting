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

    /**
     * Der Kern des Vorfalls vom 02.09.2026: der Lauf schrieb per Eloquent, der
     * RecEmployeeExportObserver setzte daraufhin zas_changed_at, und ~500
     * ZAS-Bestandsmitarbeiter landeten im Update-Export — der volle Zeilen
     * liefert und in ZAS gepflegte Akten ueberschrieben haette.
     *
     * Geprueft wird die Ursache, nicht das Symptom: der Lauf darf gar kein
     * updated-Event ausloesen. Damit ist es egal, welche Observer heute oder
     * spaeter daran haengen. Eigener Dispatcher nur fuer die Dauer des Tests,
     * danach der originale zurueck — die Model-Events sind prozessweit statisch.
     */
    public function test_run_fires_no_eloquent_events_so_no_observer_can_mark_for_zas(): void
    {
        $this->employee('RG1', '017624533557');

        $original = RecEmployee::getEventDispatcher();
        $spy      = new Dispatcher(Container::getInstance());
        $fired    = [];
        foreach (['updated', 'saved', 'updating', 'saving'] as $event) {
            $spy->listen("eloquent.{$event}: " . RecEmployee::class, function () use (&$fired, $event) {
                $fired[] = $event;
            });
        }
        RecEmployee::setEventDispatcher($spy);

        try {
            (new NormalizeEmployeePhonesCommand())->normalize(false, null, fn ($t, $x) => null);
        } finally {
            RecEmployee::setEventDispatcher($original);
        }

        $this->assertSame('+4917624533557', RecEmployee::where('personnel_number', 'RG1')->value('phone'), 'Korrektur muss trotzdem ankommen.');
        $this->assertSame([], $fired, 'Der Lauf darf keine Model-Events ausloesen (sonst markiert der Export-Observer).');
    }

    /**
     * Wirkung statt Mechanik: der Marker bleibt, wie er war — und updated_at
     * ebenfalls. Letzteres ist kein Zufall: der Lauf vom 01.09. hat mit
     * updated_at die einzige Spur ueberschrieben, an der sich nachtraeglich
     * ablesen liess, wer sich WIRKLICH geaendert hatte. Eine Formatkorrektur
     * ist keine fachliche Aenderung und darf diese Spur nicht verbrauchen.
     */
    public function test_run_touches_neither_the_zas_marker_nor_updated_at(): void
    {
        $mitMarker = $this->employee('RG1', '017624533557');
        $ohne      = $this->employee('RG2', '017611111111');

        Capsule::table('rec_employees')->where('id', $mitMarker->id)
            ->update(['zas_changed_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 09:00:00']);
        Capsule::table('rec_employees')->where('id', $ohne->id)
            ->update(['zas_changed_at' => null, 'updated_at' => '2026-08-01 09:00:00']);

        (new NormalizeEmployeePhonesCommand())->normalize(false, null, fn ($t, $x) => null);

        $a = Capsule::table('rec_employees')->where('id', $mitMarker->id)->first();
        $b = Capsule::table('rec_employees')->where('id', $ohne->id)->first();

        $this->assertSame('+4917624533557', $a->phone);
        $this->assertSame('+4917611111111', $b->phone);
        // Bestehender Marker bleibt unveraendert stehen (nicht neu gestempelt),
        // ein fehlender wird nicht gesetzt.
        $this->assertStringStartsWith('2026-08-01 10:00:00', (string) $a->zas_changed_at);
        $this->assertNull($b->zas_changed_at, 'Ohne Marker heisst: geht NICHT in den ZAS-Update-Export.');
        $this->assertStringStartsWith('2026-08-01 09:00:00', (string) $a->updated_at, 'updated_at ist die Aenderungs-Spur — nicht verbrauchen.');
        $this->assertStringStartsWith('2026-08-01 09:00:00', (string) $b->updated_at);
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
            [$own, 'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
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
