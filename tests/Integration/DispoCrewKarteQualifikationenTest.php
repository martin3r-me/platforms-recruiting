<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Events\Show;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;

/**
 * Crew-Karte der Veranstaltungsseite: zeigt die ZAS-Taetigkeiten ({Dispo5}) statt
 * des handgepflegten Felds. qualifications() (Info-Versand) bleibt auf dem alten Feld.
 */
class DispoCrewKarteQualifikationenTest extends TestCase
{
    private const TEAM = 1101;

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
        $container->instance('config', new ConfigRepository([]));

        self::runMigrations();
        RecEmployeeExportObserver::register();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_employees', 'rec_employee_hr_data', 'core_lookups', 'core_lookup_values', 'rec_dispo_events', 'rec_dispo_assignments'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private function employee(string $pnr, array $hr = []): RecEmployee
    {
        $e = RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ]);
        $row = $e->ensureHrData();
        if ($hr !== []) {
            Capsule::table('rec_employee_hr_data')->where('id', $row->id)->update($hr);
        }

        return $e->fresh();
    }

    public function test_card_shows_the_zas_taetigkeiten_not_the_old_field(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten'   => json_encode(['Servicekräfte', 'Logistiker'], JSON_UNESCAPED_UNICODE),
            'qualifications'       => json_encode(['ALTWERT'], JSON_UNESCAPED_UNICODE),
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame(['Servicekräfte', 'Logistiker'], $card['qualifications']);
        $this->assertNotContains('ALTWERT', $card['qualifications'],
            'Das handgepflegte Feld gehoert nicht mehr auf die Karte.');
    }

    public function test_names_pass_through_without_lookup_translation(): void
    {
        // Reale Katalognamen aus Lieferung #258 — Schraegstriche, Punkte,
        // Umlaute. Frueher lief das durch core_lookup_values; jetzt nicht mehr.
        // Koeder: Laeuft die Uebersetzung wieder, kommt das Label heraus.
        $lookupId = Capsule::table('core_lookups')->insertGetId([
            'team_id' => self::TEAM, 'name' => 'qualifikation', 'label' => 'Qualifikation',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Capsule::table('core_lookup_values')->insert([
            'lookup_id' => $lookupId, 'value' => '1. FC Köln / Service', 'label' => 'FALSCH ÜBERSETZT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten' => json_encode(['1. FC Köln / Service', 'Barista / Kaffee'], JSON_UNESCAPED_UNICODE),
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame(['1. FC Köln / Service', 'Barista / Kaffee'], $card['qualifications']);
    }

    public function test_card_carries_the_sync_timestamp(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten'           => json_encode(['Servicekräfte'], JSON_UNESCAPED_UNICODE),
            'dispo_taetigkeiten_synced_at' => '2026-10-08 14:12:00',
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame('2026-10-08 14:12:00', $card['qualifications_synced_at'],
            'Roh und sortierbar — die Anzeigeform macht erst crewCard().');
    }

    public function test_employee_without_assignment_yields_empty_list_and_null_timestamp(): void
    {
        $e = $this->employee('RG1');

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame([], $card['qualifications'],
            '266 aktive Neuzugaenge haben noch nichts — das darf nicht knallen.');
        $this->assertNull($card['qualifications_synced_at']);
    }

    public function test_eightyseven_taetigkeiten_come_through_uncapped_in_the_gateway(): void
    {
        $viele = [];
        for ($i = 1; $i <= 87; $i++) {
            $viele[] = 'Taetigkeit ' . $i;
        }
        $e = $this->employee('RG1', ['dispo_taetigkeiten' => json_encode($viele, JSON_UNESCAPED_UNICODE)]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertCount(87, $card['qualifications'],
            'Die Deckelung gehoert in die Anzeige, nicht in die Datenschicht — der Aufklapper braucht alle.');
    }

    public function test_employee_without_any_hr_row_does_not_throw(): void
    {
        $e = $this->employee('RG1');
        Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $e->id)->delete();

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame([], $card['qualifications']);
        $this->assertNull($card['qualifications_synced_at']);
    }

    public function test_the_info_versand_source_is_untouched(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten' => json_encode(['Servicekräfte'], JSON_UNESCAPED_UNICODE),
            'qualifications'     => json_encode(['ALTWERT'], JSON_UNESCAPED_UNICODE),
        ]);

        $data = (new DispoEmployeeGateway())->qualifications([$e->id]);

        $this->assertSame(['ALTWERT'], $data['byEmployee'][$e->id],
            'qualifications() speist den Filter im Info-Versand und bleibt auf dem alten Feld.');
    }

    public function test_crew_card_actually_uses_the_merge_and_formats_the_date(): void
    {
        $e = $this->employee('RG500', [
            'dispo_taetigkeiten'           => json_encode(['Logistiker', 'Kasse'], JSON_UNESCAPED_UNICODE),
            'dispo_taetigkeiten_synced_at' => '2026-10-08 14:12:00',
        ]);
        $event = $this->event();
        $this->assignment($event, ['rec_employee_id' => $e->id]);

        $c = $this->dispoComponent($event->id);
        $c->crewEmployeeId = $e->id;
        $karte = $c->crewCard();

        $this->assertSame(['Logistiker', 'Kasse'], $karte['qualifications']);
        $this->assertSame('08.10. 14:12', $karte['qualifications_synced_at'],
            'Die Anzeigeform entsteht erst hier, nicht im Gateway.');
    }

    public function test_crew_card_of_an_employee_with_null_taetigkeiten_is_empty_not_broken(): void
    {
        $e = $this->employee('RG500');
        $event = $this->event();
        $this->assignment($event, ['rec_employee_id' => $e->id]);

        $c = $this->dispoComponent($event->id);
        $c->crewEmployeeId = $e->id;
        $karte = $c->crewCard();

        $this->assertSame([], $karte['qualifications'],
            '266 aktive Neuzugaenge haben noch nichts — das darf nicht knallen.');
        $this->assertNull($karte['qualifications_synced_at']);
    }

    private function event(): RecDispoEvent
    {
        static $n = 0;
        $n++;

        return RecDispoEvent::create([
            'einsatz_ref' => 'VA-' . $n,
            'name'        => 'Testveranstaltung ' . $n,
        ]);
    }

    private function assignment(RecDispoEvent $event, array $attrs): RecDispoAssignment
    {
        static $n = 0;
        $n++;

        return RecDispoAssignment::create(array_merge([
            'ds_ref'             => 'DS-' . $n,
            'rec_dispo_event_id' => $event->id,
            'pnr_raw'            => 'RG' . $n,
            'datum'              => '2026-10-01',
            'von'                => '08:00',
            'bis'                => '16:00',
            'status_id'          => RecDispoAssignment::STATUS_AUFTRAG,
            'taetigkeit'         => 'Service',
        ], $attrs));
    }

    /** Baut Show ohne Livewire-Mount; die #[Computed]-Getter verdrahtet der Test von Hand. */
    private function dispoComponent(int $eventId): Show
    {
        $c = new Show();
        $c->eventId = $eventId;
        $c->getAttributes()->each(function ($attribute) {
            if (method_exists($attribute, 'boot')) {
                $attribute->boot();
            }
        });

        return $c;
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own, 'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own, 'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
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
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
