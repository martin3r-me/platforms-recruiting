<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\BackfillEmployeeCompany;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Bestands-Nachtrag der Firma (RG/MA) fuer die MA, die vor dem Fix in
 * CreateEmployeeFromApplicantService ohne den Wert angelegt wurden — 24 Stueck,
 * davon 23 die Moenchengladbacher Schulung vom 09.09.2026, deren Filiale in ZAS
 * deshalb auf DUS stand (Kundenmeldung 10.09.2026).
 *
 * DREI ENTSCHEIDUNGEN, die dieser Test festnagelt:
 *
 * 1. NUR EIGENE ANLAGEN. Zeilen mit rec_zas_inbound_file_id stammen aus einer
 *    ZAS-Lieferung; dort gehoert das Feld ZAS, und der Inbound traegt es beim
 *    naechsten Lauf selbst nach (companyFill). Sie hier mitzunehmen wuerde den
 *    Vorfall vom 02.09.2026 wiederholen: der Telefon-Lauf spuelte ~500
 *    ZAS-Bestandsmitarbeiter in den Update-Export, der VOLLE Zeilen liefert und
 *    in ZAS gepflegte Akten ueberschreibt.
 *
 * 2. DER MARKER IST HIER GEWOLLT — anders als beim Telefon-Lauf, der bewusst
 *    observer-frei schreibt. Eine fehlende Firma ist keine Schreibweise,
 *    sondern eine fachliche Angabe, die ZAS fuer die Filiale braucht. Der
 *    Nachtrag muss also per Eloquent laufen, damit die 24 in die naechste
 *    updates.csv kommen. Ohne diese Zeile bliebe die Korrektur bei uns liegen
 *    und die Filialen in ZAS blieben falsch.
 *
 * 3. NIE UEBERSCHREIBEN, und der Praefix der Personalnummer gewinnt vor der
 *    Vorgabe — dieselbe Regel, mit der auch der Inbound arbeitet. Sonst wuerde
 *    aus einer MA-Person eine RG-Person.
 */
class BackfillEmployeeCompanyTest extends TestCase
{
    private const TEAM = 3;
    private const TEAM_FREMD = 4;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['company_prefix' => 'RG']],
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
        Model::clearBootedModels();

        Capsule::schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->integer('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('personnel_number')->nullable();
            $t->string('company')->nullable();
            $t->integer('rec_applicant_id')->nullable();
            $t->integer('rec_zas_inbound_file_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('zas_changed_at')->nullable();
            $t->dateTime('zas_initial_exported_at')->nullable();
            $t->timestamps();
        });

        // Der Observer laeuft auf jedem MA-Update auch durch das
        // Lohn-Tracking, und das liest die Team-Einstellungen. Ohne die
        // Tabelle wuerde der Lauf in den stillen Fehlerzweig von safelyRun
        // fallen — der Test wuerde also den Marker pruefen, ohne den
        // Produktionspfad gelaufen zu sein.
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
    }

    public function testLeereFirmaBekommtDieVorgabeUndLandetImUpdateExport(): void
    {
        $ma = $this->employee('Santos', ['company' => null]);

        $counts = $this->lauf(dryRun: false);

        $this->assertSame('RG', $ma->fresh()->company);
        $this->assertSame(1, $counts['filled']);
        $this->assertNotNull(
            $ma->fresh()->zas_changed_at,
            'Der Nachtrag muss den ZAS-Update-Marker setzen, sonst erreicht die Firma ZAS nie.'
        );
    }

    public function testGesetzteFirmaBleibtUnberuehrt(): void
    {
        $ma = $this->employee('Waechter', ['company' => 'MA']);

        $counts = $this->lauf(dryRun: false);

        $this->assertSame('MA', $ma->fresh()->company);
        $this->assertSame(0, $counts['filled']);
        $this->assertNull($ma->fresh()->zas_changed_at, 'Ohne Aenderung darf kein Update-Marker entstehen.');
    }

    public function testDerPraefixDerPersonalnummerGewinntVorDerVorgabe(): void
    {
        $ma = $this->employee('Kurzform', ['company' => '', 'personnel_number' => 'MA1000000878']);

        $this->lauf(dryRun: false);

        $this->assertSame('MA', $ma->fresh()->company, 'Aus einer MA-Person darf nie eine RG-Person werden.');
    }

    public function testZasAnlagenBleibenUnberuehrt(): void
    {
        $ma = $this->employee('AusZasGeliefert', ['company' => null, 'rec_zas_inbound_file_id' => 12]);

        $counts = $this->lauf(dryRun: false);

        $this->assertNull($ma->fresh()->company);
        $this->assertSame(0, $counts['filled']);
        $this->assertSame(1, $counts['skipped_zas']);
        $this->assertNull(
            $ma->fresh()->zas_changed_at,
            'Sonst landen ZAS-Bestandsakten im Update-Export — der Vorfall vom 02.09.2026.'
        );
    }

    public function testTrockenlaufSchreibtNichts(): void
    {
        $ma = $this->employee('Santos', ['company' => null]);

        $counts = $this->lauf(dryRun: true);

        $this->assertNull($ma->fresh()->company);
        $this->assertNull($ma->fresh()->zas_changed_at);
        $this->assertSame(1, $counts['filled'], 'Der Trockenlauf zaehlt, was er getan haette.');
    }

    public function testDerTeamFilterGreift(): void
    {
        $eigen = $this->employee('Eigen', ['company' => null]);
        $fremd = $this->employee('Fremd', ['company' => null, 'team_id' => self::TEAM_FREMD]);

        $this->lauf(dryRun: false, teamId: self::TEAM);

        $this->assertSame('RG', $eigen->fresh()->company);
        $this->assertNull($fremd->fresh()->company);
    }

    public function testZweiterLaufFindetNichtsMehr(): void
    {
        $this->employee('Santos', ['company' => null]);

        $this->lauf(dryRun: false);
        $zweiter = $this->lauf(dryRun: false);

        $this->assertSame(0, $zweiter['filled'], 'Der Lauf muss beliebig oft wiederholbar sein.');
    }

    /** @return array{total:int, filled:int, skipped_zas:int} */
    private function lauf(bool $dryRun, ?int $teamId = null, ?int $employeeId = null): array
    {
        return (new BackfillEmployeeCompany())
            ->backfill($dryRun, $teamId, $employeeId, fn ($type, $text) => null);
    }

    private function employee(string $name, array $overrides = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'first_name'       => 'Test',
            'last_name'        => $name,
            'portal_token'     => 'tok-' . $name . '-' . uniqid(),
            'rec_applicant_id' => 42,
            'is_active'        => true,
        ], $overrides));
    }
}
