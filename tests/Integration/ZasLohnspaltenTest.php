<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;

/**
 * LOHNSPALTEN IM EXPORT: MA liefert EG 1 + Angleich, Spec 2026-10-19.
 *
 * Grundlohn = minimum_wage_hourly und Zuschlag aus der Bewerbung sind nur
 * fuer RG richtig. MA-Grundlohn = Entgeltgruppe 1 (datiert), MA-Zuschlag =
 * Angleich an den RG-Lohn der Person (+1 EUR bei Steuerklasse 6). RG
 * unveraendert; RG-Bestand ohne Bewerbung liest ZAS-Wert bzw. zuschlag_manuell.
 * Die Spalten selbst bleiben an ihrem Platz (ZAS parst ggf. positionsbasiert).
 */
final class ZasLohnspaltenTest extends TestCase
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
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        // Echte Migration fuer die Grundtabelle; company/tax_class kommen in
        // Produktion aus anderen (schweren) Migrationen, hier minimal nachgezogen.
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php',
            'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php',
            'database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php',
        ] as $relative) {
            (require $own . '/' . $relative)->up();
        }
        $this->capsule->schema()->table('rec_employees', function ($t) {
            $t->string('company', 8)->nullable();
            $t->string('tax_class', 1)->nullable();
        });
        (require $own . '/database/migrations/2026_10_19_000002_add_zuschlag_manuell_to_rec_employees.php')->up();
        $this->capsule->schema()->create('rec_employee_hr_data', function ($t) {
            $t->increments('id');
            $t->integer('rec_employee_id')->unique();
            $t->decimal('zas_grundlohn', 6, 2)->nullable();
            $t->decimal('zas_zuschlag', 6, 2)->nullable();
        });
        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->integer('team_id');
            $t->integer('merged_into_person_id')->nullable();
        });

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

    /** Hilfe: Zeile anlegen, Instanz laden. */
    private function zeile(array $set): RecEmployee
    {
        static $nr = 1000;
        $nr++;
        Capsule::table('rec_employees')->insert(array_merge([
            'id' => $nr, 'team_id' => self::TEAM, 'uuid' => 'u-' . $nr, 'first_name' => 'A', 'last_name' => 'B',
            'is_active' => 1, 'company' => 'RG', 'rec_applicant_id' => null, 'rec_person_id' => null,
            'person_key' => null, 'tax_class' => ($set['company'] ?? 'RG') === 'RG' ? '1' : null, 'zuschlag_manuell' => null,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ], $set));

        return RecEmployee::query()->findOrFail($nr);
    }

    /** Hilfe: ZAS-Lohnwerte der Anstellung (hr_data-Zeile). */
    private function zasLohn(int $employeeId, ?float $grundlohn, ?float $zuschlag): void
    {
        Capsule::table('rec_employee_hr_data')->insert([
            'rec_employee_id' => $employeeId, 'zas_grundlohn' => $grundlohn, 'zas_zuschlag' => $zuschlag,
        ]);
    }

    public function test_ma_fall_1_angleich_ueber_person(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 9, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 9]);          // 13,90 + 1,10 = 15,00
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 9, 'tax_class' => '6']);

        $r = $this->resolver();
        $this->assertSame(\Platform\Recruiting\Support\LohnMa::zahl(\Platform\Recruiting\Support\EntgeltgruppeEins::satz(now()->toDateString())), $r->col($ma, 'Grundlohn'));
        $this->assertSame('1,00', $r->col($ma, 'Zuschlag'), 'Angleich 0 (15,00 < 15,33) + 1 EUR Klasse 6');
    }

    /** Review Focus 3: nur person_key, keine Personen-Zeile. */
    public function test_ma_fall_1_ueber_person_key(): void
    {
        // Volle Nummer: erst sie bestaetigt die Kennung (PersonProofScope). Mit
        // '+491511' lief der Test bisher still als "nur MA" durch (auch 0,00).
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'person_key' => 'k-1', 'phone' => '+4915112345678', 'tax_class' => '6']);
        $ma = $this->zeile(['company' => 'MA', 'person_key' => 'k-1', 'phone' => '+4915112345678', 'tax_class' => '1']);

        $this->assertSame('0,00', $this->resolver()->col($ma, 'Zuschlag'));
        $this->assertSame(\Platform\Recruiting\Support\LohnMa::ANGLEICH, (new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma)->fall);
    }

    /** Ruling R4 Fall A: gleiche Kennung, aber die RG-Zeile gehoert einer anderen Person — kein geratenes 0,00, sondern leer. */
    public function test_ma_gleiche_kennung_andere_person_ist_unbekannt(): void
    {
        Capsule::table('rec_persons')->insert([['id' => 21, 'team_id' => self::TEAM], ['id' => 22, 'team_id' => self::TEAM]]);
        $rg = $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 21, 'person_key' => 'k-21']);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 22, 'person_key' => 'k-21', 'tax_class' => '1']);

        $this->assertNull($this->resolver()->col($ma, 'Zuschlag'));
        $this->assertNull($this->resolver()->col($ma, 'Grundlohn'));
        $e = new \Platform\Recruiting\Services\LohnMaErmittlung();
        $l = $e->ermitteln($ma);
        $this->assertSame(\Platform\Recruiting\Support\LohnMa::UNBEKANNT, $l->fall);
        $this->assertStringContainsString('RG-Zuordnung unklar (gleiche Kennung, andere Person)', $l->begruendung);
        $this->assertSame((int) $rg->id, (int) $e->rgSchwester($ma)->id);
    }

    /** Gegenprobe Fall A: dieselbe Kennung in einem fremden Team zaehlt nicht — die Person bleibt "nur MA". */
    public function test_gleiche_kennung_im_fremden_team_bleibt_nur_ma(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 23, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'team_id' => self::TEAM + 1, 'rec_applicant_id' => 500, 'person_key' => 'k-23']);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 23, 'person_key' => 'k-23', 'tax_class' => '1']);

        $this->assertSame('0,00', $this->resolver()->col($ma, 'Zuschlag'));
    }

    /** Ruling R4 Fall B: weder Person noch Kennung — wir wissen nicht, ob es eine RG-Anstellung gibt. */
    public function test_ma_ohne_personen_zuordnung_ist_unbekannt(): void
    {
        $ma = $this->zeile(['company' => 'MA', 'tax_class' => '1']);

        $this->assertNull($this->resolver()->col($ma, 'Zuschlag'));
        $this->assertNull($this->resolver()->col($ma, 'Grundlohn'), 'Pre-Merge-Review I2');
        $this->assertSame('15,33', \Platform\Recruiting\Support\LohnMa::zahl((new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma, '2026-10-19')->grundlohn));
        $this->assertStringContainsString('keine Personen-Zuordnung', (new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma)->begruendung);
    }

    public function test_ma_fall_1b_zas_zuschlag_der_rg_schwester(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 13, 'team_id' => self::TEAM]);
        $rg = $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'rec_person_id' => 13]);
        $this->zasLohn($rg->id, 13.90, 2.50);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 13, 'tax_class' => '6']);

        $this->assertSame(\Platform\Recruiting\Support\LohnMa::zahl(round(13.90 + 2.50 - \Platform\Recruiting\Support\EntgeltgruppeEins::satz(now()->toDateString()) + 1.00, 2)), $this->resolver()->col($ma, 'Zuschlag'));
    }

    /** Markus 14.10.: der Euro nur bei RG 1-5 und MA 6; ist die RG-Klasse leer, bleibt die Zelle leer. */
    public function test_ma_6_rg_steuerklasse_leer_liefert_leeren_zuschlag(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 17, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 17, 'tax_class' => null]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 17, 'tax_class' => '6']);

        $this->assertNull($this->resolver()->col($ma, 'Zuschlag'), 'leere Zelle statt falschem Wert');
        $this->assertNull($this->resolver()->col($ma, 'Grundlohn'), 'Pre-Merge-Review I2: Grundlohn nur zusammen mit dem Zuschlag');
    }

    /** 20.10.2026: RG 1 und MA 1 = zwei Hauptarbeitgeber — beide Zellen leer, bis HR klaert. */
    public function test_beide_klasse_1_liefert_grundlohn_und_zuschlag_leer(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 18, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 18, 'tax_class' => '1']);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 18, 'tax_class' => '1']);

        $this->assertNull($this->resolver()->col($ma, 'Zuschlag'));
        $this->assertNull($this->resolver()->col($ma, 'Grundlohn'));
        $this->assertSame(\Platform\Recruiting\Support\LohnMa::STEUERKLASSE_FEHLT, (new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma)->fall);
    }

    /** RG 'P' (pauschal) und MA 6: Zuschlag berechnet, aber kein Klasse-6-Euro. */
    public function test_rg_pauschal_und_ma_6_ohne_euro(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 19, 'team_id' => self::TEAM]);
        $rg = $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'rec_person_id' => 19, 'tax_class' => 'P']);
        $this->zasLohn($rg->id, 13.90, 2.50);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 19, 'tax_class' => '6']);

        $this->assertSame(\Platform\Recruiting\Support\LohnMa::zahl(round(13.90 + 2.50 - \Platform\Recruiting\Support\EntgeltgruppeEins::satz(now()->toDateString()), 2)), $this->resolver()->col($ma, 'Zuschlag'));
        $this->assertNotNull($this->resolver()->col($ma, 'Grundlohn'));
    }

    /** ZAS-Grundlohn 0 = Zeile bei ZAS nicht gepflegt, der Zuschlag daneben ist keine Angabe. */
    public function test_zas_wert_mit_grundlohn_null_zaehlt_nicht(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 14, 'team_id' => self::TEAM]);
        $rg = $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'rec_person_id' => 14]);
        $this->zasLohn($rg->id, 0.0, 0.0);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 14, 'tax_class' => '6']);

        $this->assertNull($this->resolver()->col($ma, 'Zuschlag'));
    }

    /** Reihenfolge der Quellen: Bewerbung vor ZAS vor manuell. */
    public function test_bewerbung_schlaegt_zas_und_zas_schlaegt_manuell(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 15, 'team_id' => self::TEAM]);
        $rg = $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 15, 'zuschlag_manuell' => 9.99, 'tax_class' => '6']);  // Bewerbung 1,10
        $this->zasLohn($rg->id, 13.90, 5.00);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 15, 'tax_class' => '1']);
        $this->assertSame('0,00', $this->resolver()->col($ma, 'Zuschlag'), 'Bewerbung 15,00 < EG 1');

        Capsule::table('rec_persons')->insert(['id' => 16, 'team_id' => self::TEAM]);
        $rg2 = $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'rec_person_id' => 16, 'zuschlag_manuell' => 9.99, 'tax_class' => '6']);
        $this->zasLohn($rg2->id, 13.90, 2.50);
        $ma2 = $this->zeile(['company' => 'MA', 'rec_person_id' => 16, 'tax_class' => '1']);
        $this->assertSame(\Platform\Recruiting\Support\LohnMa::zahl(round(13.90 + 2.50 - \Platform\Recruiting\Support\EntgeltgruppeEins::satz(now()->toDateString()), 2)), $this->resolver()->col($ma2, 'Zuschlag'), 'ZAS vor manuell');
    }

    /** Pre-Merge-Review I2: ohne bekannten Zuschlag gehen BEIDE Zellen leer raus — ZAS behaelt sein Paar. */
    public function test_ma_fall_2_unbekannt_liefert_grundlohn_und_zuschlag_leer(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 10, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'rec_person_id' => 10]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 10, 'tax_class' => '6']);

        $r = $this->resolver();
        $this->assertNull($r->col($ma, 'Grundlohn'));
        $this->assertNull($r->col($ma, 'Zuschlag'));
    }

    public function test_ma_fall_3_manuell(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 11, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'rec_person_id' => 11, 'zuschlag_manuell' => 2.50]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 11, 'tax_class' => '6']);

        $this->assertSame(\Platform\Recruiting\Support\LohnMa::zahl(round(13.90 + 2.50 - \Platform\Recruiting\Support\EntgeltgruppeEins::satz(now()->toDateString()) + 1.00, 2)), $this->resolver()->col($ma, 'Zuschlag'));
    }

    public function test_ma_fall_4_nur_ma(): void
    {
        // Echte nur-MA-Person: Person bekannt, keine RG-Anstellung, keine fremde Kennung (Ruling R4).
        Capsule::table('rec_persons')->insert(['id' => 24, 'team_id' => self::TEAM]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 24, 'tax_class' => '1']);

        $this->assertSame('0,00', $this->resolver()->col($ma, 'Zuschlag'));
    }

    /** Review Focus 2: ausgeschiedene RG-Schwester zaehlt nicht. */
    public function test_inaktive_rg_schwester_ist_nur_ma(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 12, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'rec_person_id' => 12, 'is_active' => 0]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 12, 'tax_class' => '1']);

        $this->assertSame('0,00', $this->resolver()->col($ma, 'Zuschlag'), 'nicht leer (unbekannt), sondern nur MA');
    }

    public function test_rg_ohne_bewerbung_liefert_zas_oder_manuellen_zuschlag(): void
    {
        $zas = $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'zuschlag_manuell' => 1.10]);
        $this->zasLohn($zas->id, 13.90, 2.10);
        $manuell = $this->zeile(['company' => 'RG', 'rec_applicant_id' => null, 'zuschlag_manuell' => 1.10]);
        $ohne = $this->zeile(['company' => 'RG', 'rec_applicant_id' => null]);

        $this->assertSame('2,10', $this->resolver()->col($zas, 'Zuschlag'), 'ZAS vor manuell');
        $this->assertSame('1,10', $this->resolver()->col($manuell, 'Zuschlag'));
        $this->assertNull($this->resolver()->col($ohne, 'Zuschlag'));
    }

    /** Bewerbung gewinnt — eine Wahrheit je Person. */
    public function test_rg_mit_bewerbung_ignoriert_zuschlag_manuell(): void
    {
        $rg = $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'zuschlag_manuell' => 9.99]);

        $this->assertSame('1,10', $this->resolver()->col($rg, 'Zuschlag'));
    }
    public function test_rg_anstellung_liefert_mindestlohn_und_zuschlag_wie_bisher(): void
    {
        $r = $this->resolver();

        $this->assertSame('13,90', $r->col($this->zeile(['company' => 'RG', 'rec_applicant_id' => 500]), 'Grundlohn'));
        $this->assertSame('1,10', $r->col($this->zeile(['company' => 'RG', 'rec_applicant_id' => 500]), 'Zuschlag'));
        $this->assertSame('0,60', $r->col($this->zeile(['company' => 'RG', 'rec_applicant_id' => 501]), 'Zuschlag'), 'Fallback AV-Code unveraendert');
    }

    /** Bestand ohne Firma ist RG — Verhalten wie bisher. */
    public function test_anstellung_ohne_firma_wie_rg(): void
    {
        $r = $this->resolver();

        $this->assertSame('13,90', $r->col($this->zeile(['company' => null, 'rec_applicant_id' => 500]), 'Grundlohn'));
        $this->assertSame('1,10', $r->col($this->zeile(['company' => null, 'rec_applicant_id' => 500]), 'Zuschlag'));
    }

    /** Spaltenzahl und -reihenfolge unveraendert — nur der Wert ist leer. */
    public function test_lohnspalten_bleiben_an_ihrem_platz(): void
    {
        $cols = ZasEmployeeFieldResolver::COLUMNS;
        $i = array_search('Grundlohn', $cols, true);

        $this->assertNotFalse($i);
        $this->assertSame(['SchulungsDatum', 'Grundlohn', 'Zuschlag', 'UUID'], array_slice($cols, $i - 1, 4));
    }

    /**
     * Ruling R3' (Final-Review F5): Bewerbung ohne Zuschlag und ohne lesbaren
     * AV-Code ist keine Angabe — der MA-Angleich nimmt den ZAS-Wert der
     * RG-Schwester. Der RG-Export mit Bewerbung bleibt dabei unveraendert leer.
     */
    public function test_bewerbung_ohne_wert_laeuft_weiter_zu_zas(): void
    {
        Capsule::table('rec_applicants')->insert(['id' => 502, 'zuschlag' => null, 'contract_template_id' => null]);
        Capsule::table('rec_persons')->insert(['id' => 31, 'team_id' => self::TEAM]);
        $rg = $this->zeile(['company' => 'RG', 'rec_applicant_id' => 502, 'rec_person_id' => 31, 'tax_class' => '6']);
        $this->zasLohn($rg->id, 13.90, 2.00);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 31, 'tax_class' => '1']);

        $tag = '2026-10-19';
        $l = (new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma, $tag);
        $this->assertSame(\Platform\Recruiting\Support\LohnMa::ZAS, $l->fall);
        $this->assertSame(round(13.90 + 2.00 - \Platform\Recruiting\Support\EntgeltgruppeEins::satz($tag), 2), $l->zuschlag);   // 0,57
        $this->assertNull($this->resolver()->col($rg, 'Zuschlag'), 'RG-Export mit Bewerbung unveraendert');
    }

    /** R3': Bewerbung ohne Wert, kein ZAS, nichts manuell — erst dann 0,00 aus der Bewerbung. */
    public function test_bewerbung_ohne_wert_ohne_zas_rechnet_mit_null(): void
    {
        Capsule::table('rec_applicants')->insert(['id' => 503, 'zuschlag' => null, 'contract_template_id' => null]);
        Capsule::table('rec_persons')->insert(['id' => 32, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 503, 'rec_person_id' => 32]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 32, 'tax_class' => '6']);

        $l = (new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma, '2026-10-19');
        $this->assertSame(\Platform\Recruiting\Support\LohnMa::ANGLEICH, $l->fall);
        $this->assertSame('1,00', $this->resolver()->col($ma, 'Zuschlag'), '13,90 + 0,00 liegt unter EG 1 -> 0,00 + Klasse 6');
    }

    /** R3': Bewerbung ohne Wert, kein ZAS, aber manuell eingetragen — der manuelle Wert gilt. */
    public function test_bewerbung_ohne_wert_nimmt_manuellen_zuschlag(): void
    {
        Capsule::table('rec_applicants')->insert(['id' => 504, 'zuschlag' => null, 'contract_template_id' => null]);
        Capsule::table('rec_persons')->insert(['id' => 33, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 504, 'rec_person_id' => 33, 'zuschlag_manuell' => 2.50, 'tax_class' => '6']);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 33, 'tax_class' => '1']);

        $this->assertSame(\Platform\Recruiting\Support\LohnMa::MANUELL, (new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma)->fall);
    }

    /**
     * Hotfix 21.10.2026, Fall 1568: ZAS fuehrt fuer die MA-Anstellung schon
     * 15,33 + 1,17; die Regel ergaebe 15,33 + 1,00 (RG 13,90 + 0,82 laut ZAS,
     * Klasse 6). Wir duerfen den Lohn nicht still senken: beide Zellen leer.
     */
    public function test_ma_mit_eigenem_zas_lohn_sendet_nichts(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 68, 'team_id' => self::TEAM]);
        $rg = $this->zeile(['company' => 'RG', 'rec_person_id' => 68, 'tax_class' => '1']);
        $this->zasLohn($rg->id, 13.90, 0.82);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 68, 'tax_class' => '6']);
        $this->zasLohn($ma->id, 15.33, 1.17);

        $r = $this->resolver();
        $this->assertNull($r->col($ma, 'Grundlohn'), 'Grundlohn leer: ZAS behaelt seinen');
        $this->assertNull($r->col($ma, 'Zuschlag'), 'Zuschlag leer: nie 1,00 statt 1,17');

        $l = (new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma);
        $this->assertSame(\Platform\Recruiting\Support\LohnMa::ZAS_BESTAND, $l->fall);
        $this->assertNotNull($l->regel);
        $this->assertSame(1.00, $l->regel->zuschlag, 'Rechnung nach der Regel bleibt zum Vergleich');
        $this->assertStringContainsString('1,17', $l->begruendung);
    }

    /** Hotfix 21.10.2026: auch "nur MA" (0,00) ueberschreibt keinen ZAS-Lohn. */
    public function test_nur_ma_mit_eigenem_zas_lohn_sendet_nichts(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 69, 'team_id' => self::TEAM]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 69, 'tax_class' => '1']);
        $this->zasLohn($ma->id, 15.33, 1.17);

        $this->assertNull($this->resolver()->col($ma, 'Zuschlag'));
        $this->assertNull($this->resolver()->col($ma, 'Grundlohn'));
    }

    /** Grundlohn 0 heisst bei ZAS "nicht gepflegt": dann gilt die Regel. */
    public function test_ma_zas_grundlohn_null_zaehlt_nicht_als_lohn(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 70, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 70]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 70, 'tax_class' => '6']);
        $this->zasLohn($ma->id, 0.0, 1.17);

        $this->assertSame('1,00', $this->resolver()->col($ma, 'Zuschlag'));
    }

    /** Neue MA ohne ZAS-Lohn (keine hr_data-Zeile): die Regel liefert. */
    public function test_neue_ma_ohne_zas_lohn_bekommt_die_regel(): void
    {
        Capsule::table('rec_persons')->insert(['id' => 71, 'team_id' => self::TEAM]);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 71]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 71, 'tax_class' => '6']);

        $this->assertSame('1,00', $this->resolver()->col($ma, 'Zuschlag'));
        $this->assertNotNull($this->resolver()->col($ma, 'Grundlohn'));
    }

    /** Ein Leer-Fall bleibt sein Leer-Fall, auch wenn ZAS einen Lohn fuehrt. */
    public function test_unbekannt_bleibt_unbekannt_trotz_zas_lohn(): void
    {
        $ma = $this->zeile(['company' => 'MA', 'tax_class' => '6']);
        $this->zasLohn($ma->id, 15.33, 1.17);

        $this->assertSame(\Platform\Recruiting\Support\LohnMa::UNBEKANNT, (new \Platform\Recruiting\Services\LohnMaErmittlung())->ermitteln($ma)->fall);
    }
}
