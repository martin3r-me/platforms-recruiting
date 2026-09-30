<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\BackfillShortTermDayBudget;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Nachtrag des Tagekonto-Startwerts aus dem Arbeitsvertrag (30.09.2026).
 *
 * Gemessen werden die drei Eigenschaften, an denen es teuer wuerde: nur das
 * laufende Kalenderjahr, nie ueberschreiben, und KEIN ZAS-Export-Marker.
 */
class BackfillShortTermDayBudgetTest extends TestCase
{
    private const TEAM = 617;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $container->instance('config', new ConfigRepository([]));
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $dispatcher = new Dispatcher($container);
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $container->instance('events', $dispatcher);
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        Model::unguard();
        Model::clearBootedModels();

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php',
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_09_25_000001_add_short_term_days_to_hr_data.php',
            'database/migrations/2026_09_25_000002_add_short_term_year_to_hr_data.php',
        ] as $relative) {
            (require $own . '/' . $relative)->up();
        }

        Capsule::schema()->create('rec_contract_templates', function ($t) {
            $t->increments('id');
            $t->string('code', 20)->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Capsule::schema()->create('rec_contracts', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('rec_contract_template_id');
            $t->string('status', 30)->default('completed');
            $t->text('pre_signing_data')->nullable();
            $t->dateTime('signed_at')->nullable();
            $t->timestamps();
        });
        Capsule::schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        RecEmployeeExportObserver::register();
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_employee_hr_data')->delete();
        Capsule::table('rec_contracts')->delete();
        Capsule::table('rec_contract_templates')->delete();
        Capsule::table('rec_applicant_settings')->delete();
        Capsule::table('rec_contract_templates')->insert([
            ['id' => 1, 'code' => 'AV-default', 'name' => 'Arbeitsvertrag'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    /** 20 erklaerte Tage im laufenden Jahr → 70 − 20 = 50. */
    private function signedContract(int $applicantId, string $signedAt, array $declaration): void
    {
        Capsule::table('rec_contracts')->insert([
            'uuid'                     => uniqid('c', true),
            'rec_applicant_id'         => $applicantId,
            'rec_contract_template_id' => 1,
            'status'                   => 'completed',
            'signed_at'                => $signedAt,
            'pre_signing_data'         => json_encode($declaration),
        ]);
    }

    private function declaration(array $entries = [], bool $hasPrevious = true): array
    {
        // Die Maske speichert '1' / '0' — ShortTermDayBudget::antwort() laesst
        // nur das gelten, alles andere ist "keine eindeutige Erklaerung".
        return ['par15_has_previous' => $hasPrevious ? '1' : '0', 'par15_entries' => $entries];
    }

    private function employee(int $applicantId): RecEmployee
    {
        return RecEmployee::create([
            'team_id'          => self::TEAM,
            'first_name'       => 'Erika',
            'last_name'        => 'Muster',
            'portal_token'     => 'tok-stb-' . uniqid(),
            'rec_applicant_id' => $applicantId,
            'is_active'        => true,
        ]);
    }

    /** @return array{seen:int, set:int, skipped:int} */
    private function lauf(bool $dryRun = false, ?int $team = null, ?int $employeeId = null): array
    {
        return (new BackfillShortTermDayBudget())
            ->backfill($dryRun, $team, $employeeId, fn ($type, $text) => null);
    }

    private function hrOf(int $employeeId): ?object
    {
        return Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $employeeId)->first();
    }

    public function test_startwert_wird_aus_dem_vertrag_nachgetragen(): void
    {
        $this->signedContract(701, '2026-03-14 09:00:00', $this->declaration([
            ['von' => '2026-01-10', 'bis' => '2026-01-29', 'tage' => 20],
        ]));
        $ma = $this->employee(701);

        $counts = $this->lauf();

        $this->assertSame(1, $counts['set']);
        $hr = $this->hrOf($ma->id);
        $this->assertNotNull($hr, 'Auch ohne vorhandene HR-Zeile muss der Wert landen.');
        $this->assertSame(2026, (int) $hr->short_term_days_allowed_year);
        $this->assertSame(50, (int) $hr->short_term_days_allowed, '70 minus 20 erklaerte Tage.');
    }

    /** Das Kontingent gilt je Kalenderjahr — eine Erklaerung von 2025 sagt nichts ueber 2026. */
    public function test_vertrag_aus_dem_vorjahr_wird_uebersprungen(): void
    {
        $this->signedContract(702, '2025-11-02 09:00:00', $this->declaration([], false));
        $ma = $this->employee(702);

        $counts = $this->lauf();

        $this->assertSame(0, $counts['set']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertNull($this->hrOf($ma->id));
    }

    /** Ein vorhandener Startwert ist ein Anfangsbestand — der Lauf setzt ihn nie zurueck. */
    public function test_vorhandener_startwert_des_jahres_bleibt_stehen(): void
    {
        $this->signedContract(703, '2026-03-14 09:00:00', $this->declaration([], false));
        $ma = $this->employee(703);
        Capsule::table('rec_employee_hr_data')->insert([
            'uuid' => uniqid('hr', true), 'rec_employee_id' => $ma->id, 'team_id' => self::TEAM,
            'short_term_days_allowed' => 12, 'short_term_days_allowed_year' => 2026,
        ]);

        $counts = $this->lauf();

        $this->assertSame(0, $counts['seen'], 'Der Mensch taucht gar nicht erst in der Auswahl auf.');
        $this->assertSame(12, (int) $this->hrOf($ma->id)->short_term_days_allowed);
    }

    /** Startwert aus dem Vorjahr: neues Jahr, neues Kontingent — wird ersetzt. */
    public function test_startwert_aus_dem_vorjahr_wird_erneuert(): void
    {
        $this->signedContract(704, '2026-03-14 09:00:00', $this->declaration([], false));
        $ma = $this->employee(704);
        Capsule::table('rec_employee_hr_data')->insert([
            'uuid' => uniqid('hr', true), 'rec_employee_id' => $ma->id, 'team_id' => self::TEAM,
            'short_term_days_allowed' => 12, 'short_term_days_allowed_year' => 2025,
        ]);

        $this->lauf();

        $hr = $this->hrOf($ma->id);
        $this->assertSame(2026, (int) $hr->short_term_days_allowed_year);
        $this->assertSame(70, (int) $hr->short_term_days_allowed, 'Ohne Vorbeschaeftigung die volle Grenze.');
    }

    public function test_ohne_paragraf15_erklaerung_passiert_nichts(): void
    {
        $this->signedContract(705, '2026-03-14 09:00:00', ['irgendwas' => 'ja']);
        $ma = $this->employee(705);

        $counts = $this->lauf();

        $this->assertSame(0, $counts['set']);
        $this->assertNull($this->hrOf($ma->id));
    }

    public function test_trockenlauf_schreibt_nichts(): void
    {
        $this->signedContract(706, '2026-03-14 09:00:00', $this->declaration([], false));
        $ma = $this->employee(706);

        $counts = $this->lauf(dryRun: true);

        $this->assertSame(1, $counts['set'], 'Gezaehlt wird, was passieren wuerde.');
        $this->assertNull($this->hrOf($ma->id));
    }

    /**
     * Der teure Fall: ein Eloquent-Lauf ueber knapp 300 Menschen spuelte
     * denselben Bestand mit VOLLEN Zeilen in die naechste updates.csv.
     */
    public function test_kein_zas_export_marker(): void
    {
        $this->signedContract(707, '2026-03-14 09:00:00', $this->declaration([], false));
        $ma = $this->employee(707);
        Capsule::table('rec_employees')->where('id', $ma->id)->update(['zas_changed_at' => null]);

        $this->lauf();

        $this->assertNull($ma->fresh()->zas_changed_at);
    }

    public function test_der_employee_filter_greift(): void
    {
        $this->signedContract(708, '2026-03-14 09:00:00', $this->declaration([], false));
        $this->signedContract(709, '2026-03-14 09:00:00', $this->declaration([], false));
        $a = $this->employee(708);
        $b = $this->employee(709);

        $this->lauf(employeeId: $a->id);

        $this->assertNotNull($this->hrOf($a->id));
        $this->assertNull($this->hrOf($b->id));
    }
}
