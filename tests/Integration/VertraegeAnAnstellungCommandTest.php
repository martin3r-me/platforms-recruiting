<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Console\Commands\VertraegeAnAnstellung;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Backfill `recruiting:vertraege-an-anstellung` (Spec Vertrag an der Anstellung
 * §3.5, Tests 5, 6, 7, 12). Schema aus den ECHTEN eigenen Migrationen (wie
 * VersandVormerkenTest::runRealMigrations), der echte
 * RecEmployeeExportObserver ist scharf — er ist die Falle fuer Test 7.
 * Fixtures per Query Builder, damit kein Hook laeuft und Zeitstempel fest sind.
 */
class VertraegeAnAnstellungCommandTest extends TestCase
{
    private const TEAM = 9;
    private const T0 = '2026-09-01 08:00:00';

    /** @var list<string> */
    private array $meldungen = [];

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => ['company_prefix' => 'RG']],
        ]));
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
        Model::setEventDispatcher($dispatcher);
        Model::clearBootedModels();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        foreach (['teams', 'users', 'hcm_job_titles', 'comms_channels'] as $fremd) {
            if (!$schema->hasTable($fremd)) {
                $schema->create($fremd, fn ($table) => $table->id());
            }
        }

        self::runRealMigrations();

        RecEmployeeExportObserver::register();
    }

    public static function tearDownAfterClass(): void
    {
        Model::unsetEventDispatcher();
        Model::clearBootedModels();
        Capsule::schema()->dropAllTables();
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
        foreach (['rec_contracts', 'rec_contract_templates', 'rec_employees', 'rec_employee_hr_data', 'rec_applicants'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * §3.7 Test 5 — die Firma der Vorlage entscheidet. MA hat die kleinere
     * Kennung, damit ein first() ohne Firmenfilter strukturell rot wird.
     * Mutation A: Firmenfilter raus (first() ueber alle Anstellungen) → rot.
     * Mutation B: 'RG' statt $template->company → Gegenprobe rot.
     */
    public function test_backfill_folgt_der_firma_der_vorlage(): void
    {
        $a  = $this->applicant();
        $ma = $this->employee($a, 'MA');
        $rg = $this->employee($a, 'RG');
        $rgVertrag = $this->contract($a, $this->template('AV-default', 'RG'));
        $maVertrag = $this->contract($a, $this->template('AV-MA-LOG', 'MA', 'logistiker'));

        $counts = $this->lauf();

        $this->assertSame($rg, $this->anker($rgVertrag));
        $this->assertSame($ma, $this->anker($maVertrag), 'Gegenprobe: MA-Vorlage → MA-Anstellung');
        $this->assertSame(2, $counts['zugeordnet']);
    }

    /** §3.7 Test 6 — Mutation: bei mehrdeutig trotzdem ersterKandidatId() schreiben → rot. */
    public function test_backfill_laesst_mehrdeutig_stehen_und_meldet(): void
    {
        $a = $this->applicant();
        $e1 = $this->employee($a, 'RG');
        $e2 = $this->employee($a, 'RG');
        $c = $this->contract($a, $this->template('AV-default'));

        $counts = $this->lauf();

        $this->assertNull($this->anker($c));
        $this->assertSame(1, $counts['mehrdeutig']);
        $this->assertSame(0, $counts['zugeordnet']);
        $this->assertStringContainsString("#{$c}", implode("\n", $this->meldungen));
        $this->assertStringContainsString("{$e1}", implode("\n", $this->meldungen));
        $this->assertStringContainsString("{$e2}", implode("\n", $this->meldungen));
    }

    public function test_backfill_firma_fehlt_bleibt_beim_bewerber(): void
    {
        $a = $this->applicant();
        $this->employee($a, 'MA');                        // einzige Anstellung, falsche Firma
        $c = $this->contract($a, $this->template('AV-default', 'RG'));

        $counts = $this->lauf();

        $this->assertNull($this->anker($c), 'RG-Vertrag an der MA-Akte waere der Fehler im Kleinen.');
        $this->assertSame(1, $counts['firma_fehlt']);
    }

    public function test_backfill_ohne_anstellung_und_idempotent_und_trockenlauf(): void
    {
        $a = $this->applicant();
        $c = $this->contract($a, $this->template('AV-default'));
        $this->assertSame(1, $this->lauf()['ohne_anstellung']);
        $this->assertNull($this->anker($c));

        $b = $this->applicant();
        $rg = $this->employee($b, 'RG');
        $d = $this->contract($b, $this->template('IFSG'));
        $this->assertSame(1, $this->lauf(dryRun: true)['zugeordnet'], 'Trockenlauf zaehlt, was er taete');
        $this->assertNull($this->anker($d), 'Trockenlauf schreibt nichts');
        $this->lauf();
        $this->assertSame($rg, $this->anker($d));
        $this->assertSame(0, $this->lauf()['zugeordnet'], 'Zweiter Lauf findet nichts mehr');
    }

    /**
     * §3.7 Test 7 — observer-frei. VORFLUG im selben Test: der echte Observer
     * ist scharf — ein Eloquent-Save eines signierten Vertrags setzt den
     * ZAS-Marker ueber RecContract::saved → hrData.contract_signed_at →
     * RecEmployeeHrData::saved. Erst danach die eigentliche Messung.
     * Mutation: im Backfill `RecContract::find($id)->update(['rec_employee_id' => ...])`
     * statt DB::table → rot (Marker gesetzt).
     */
    public function test_backfill_setzt_keinen_zas_marker(): void
    {
        $a = $this->applicant();
        $rg = $this->employee($a, 'RG');
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => 'h-' . uniqid(), 'rec_employee_id' => $rg, 'team_id' => self::TEAM, 'created_at' => self::T0, 'updated_at' => self::T0]);
        $c = $this->contract($a, $this->template('AV-default'));

        // Vorflug: die Falle ist scharf.
        \Platform\Recruiting\Models\RecContract::find($c)->update(['notes' => 'vorflug']);
        $this->assertNotNull(Capsule::table('rec_employees')->where('id', $rg)->value('zas_changed_at'), 'Vorflug: Eloquent-Save am signierten Vertrag MUSS den Marker setzen, sonst prueft dieser Test nichts.');
        Capsule::table('rec_employees')->where('id', $rg)->update(['zas_changed_at' => null, 'updated_at' => self::T0]);
        Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $rg)->update(['contract_signed_at' => null, 'updated_at' => self::T0]);

        $this->lauf();

        $this->assertSame($rg, $this->anker($c));
        $row = Capsule::table('rec_employees')->where('id', $rg)->first();
        $this->assertNull($row->zas_changed_at, 'Kein ZAS-Marker durch das Backfill (Vorfall 02.09.2026).');
        $this->assertSame(self::T0, (string) $row->updated_at);
    }

    /** §3.7 Test 12 — Mutation: Praefix-Filter `code LIKE 'AV-%'` entfernen → rot (IFSG/AT-140 bekommen die Taetigkeit). */
    public function test_schritt_0_typisiert_nur_av_vorlagen(): void
    {
        $av   = $this->template('AV-default');
        $av2  = $this->template('AV-060');
        $at   = $this->template('AT-140');
        $ifsg = $this->template('IFSG');
        $fix  = $this->template('AV-MA-LOG', 'MA', 'logistiker');

        $counts = $this->lauf();

        $t = fn (int $id) => Capsule::table('rec_contract_templates')->where('id', $id)->value('taetigkeit');
        $this->assertSame('eventmitarbeiter', $t($av));
        $this->assertSame('eventmitarbeiter', $t($av2));
        $this->assertNull($t($at));
        $this->assertNull($t($ifsg));
        $this->assertSame('logistiker', $t($fix), 'Gesetzte Werte bleiben.');
        $this->assertSame(2, $counts['vorlagen_typisiert']);
        $this->assertSame(0, $this->lauf()['vorlagen_typisiert'], 'idempotent');
    }

    /** Review-Focus 4 — Mutation: verwaist-Zaehlung entfernen → rot. */
    public function test_bericht_nennt_verwaiste_anker(): void
    {
        $a = $this->applicant();
        $c = $this->contract($a, $this->template('AV-default'), ['rec_employee_id' => 999999]);

        $counts = $this->lauf();

        $this->assertSame(1, $counts['verwaist']);
        $this->assertSame(999999, $this->anker($c), 'Der Bericht aendert verwaiste Anker nicht — das ist Handarbeit.');
        $this->assertStringContainsString("#{$c}", implode("\n", $this->meldungen));
    }

    /** Review-Focus 5 — stornierte Vertraege gehoeren auch zur Anstellung (Archiv). Mutation: `whereNotIn('status', ['cancelled'])` einbauen → rot. */
    public function test_backfill_haengt_auch_stornierte_an(): void
    {
        $a = $this->applicant();
        $rg = $this->employee($a, 'RG');
        $c = $this->contract($a, $this->template('AV-default'), ['status' => 'cancelled']);
        $this->lauf();
        $this->assertSame($rg, $this->anker($c));
    }

    private function applicant(): int
    {
        return (int) Capsule::table('rec_applicants')->insertGetId(['uuid' => 'a-' . uniqid(), 'team_id' => self::TEAM, 'is_active' => 0, 'created_at' => self::T0, 'updated_at' => self::T0]);
    }

    private function employee(int $applicantId, ?string $company, array $extra = []): int
    {
        return (int) Capsule::table('rec_employees')->insertGetId(array_merge([
            'uuid' => 'e-' . uniqid(), 'portal_token' => 't-' . uniqid(), 'team_id' => self::TEAM,
            'rec_applicant_id' => $applicantId, 'company' => $company, 'is_active' => 1,
            'zas_changed_at' => null, 'created_at' => self::T0, 'updated_at' => self::T0,
        ], $extra));
    }

    private function template(string $code, string $company = 'RG', ?string $taetigkeit = null): int
    {
        return (int) Capsule::table('rec_contract_templates')->insertGetId(['uuid' => 'v-' . uniqid(), 'team_id' => self::TEAM, 'name' => $code, 'code' => $code, 'company' => $company, 'taetigkeit' => $taetigkeit, 'is_active' => 1, 'created_at' => self::T0, 'updated_at' => self::T0]);
    }

    private function contract(int $applicantId, int $templateId, array $extra = []): int
    {
        return (int) Capsule::table('rec_contracts')->insertGetId(array_merge([
            'uuid' => 'c-' . uniqid(), 'team_id' => self::TEAM, 'rec_applicant_id' => $applicantId,
            'rec_contract_template_id' => $templateId, 'status' => 'completed',
            'sent_at' => self::T0, 'signed_at' => '2026-09-02 08:00:00', 'completed_at' => '2026-09-02 08:00:00',
            'created_at' => self::T0, 'updated_at' => self::T0,
        ], $extra));
    }

    private function anker(int $contractId): ?int
    {
        $v = Capsule::table('rec_contracts')->where('id', $contractId)->value('rec_employee_id');

        return $v === null ? null : (int) $v;
    }

    /** @return array{vorlagen_typisiert:int, zugeordnet:int, mehrdeutig:int, firma_fehlt:int, ohne_anstellung:int, verwaist:int} */
    private function lauf(bool $dryRun = false): array
    {
        $this->meldungen = [];

        return (new VertraegeAnAnstellung())->backfill($dryRun, null, function (string $type, string $text): void {
            $this->meldungen[] = $text;
        });
    }

    /** Wie VersandVormerkenTest::runRealMigrations(): eigene Migrationen + zwei Core-Extra-Field-Migrationen. */
    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }
}
