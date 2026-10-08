<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeDirectory;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmMarker;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoImportPlanner;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;

/** Disk-Ersatz: haelt den Rohinhalt im Speicher, Storage::disk(...)->get(...). */
class FakeDispoDisk
{
    /** @var array<string,string> */
    public static array $dateien = [];

    public function disk(?string $name = null): self
    {
        return $this;
    }

    public function get(string $pfad): string
    {
        return self::$dateien[$pfad] ?? '';
    }
}

/** Log-Attrappe: Container::instance allein reicht nicht, die Facade cacht. */
class StummerLog
{
    public function error(string $m, array $c = []): void {}
    public function warning(string $m, array $c = []): void {}
    public function info(string $m, array $c = []): void {}
}

/**
 * Qualifikationen aus {Dispo4}/{Dispo5} (Mail Olaf Michel 06.10.2026).
 *
 * Die Bloecke kommen im selben Webexport wie die Einbuchungen. Hier wird
 * geprueft, dass sie bei jeder Lieferung in rec_employee_hr_data landen — und
 * vor allem, dass eine Lieferung OHNE sie nichts kaputt macht.
 */
class ZasDispoQualifikationImportTest extends TestCase
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
        $container->instance('filesystem', new FakeDispoDisk());
        $container->instance('log', new StummerLog());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['company_prefix' => 'RG']],
        ]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('filesystem');
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        FakeDispoDisk::$dateien = [];
        foreach ([
            'rec_employees', 'rec_employee_hr_data', 'core_lookups', 'core_lookup_values',
            'rec_zas_dispo_inbound_files', 'rec_dispo_events', 'rec_dispo_assignments', 'rec_persons',
        ] as $t) {
            Capsule::table($t)->delete();
        }
    }

    /**
     * Jede Tabelle, die ein Test anfasst, MUSS hier stehen. Fehlt sie, wertet
     * SQLite doppelt gequotete Bezeichner als String-Literal — der Test ist
     * dann gruen, ohne irgendetwas zu pruefen.
     */
    private static function runMigrations(): void
    {
        $own  = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);

        $files = [
            [$own,  'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own,  'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own,  'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own,  'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$own,  'database/migrations/2026_08_06_000001_create_rec_zas_dispo_inbound_files_table.php'],
            [$own,  'database/migrations/2026_08_12_000003_add_processed_at_to_rec_zas_dispo_inbound_files.php'],
            [$own,  'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own,  'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own,  'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_08_14_000002_add_vorlauf_minuten_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_19_000001_add_ansprechpartner_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_08_24_000003_add_alarm_message_id_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_27_000002_add_escalation_override_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_28_000001_add_escalation_date_to_rec_dispo_events.php'],
            [$own,  'database/migrations/2026_08_28_000002_add_reconfirm_fields_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_04_000002_add_escalation_plan_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_04_000003_add_reminder_sent_to_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_04_000004_add_manual_confirm_to_rec_dispo_assignments.php'],
            [$own,  'database/migrations/2026_09_08_000001_add_late_marker_to_rec_dispo_assignments.php'],
            // Branch feat/ma-konto: der Importer raeumt beim Wiederauftauchen den
            // Erinnerungs-Stempel (ET-18). Ohne die Spalte bricht der Live-Lauf
            // vor dem Qualifikations-Sync ab — die Welt kommt aus den Migrationen.
            [$own,  'database/migrations/2026_09_28_000001_create_rec_persons_table.php'],
            [$own,  'database/migrations/2026_09_29_000001_add_konto_felder_to_rec_persons.php'],
            [$own,  'database/migrations/2026_10_01_000001_add_trigger_state.php'],
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

    private function employee(string $pnr): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ]);
    }

    /** Schreibt eine Rohdatei auf den Attrappen-Disk und importiert sie. */
    private function importiere(string $inhalt, bool $dryRun = false): array
    {
        $pfad = 'zas-dispo-inbound/test-' . uniqid() . '.csv';
        FakeDispoDisk::$dateien[$pfad] = $inhalt;

        $file = RecZasDispoInboundFile::create([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'source' => 'test', 'original_filename' => 'test.csv',
            'disk' => 'local', 'stored_path' => $pfad,
            'mime_type' => 'text/csv', 'size_bytes' => strlen($inhalt),
            'parse_status' => 'viewable',
        ]);

        $importer = new ZasDispoWebexportImporter(
            new ZasDispoBlockSplitter(),
            new ZasDispoImportPlanner(),
            new DispoEmployeeDirectory(),
            new DispoReconfirmMarker(),
        );

        return $importer->import($file, $dryRun);
    }

    /** Eine Einbuchungszeile in der Zukunft — Form aus Michels Beispielexport. */
    private function dispoZeile(string $pnr = 'RG1464'): string
    {
        return "{Dispo}\r\n19.05.2027;BHG. BROICHCATERING GMBH;{$pnr};RG19077;1;RG13450;830363;10:30;21:15;2;0;Servicekräfte;;27,49;\r\n";
    }

    public function test_dispo5_fills_the_employee_qualifications(): void
    {
        $mitarbeiter = $this->employee('RG1464');

        $summary = $this->importiere(
            "{Dispo4}\r\n8;Servicekräfte;RG8;\r\n13;Logistiker;RG13;\r\n"
            . "{Dispo5}\r\nRG1464;RG8;17;\r\nRG1464;RG13;0;\r\n"
        );

        $this->assertSame(['Servicekräfte', 'Logistiker'], (array) $mitarbeiter->fresh()->hrData->dispo_taetigkeiten);
        $this->assertSame(1, $summary['qualifikationen']['matched']);
        $this->assertSame(1, $summary['qualifikationen']['employees_updated']);
        $this->assertSame(2, $summary['qualifikationen']['katalog']);
    }

    public function test_delivery_without_dispo5_leaves_existing_qualifications_alone(): void
    {
        $mitarbeiter = $this->employee('RG1464');
        $this->importiere("{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG1464;RG8;17;\r\n");
        $this->assertSame(['Servicekräfte'], (array) $mitarbeiter->fresh()->hrData->dispo_taetigkeiten, 'Testannahme');

        $this->importiere($this->dispoZeile());

        $this->assertSame(['Servicekräfte'], (array) $mitarbeiter->fresh()->hrData->dispo_taetigkeiten,
            'Ende-zu-Ende: eine Teillieferung ohne {Dispo5} laesst gespeicherte Qualifikationen stehen (der Frueh-Ausstieg selbst wird in test_delivery_with_dispo4_only_does_not_run_the_qualification_sync geprueft).');
    }

    public function test_delivery_with_dispo4_only_does_not_run_the_qualification_sync(): void
    {
        // Ergaenzung zum Brief: syncMany([]) ist von sich aus ein No-op, die
        // Mutationsprobe am Frueh-Ausstieg waere ohne diesen Test ueberlebt.
        // Beobachtbar ist der Ausstieg an den Zaehlern: ohne ihn liefe der
        // Extractor und fuellte 'katalog'.
        $this->employee('RG1464');

        $summary = $this->importiere($this->dispoZeile() . "{Dispo4}\r\n8;Servicekräfte;RG8;\r\n");

        $this->assertSame(0, $summary['qualifikationen']['katalog']);
        $this->assertSame(0, $summary['qualifikationen']['matched']);
        $this->assertSame(0, Capsule::table('core_lookup_values')->count());
    }

    public function test_two_personnel_number_forms_of_one_employee_are_merged(): void
    {
        // Lange Form im Stamm, gekuerzte Dispo-Form (ZasPersonnelNumber::shortenedForm)
        // in {Dispo5}: beide Zeilen matchen auf denselben Mitarbeiter.
        $mitarbeiter = $this->employee('RG1000000878');

        $summary = $this->importiere(
            "{Dispo4}\r\n8;Servicekräfte;RG8;\r\n13;Logistiker;RG13;\r\n"
            . "{Dispo5}\r\nRG1000000878;RG8;3;\r\nRG878;RG13;5;\r\n"
        );

        $this->assertSame(2, $summary['qualifikationen']['pnr_gesamt']);
        $this->assertSame(2, $summary['qualifikationen']['matched']);
        $this->assertSame(1, $summary['qualifikationen']['employees_updated']);
        $liste = (array) $mitarbeiter->fresh()->hrData->dispo_taetigkeiten;
        sort($liste);
        $this->assertSame(['Logistiker', 'Servicekräfte'], $liste,
            'Die Vereinigung muss beide Taetigkeiten tragen, nicht nur die der letzten Zeile.');
    }

    public function test_qualification_counters_are_written_to_the_file_notes(): void
    {
        $this->employee('RG1464');
        $this->importiere("{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG1464;RG8;17;\r\n");

        $notes = RecZasDispoInboundFile::query()->orderByDesc('id')->first()->notes;
        $notes = is_array($notes) ? $notes : json_decode((string) $notes, true);

        $this->assertIsArray($notes);
        $this->assertSame(1, $notes['qualifikationen']['matched'] ?? null);
        $this->assertSame(1, $notes['qualifikationen']['employees_updated'] ?? null);
    }

    public function test_unknown_personnel_numbers_are_counted_not_written(): void
    {
        $summary = $this->importiere("{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG999999;RG8;1;\r\n");

        $this->assertSame(0, $summary['qualifikationen']['matched']);
        $this->assertSame(1, $summary['qualifikationen']['unmatched']);
    }

    public function test_placeholder_rg14_does_not_touch_the_real_employee(): void
    {
        $echter = $this->employee('RG14');

        $summary = $this->importiere("{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG14;RG8;782;\r\n");

        $this->assertSame([], (array) ($echter->fresh()->hrData?->dispo_taetigkeiten ?? []),
            'RG14 ist ein unbesetzter Platz und trifft zugleich einen echten Mitarbeiter.');
        $this->assertSame(1, $summary['qualifikationen']['platzhalter']);
        $this->assertSame(0, $summary['qualifikationen']['matched']);
    }

    public function test_dry_run_counts_but_writes_nothing(): void
    {
        $mitarbeiter = $this->employee('RG1464');

        $summary = $this->importiere(
            "{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG1464;RG8;17;\r\n",
            dryRun: true
        );

        $this->assertSame(1, $summary['qualifikationen']['matched'], 'Die Vorschau zaehlt wie der Live-Lauf.');
        $this->assertSame([], (array) ($mitarbeiter->fresh()->hrData?->dispo_taetigkeiten ?? []));
        $this->assertSame(0, Capsule::table('core_lookup_values')->count());
    }

    public function test_a_failing_qualification_sync_does_not_roll_back_the_dispo_import(): void
    {
        $this->employee('RG1464');
        // Ohne diese Tabelle wirft die Listenpflege mitten im Sync.
        Capsule::schema()->drop('core_lookup_values');

        try {
            $summary = $this->importiere(
                $this->dispoZeile()
                . "{Dispo4}\r\n8;Servicekräfte;RG8;\r\n{Dispo5}\r\nRG1464;RG8;17;\r\n"
            );

            $this->assertSame(1, Capsule::table('rec_dispo_assignments')->count(),
                'Die Einbuchung muss stehen bleiben — Qualifikationen sind Sekundaerdaten.');
            $this->assertNotSame([], $summary['qualifikationen']['fehler']);
            $this->assertStringContainsString('core_lookup_values', implode(' ', $summary['qualifikationen']['fehler']),
                'Der Fehler muss von der absichtlich geloeschten Tabelle kommen, nicht von etwas anderem.');
            $this->assertArrayNotHasKey('rolled_back', $summary);
        } finally {
            self::runMigrationFor('core_lookup_values');
        }
    }

    /** Stellt eine im Test absichtlich geloeschte Tabelle wieder her. */
    private static function runMigrationFor(string $table): void
    {
        if (Capsule::schema()->hasTable($table)) {
            return;
        }
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);
        Capsule::schema()->drop('core_lookups');
        (require $core . '/database/migrations/2026_02_12_000003_create_core_lookups_tables.php')->up();
    }
}
