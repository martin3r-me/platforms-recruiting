<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\SignedDayBudget;

/**
 * "Tage erlaubt" aus der unterschriebenen §15-Erklaerung (Markus 24.09.2026).
 *
 * Gewinner ist der juengste Arbeitsvertrag, der wirklich eine §15-Erklaerung
 * traegt — die Auswahlregeln stehen in SignedContractDeclarations.
 *
 * Der Unterschied zur Arbeitgeber-Erklaerung: hier kann das Ergebnis 0 sein
 * (Grenze ausgeschoepft), und 0 ist etwas voellig anderes als null (keine
 * Grundlage). ZAS zaehlt von diesem Wert herunter.
 */
class SignedDayBudgetTest extends TestCase
{
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

        Capsule::schema()->create('rec_contract_templates', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('name')->nullable();
            $t->string('code', 20)->nullable();
            $t->integer('team_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Capsule::schema()->create('rec_contracts', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('rec_contract_template_id');
            $t->integer('team_id')->nullable();
            $t->string('status', 30)->default('pending');
            $t->text('pre_signing_data')->nullable();
            $t->dateTime('signed_at')->nullable();
            $t->timestamps();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_contracts')->delete();
        Capsule::table('rec_contract_templates')->delete();

        Capsule::table('rec_contract_templates')->insert([
            ['id' => 1, 'code' => 'AV-default', 'name' => 'Arbeitsvertrag', 'team_id' => 1],
            ['id' => 2, 'code' => 'IFSG',       'name' => 'Infektionsschutz', 'team_id' => 1],
            ['id' => 3, 'code' => 'AV-ZERT',    'name' => 'AV mit Zertifikat', 'team_id' => 1],
        ]);
    }

    private const GRENZE = 70;

    private function contract(array $overrides = []): int
    {
        return Capsule::table('rec_contracts')->insertGetId(array_merge([
            'uuid'                     => uniqid('c', true),
            'rec_applicant_id'         => 100,
            'rec_contract_template_id' => 1,
            'team_id'                  => 1,
            'status'                   => 'completed',
            'signed_at'                => '2026-09-25 10:00:00',
            'pre_signing_data'         => json_encode([
                'par15_has_previous' => true,
                'par15_entries'      => [['tage' => 20]],
            ]),
        ], $overrides));
    }

    public function test_beispiel_aus_markus_mail(): void
    {
        $this->contract();

        $this->assertSame(50, SignedDayBudget::forApplicant(100, self::GRENZE));
    }

    public function test_ausdrueckliches_nein_ergibt_die_volle_grenze(): void
    {
        $this->contract(['pre_signing_data' => json_encode([
            'par15_has_previous' => false,
            'par15_entries'      => [],
        ])]);

        $this->assertSame(70, SignedDayBudget::forApplicant(100, self::GRENZE));
    }

    public function test_ohne_vertrag_gibt_es_keinen_startwert(): void
    {
        $this->assertNull(SignedDayBudget::forApplicant(100, self::GRENZE));
        $this->assertNull(SignedDayBudget::forApplicant(null, self::GRENZE));
    }

    public function test_nicht_unterschriebener_vertrag_zaehlt_nicht(): void
    {
        $this->contract(['signed_at' => null, 'status' => 'sent']);

        $this->assertNull(SignedDayBudget::forApplicant(100, self::GRENZE));
    }

    public function test_kein_arbeitsvertrag_zaehlt_nicht(): void
    {
        $this->contract(['rec_contract_template_id' => 2]);

        $this->assertNull(SignedDayBudget::forApplicant(100, self::GRENZE));
    }

    public function test_der_juengste_vertrag_gewinnt(): void
    {
        $this->contract(['signed_at' => '2026-03-01 09:00:00']);
        $this->contract([
            'signed_at'        => '2026-09-25 10:00:00',
            'pre_signing_data' => json_encode([
                'par15_has_previous' => true,
                'par15_entries'      => [['tage' => 5]],
            ]),
        ]);

        $this->assertSame(65, SignedDayBudget::forApplicant(100, self::GRENZE));
    }

    /**
     * Ein juengerer Vertrag ohne §15-Erklaerung darf eine vorhandene nicht
     * verdecken — UpdateContractTool kann signed_at ohne pre_signing_data
     * setzen.
     */
    public function test_juengerer_vertrag_ohne_erklaerung_verdeckt_nicht(): void
    {
        $this->contract(['signed_at' => '2026-03-01 09:00:00']);
        $this->contract(['signed_at' => '2026-09-25 10:00:00', 'pre_signing_data' => null]);

        $this->assertSame(50, SignedDayBudget::forApplicant(100, self::GRENZE));
    }

    public function test_altvertrag_ohne_paragraf_15_liefert_nichts(): void
    {
        $this->contract(['pre_signing_data' => json_encode(['par16_was_jobseeking' => false])]);

        $this->assertNull(SignedDayBudget::forApplicant(100, self::GRENZE));
    }
}
