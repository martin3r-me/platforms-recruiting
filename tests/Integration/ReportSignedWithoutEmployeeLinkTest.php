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
use Platform\Recruiting\Console\Commands\ReportSignedWithoutEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `--link` und `--backfill-links` haengen nach dem Link die Vertraege an die
 * Anstellung (Nachtrag zu Vertrag an der Anstellung §3.5). Echte Migrationen,
 * Lauf ueber Command::run() mit echtem ArrayInput.
 */
class ReportSignedWithoutEmployeeLinkTest extends TestCase
{
    private const TEAM = 9;
    private const T0 = '2026-09-01 08:00:00';

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

        $schema->create('crm_contacts', function ($table) {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });
        $schema->create('crm_contact_links', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('contact_id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
        });

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
        foreach (['rec_contracts', 'rec_contract_templates', 'rec_employees', 'rec_employee_hr_data', 'rec_applicants', 'crm_contacts', 'crm_contact_links', 'core_extra_field_values', 'core_extra_field_definitions'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Mutation: Anker-Aufruf in linkPairs() entfernen → rot. */
    public function test_link_setzt_den_anker(): void
    {
        $a = $this->applicant();
        $e = $this->employee(null, 'RG', ['personnel_number' => 'RG4711']);
        $c = $this->contract($a, $this->template('AV-default', 'RG'), ['rec_employee_id' => null]);

        [$exit, $out] = $this->lauf(['--link' => "{$a}:RG4711"]);

        $this->assertSame(0, $exit, $out);
        $row = Capsule::table('rec_employees')->where('id', $e)->first();
        $this->assertSame($a, (int) $row->rec_applicant_id);
        $this->assertSame($e, $this->anker($c));
        $this->assertNull($row->zas_changed_at);
        $this->assertStringContainsString('1 Vertraege angehaengt', $out);
    }

    public function test_link_probelauf_verankert_nichts(): void
    {
        $a = $this->applicant();
        $e = $this->employee(null, 'RG', ['personnel_number' => 'RG4711']);
        $c = $this->contract($a, $this->template('AV-default', 'RG'), ['rec_employee_id' => null]);

        [$exit, $out] = $this->lauf(['--link' => "{$a}:RG4711", '--dry-run' => true]);

        $this->assertSame(0, $exit, $out);
        $this->assertNull(Capsule::table('rec_employees')->where('id', $e)->value('rec_applicant_id'));
        $this->assertNull($this->anker($c));
    }

    public function test_link_an_ma_anstellung_laesst_rg_vertrag_beim_bewerber(): void
    {
        $a = $this->applicant();
        $e = $this->employee(null, 'MA', ['personnel_number' => 'MA4711']);
        $c = $this->contract($a, $this->template('AV-default', 'RG'), ['rec_employee_id' => null]);

        [$exit, $out] = $this->lauf(['--link' => "{$a}:MA4711"]);

        $this->assertSame(0, $exit, $out);
        $this->assertSame($a, (int) Capsule::table('rec_employees')->where('id', $e)->value('rec_applicant_id'));
        $this->assertNull($this->anker($c), 'Firmenregel: RG-Vertrag haengt nicht an der MA-Akte.');
    }

    /** Mutation: Anker-Aufruf in backfillLinks() entfernen → rot. */
    public function test_backfill_links_setzt_den_anker(): void
    {
        $a = $this->applicant();
        $e = $this->employee(null, 'RG', ['personnel_number' => 'RG4711', 'first_name' => 'Dario', 'last_name' => 'Halabarec', 'birth_date' => '1999-09-16']);
        $c = $this->contract($a, $this->template('AV-default', 'RG'), ['rec_employee_id' => null, 'signature_data' => 'x']);

        $k = (int) Capsule::table('crm_contacts')->insertGetId(['first_name' => 'Dario', 'last_name' => 'Halabarec']);
        Capsule::table('crm_contact_links')->insert(['contact_id' => $k, 'linkable_type' => 'rec_applicant', 'linkable_id' => $a]);
        $d = (int) Capsule::table('core_extra_field_definitions')->insertGetId(['name' => 'geburtsdatum', 'team_id' => self::TEAM, 'context_type' => 'rec_applicant', 'label' => 'Geburtsdatum', 'type' => 'text']);
        Capsule::table('core_extra_field_values')->insert(['definition_id' => $d, 'fieldable_type' => 'rec_applicant', 'fieldable_id' => $a, 'value' => '1999-09-16']);

        [$exit, $out] = $this->lauf(['--backfill-links' => true]);

        $this->assertSame(0, $exit, $out);
        $this->assertSame($a, (int) Capsule::table('rec_employees')->where('id', $e)->value('rec_applicant_id'), $out);
        $this->assertSame($e, $this->anker($c));
        $this->assertStringContainsString('1 Vertraege angehaengt', $out);
    }

    private function applicant(): int
    {
        return (int) Capsule::table('rec_applicants')->insertGetId(['uuid' => 'a-' . uniqid(), 'team_id' => self::TEAM, 'is_active' => 0, 'created_at' => self::T0, 'updated_at' => self::T0]);
    }

    private function employee(?int $applicantId, ?string $company, array $extra = []): int
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

    /** @return array{0:int,1:string} */
    private function lauf(array $options): array
    {
        $command = new ReportSignedWithoutEmployee();
        $command->setLaravel(new ReportSignedLinkFakeLaravel());
        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput($options, $command->getDefinition()), $output);

        return [$exit, $output->fetch()];
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

final class ReportSignedLinkFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
