<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\BackfillEmployerDeclaration;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Support\EmployerDeclaration;

/**
 * Traegt die Arbeitgeber-Erklaerung aus dem unterschriebenen Arbeitsvertrag
 * im Bestand nach.
 *
 * DER EIGENTLICHE GRUND ist nicht der Fehlerfall, sondern der Normalbetrieb:
 * ContractSigning schreibt auf `applicant->employee` — eine 1:1-Beziehung.
 * Eine Person kann aber ZWEI rec_employees haben (RG und MA, Chaieb-Befund
 * 10.09.2026). Von denen bekommt beim Unterschreiben nur einer die
 * Erklaerung. Dieses Kommando arbeitet pro MITARBEITER und schliesst die
 * Luecke; der Fehlerfall (Schreibfehler bei der Unterschrift) ist die Zugabe.
 *
 * DREI REGELN:
 *  1. NUR LEERE FELDER — eine im Portal gegebene Antwort ist juenger als der
 *     Vertrag und darf nie ueberschrieben werden.
 *  2. OBSERVER-FREI. Die Spalten gehen heute nicht nach ZAS; sobald sie es
 *     tun, wuerde ein Eloquent-Lauf den halben Bestand in die naechste
 *     updates.csv spuelen (Vorfall 02.09.2026). Nachlieferung an ZAS ist
 *     ein eigener, angekuendigter Schritt.
 *  3. Trockenlauf schreibt nichts, zaehlt aber, was er getan haette.
 */
class BackfillEmployerDeclarationTest extends TestCase
{
    private const TEAM = 615;

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
            // Zwei Anstellungen derselben Person unterscheiden sich in der
            // Personalnummer — genau der Fall, fuer den es dieses Kommando gibt.
            'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php',
            'database/migrations/2026_09_23_000002_add_employer_fields_to_rec_employees.php',
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
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_contracts')->delete();
        Capsule::table('rec_contract_templates')->delete();
        Capsule::table('rec_contract_templates')->insert([
            ['id' => 1, 'code' => 'AV-default', 'name' => 'Arbeitsvertrag'],
        ]);
    }

    private function signedContract(int $applicantId, array $declaration): void
    {
        Capsule::table('rec_contracts')->insert([
            'uuid'                     => uniqid('c', true),
            'rec_applicant_id'         => $applicantId,
            'rec_contract_template_id' => 1,
            'status'                   => 'completed',
            'signed_at'                => '2026-09-25 10:00:00',
            'pre_signing_data'         => json_encode($declaration),
        ]);
    }

    private function employee(int $applicantId, array $overrides = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'first_name'       => 'Erika',
            'last_name'        => 'Muster',
            'portal_token'     => 'tok-bf-' . uniqid(),
            'rec_applicant_id' => $applicantId,
            'is_active'        => true,
        ], $overrides));
    }

    /** @return array{seen:int, set:int, skipped:int} */
    private function lauf(bool $dryRun, ?int $team = null, ?int $employeeId = null): array
    {
        return (new BackfillEmployerDeclaration())
            ->backfill($dryRun, $team, $employeeId, fn ($type, $text) => null);
    }

    public function test_leerer_mitarbeiter_bekommt_die_erklaerung(): void
    {
        $this->signedContract(500, [
            EmployerDeclaration::KEY_ROLE  => EmployerDeclaration::ROLE_SECONDARY,
            EmployerDeclaration::KEY_OTHER => 'Mueller GmbH',
        ]);
        $ma = $this->employee(500);

        $counts = $this->lauf(dryRun: false);

        $this->assertSame(1, $counts['set']);
        $this->assertFalse($ma->fresh()->is_main_employer);
        $this->assertSame('Mueller GmbH', $ma->fresh()->other_employer);
    }

    /**
     * Der Fall, fuer den es dieses Kommando vor allem gibt: eine Person mit
     * zwei Anstellungen. Beim Unterschreiben bekommt nur einer der beiden
     * Datensaetze die Erklaerung.
     */
    public function test_beide_anstellungen_derselben_person_werden_gefuellt(): void
    {
        $this->signedContract(501, [EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN]);
        $rg = $this->employee(501, ['personnel_number' => 'RG100']);
        $ma = $this->employee(501, ['personnel_number' => 'MA100']);

        $counts = $this->lauf(dryRun: false);

        $this->assertSame(2, $counts['set']);
        $this->assertTrue($rg->fresh()->is_main_employer);
        $this->assertTrue($ma->fresh()->is_main_employer);
    }

    public function test_vorhandene_antwort_wird_nie_ueberschrieben(): void
    {
        $this->signedContract(502, [EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN]);
        $ma = $this->employee(502, ['is_main_employer' => false, 'other_employer' => 'Portal-Angabe GmbH']);

        $counts = $this->lauf(dryRun: false);

        $this->assertSame(0, $counts['set']);
        $this->assertFalse($ma->fresh()->is_main_employer, 'Die Portal-Antwort ist juenger als der Vertrag.');
        $this->assertSame('Portal-Angabe GmbH', $ma->fresh()->other_employer);
    }

    public function test_ohne_unterschriebene_erklaerung_passiert_nichts(): void
    {
        $ma = $this->employee(503);

        $counts = $this->lauf(dryRun: false);

        $this->assertSame(0, $counts['set']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertNull($ma->fresh()->is_main_employer);
    }

    public function test_kein_zas_export_marker(): void
    {
        $this->signedContract(504, [EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN]);
        $ma = $this->employee(504);
        Capsule::table('rec_employees')->where('id', $ma->id)->update(['zas_changed_at' => null]);

        $this->lauf(dryRun: false);

        $this->assertNull(
            $ma->fresh()->zas_changed_at,
            'Sobald die Spalten exportiert werden, spuelte ein Eloquent-Lauf den halben Bestand in updates.csv.',
        );
    }

    public function test_trockenlauf_schreibt_nichts(): void
    {
        $this->signedContract(505, [EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN]);
        $ma = $this->employee(505);

        $counts = $this->lauf(dryRun: true);

        $this->assertSame(1, $counts['set'], 'Der Trockenlauf zaehlt, was er getan haette.');
        $this->assertNull($ma->fresh()->is_main_employer);
    }

    public function test_zweiter_lauf_findet_nichts_mehr(): void
    {
        $this->signedContract(506, [EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN]);
        $this->employee(506);

        $this->lauf(dryRun: false);
        $zweiter = $this->lauf(dryRun: false);

        $this->assertSame(0, $zweiter['set'], 'Der Lauf muss beliebig oft wiederholbar sein.');
    }

    public function test_der_team_filter_greift(): void
    {
        $this->signedContract(507, [EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN]);
        $this->signedContract(508, [EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN]);
        $eigen = $this->employee(507);
        $fremd = $this->employee(508, ['team_id' => 999]);

        $this->lauf(dryRun: false, team: self::TEAM);

        $this->assertTrue($eigen->fresh()->is_main_employer);
        $this->assertNull($fremd->fresh()->is_main_employer);
    }
}
