<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\BackfillNationality;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Bestands-Backfill der Staatsangehoerigkeit — Stufe A des Nation-Fixes.
 *
 * Die eine Entscheidung, die dieser Test festnagelt: DER MARKER BLEIBT
 * UNBERUEHRT. Der Backfill schreibt am Observer vorbei, `zas_changed_at`
 * darf nicht gesetzt werden. Sonst landen rund 1.300 Datensaetze in der
 * naechsten updates.csv — der Vorfall vom 02.09.2026 in Neuauflage. Die
 * Nachlieferung korrigierter Werte an ZAS ist Stufe B und passiert nur
 * angekuendigt und abgestimmt.
 */
class BackfillNationalityCommandTest extends TestCase
{
    private const TEAM = 3;

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
            $t->integer('rec_applicant_id')->nullable();
            $t->integer('rec_zas_inbound_file_id')->nullable();
            $t->string('birth_country')->nullable();
            $t->string('nationality')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('zas_changed_at')->nullable();
            $t->timestamps();
        });
        Capsule::schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        // Der Observer ist registriert, damit der Test beweist, dass der
        // Backfill ihn NICHT ausloest — nicht nur, dass er fehlt.
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

    public function testImportMaZiehtStaatsangehoerigkeitAusGeburtslandUmOhneExportMarker(): void
    {
        $ma = $this->employee(['rec_zas_inbound_file_id' => 9, 'birth_country' => 'de']);

        $counts = $this->lauf(dryRun: false);

        $fresh = $ma->fresh();
        $this->assertSame('de', $fresh->nationality);
        $this->assertNull($fresh->birth_country, 'Geburtsland kam nie von ZAS und muss leer werden');
        $this->assertNull($fresh->zas_changed_at, 'Backfill darf NICHT fuer den Update-Export markieren');
        $this->assertSame(1, $counts['set']);
    }

    public function testFunnelMaBekommtStaatsangehoerigkeitAusBewerberUndBehaeltGeburtsland(): void
    {
        $ma = $this->employee(['rec_applicant_id' => 501, 'birth_country' => 'tr']);

        $this->lauf(dryRun: false, applicantNationality: fn (int $id) => $id === 501 ? 'bd' : null);

        $fresh = $ma->fresh();
        $this->assertSame('bd', $fresh->nationality);
        $this->assertSame('tr', $fresh->birth_country, 'echtes Geburtsland bleibt');
        $this->assertNull($fresh->zas_changed_at);
    }

    public function testVorhandeneStaatsangehoerigkeitBleibtUnangetastet(): void
    {
        $ma = $this->employee(['rec_zas_inbound_file_id' => 9, 'birth_country' => 'de', 'nationality' => 'bd']);

        $counts = $this->lauf(dryRun: false);

        $this->assertSame('bd', $ma->fresh()->nationality);
        $this->assertSame('de', $ma->fresh()->birth_country);
        $this->assertSame(0, $counts['set']);
    }

    public function testDryRunSchreibtNichts(): void
    {
        $ma = $this->employee(['rec_zas_inbound_file_id' => 9, 'birth_country' => 'de']);

        $counts = $this->lauf(dryRun: true);

        $this->assertNull($ma->fresh()->nationality);
        $this->assertSame('de', $ma->fresh()->birth_country);
        $this->assertSame(1, $counts['set'], 'Dry-Run zaehlt, was geschrieben wuerde');
    }

    public function testFunnelMaOhneBewerberwertBleibtLiegen(): void
    {
        $ma = $this->employee(['rec_applicant_id' => 502, 'birth_country' => 'tr']);

        $counts = $this->lauf(dryRun: false, applicantNationality: fn (int $id) => null);

        $this->assertNull($ma->fresh()->nationality);
        $this->assertSame('tr', $ma->fresh()->birth_country);
        $this->assertSame(0, $counts['set']);
        $this->assertSame(1, $counts['skipped']);
    }

    // ------------------------------------------------------------------

    private function employee(array $attrs): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'    => self::TEAM,
            'first_name' => 'Test',
            'last_name'  => 'Person',
            'is_active'  => true,
        ], $attrs));
    }

    private function lauf(bool $dryRun, ?callable $applicantNationality = null): array
    {
        return (new BackfillNationality())->backfill(
            $dryRun,
            null,
            null,
            function (string $type, string $text): void {},
            $applicantNationality ?? fn (int $applicantId) => null,
        );
    }
}
