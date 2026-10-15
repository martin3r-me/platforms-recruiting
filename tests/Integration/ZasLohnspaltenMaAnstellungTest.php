<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;

/**
 * LOHNSPALTEN IM MA-EXPORT (15.10.2026).
 *
 * Grundlohn = minimum_wage_hourly und Zuschlag aus der Bewerbung sind nur
 * fuer RG richtig. Markus 14.10.2026 (E14/E15): MA-Grundlohn = Entgeltgruppe 1
 * aus dem Tarif, Zuschlag nach eigener Regel — bis die gebaut ist, liefern
 * MA-Anstellungen beide Zellen LEER. RG unveraendert. Die Spalten selbst
 * bleiben an ihrem Platz (ZAS parst ggf. positionsbasiert).
 */
final class ZasLohnspaltenMaAnstellungTest extends TestCase
{
    private const TEAM = 7;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $container->instance('db', $this->capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->capsule->schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });
        $this->capsule->schema()->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->decimal('zuschlag', 5, 2)->nullable();
            $t->integer('contract_template_id')->nullable();
        });
        $this->capsule->schema()->create('rec_contract_templates', function ($t) {
            $t->increments('id');
            $t->string('code')->nullable();
        });

        Capsule::table('rec_applicant_settings')->insert([
            'team_id' => self::TEAM, 'settings' => json_encode(['minimum_wage_hourly' => 13.90]),
        ]);
        Capsule::table('rec_contract_templates')->insert(['id' => 60, 'code' => 'AV-060']);
        Capsule::table('rec_applicants')->insert([
            ['id' => 500, 'zuschlag' => 1.10, 'contract_template_id' => null],
            ['id' => 501, 'zuschlag' => null, 'contract_template_id' => 60],
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        $this->capsule->schema()->dropAllTables();
        parent::tearDown();
    }

    private function resolver(): object
    {
        return new class extends ZasEmployeeFieldResolver {
            public function __construct() {}
            public function col(RecEmployee $e, string $column): ?string
            {
                return $this->resolveColumn($e, null, $column);
            }
        };
    }

    private function anstellung(?string $company, ?int $applicantId): RecEmployee
    {
        $e = new RecEmployee();
        $e->setRawAttributes(['id' => 1, 'team_id' => self::TEAM, 'company' => $company, 'rec_applicant_id' => $applicantId]);

        return $e;
    }

    public function test_rg_anstellung_liefert_mindestlohn_und_zuschlag_wie_bisher(): void
    {
        $r = $this->resolver();

        $this->assertSame('13,90', $r->col($this->anstellung('RG', 500), 'Grundlohn'));
        $this->assertSame('1,10', $r->col($this->anstellung('RG', 500), 'Zuschlag'));
        $this->assertSame('0,60', $r->col($this->anstellung('RG', 501), 'Zuschlag'), 'Fallback AV-Code unveraendert');
    }

    /** Bestand ohne Firma ist RG — Verhalten wie bisher. */
    public function test_anstellung_ohne_firma_wie_rg(): void
    {
        $r = $this->resolver();

        $this->assertSame('13,90', $r->col($this->anstellung(null, 500), 'Grundlohn'));
        $this->assertSame('1,10', $r->col($this->anstellung(null, 500), 'Zuschlag'));
    }

    public function test_ma_anstellung_liefert_grundlohn_und_zuschlag_leer(): void
    {
        $r = $this->resolver();

        // Mit Bewerbung (Zuschlag-Feld UND AV-Code) — beides darf nicht durchschlagen.
        $this->assertNull($r->col($this->anstellung('MA', 500), 'Grundlohn'));
        $this->assertNull($r->col($this->anstellung('MA', 500), 'Zuschlag'));
        $this->assertNull($r->col($this->anstellung('MA', 501), 'Zuschlag'));
        // Schreibweise wie in VertragsPruefung::firmaVon() normalisiert.
        $this->assertNull($r->col($this->anstellung(' ma ', 500), 'Grundlohn'));
    }

    /** Spaltenzahl und -reihenfolge unveraendert — nur der Wert ist leer. */
    public function test_lohnspalten_bleiben_an_ihrem_platz(): void
    {
        $cols = ZasEmployeeFieldResolver::COLUMNS;
        $i = array_search('Grundlohn', $cols, true);

        $this->assertNotFalse($i);
        $this->assertSame(['SchulungsDatum', 'Grundlohn', 'Zuschlag', 'UUID'], array_slice($cols, $i - 1, 4));
    }
}
