<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EmployerDeclaration;
use Platform\Recruiting\Support\SignedEmployerDeclaration;

/**
 * Die Arbeitgeber-Erklaerung wird beim Unterschreiben des Arbeitsvertrags
 * erfasst. Der Mitarbeiter entsteht aber erst beim Abschluss der
 * Schulungsphase — je nach Reihenfolge existiert er zum Zeitpunkt der
 * Unterschrift also noch nicht.
 *
 * Diese Klasse ist der Nachleseweg: bei der MA-Anlage wird die Erklaerung
 * aus dem unterschriebenen Vertrag geholt.
 *
 * VIER ENTSCHEIDUNGEN, die hier festgenagelt werden:
 *  1. Nur UNTERSCHRIEBENE Vertraege zaehlen — ein verschickter, aber nicht
 *     unterzeichneter Vertrag ist keine Erklaerung.
 *  2. Nur ARBEITSVERTRAEGE (AV*) — die Erklaerung wird nur dort abgefragt.
 *  3. Bei mehreren gewinnt der JUENGSTE — nach einem Vertragsneuausstellen
 *     gilt die neue Erklaerung.
 *  4. Stornierte Vertraege zaehlen NICHT.
 */
class SignedEmployerDeclarationTest extends TestCase
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
                'par15_has_previous' => false,
                EmployerDeclaration::KEY_ROLE  => EmployerDeclaration::ROLE_SECONDARY,
                EmployerDeclaration::KEY_OTHER => 'Musterkantine GmbH',
            ]),
        ], $overrides));
    }

    public function test_unterschriebener_arbeitsvertrag_liefert_die_erklaerung(): void
    {
        $this->contract();

        $this->assertSame(
            ['is_main_employer' => false, 'other_employer' => 'Musterkantine GmbH'],
            SignedEmployerDeclaration::forApplicant(100),
        );
    }

    public function test_ohne_bewerber_id_nichts(): void
    {
        $this->assertSame([], SignedEmployerDeclaration::forApplicant(null));
        $this->assertSame([], SignedEmployerDeclaration::forApplicant(0));
    }

    public function test_fremder_bewerber_bekommt_nichts(): void
    {
        $this->contract();

        $this->assertSame([], SignedEmployerDeclaration::forApplicant(999));
    }

    public function test_nicht_unterschriebener_vertrag_zaehlt_nicht(): void
    {
        $this->contract(['signed_at' => null, 'status' => 'sent']);

        $this->assertSame([], SignedEmployerDeclaration::forApplicant(100));
    }

    public function test_kein_arbeitsvertrag_zaehlt_nicht(): void
    {
        $this->contract(['rec_contract_template_id' => 2]);

        $this->assertSame([], SignedEmployerDeclaration::forApplicant(100));
    }

    public function test_stornierter_vertrag_zaehlt_nicht(): void
    {
        $this->contract(['status' => 'cancelled']);

        $this->assertSame([], SignedEmployerDeclaration::forApplicant(100));
    }

    public function test_der_juengste_arbeitsvertrag_gewinnt(): void
    {
        $this->contract(['signed_at' => '2026-03-01 09:00:00']);
        $this->contract([
            'signed_at'        => '2026-09-25 10:00:00',
            'pre_signing_data' => json_encode([
                EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN,
            ]),
        ]);

        $this->assertSame(
            ['is_main_employer' => true, 'other_employer' => null],
            SignedEmployerDeclaration::forApplicant(100),
            'Nach einem Vertragsneuausstellen gilt die neue Erklaerung.',
        );
    }

    /**
     * Ein juengerer unterschriebener Vertrag OHNE Erklaerung darf eine
     * vorhandene nicht verdecken (Befund Review 25.09.2026).
     *
     * Der Fall ist real: UpdateContractTool kann einen Vertrag als
     * unterschrieben markieren, ohne pre_signing_data zu setzen. Wuerde
     * blind der juengste gewaehlt, kaeme der Mitarbeiter ohne Angabe an,
     * obwohl sie unterschrieben vorliegt.
     */
    public function test_juengerer_vertrag_ohne_erklaerung_verdeckt_die_vorhandene_nicht(): void
    {
        $this->contract(['signed_at' => '2026-03-01 09:00:00']);
        $this->contract(['signed_at' => '2026-09-25 10:00:00', 'pre_signing_data' => null]);

        $this->assertSame(
            ['is_main_employer' => false, 'other_employer' => 'Musterkantine GmbH'],
            SignedEmployerDeclaration::forApplicant(100),
        );
    }

    public function test_juengerer_vertrag_nur_mit_paragraf_daten_verdeckt_ebenfalls_nicht(): void
    {
        $this->contract(['signed_at' => '2026-03-01 09:00:00']);
        $this->contract([
            'signed_at'        => '2026-09-25 10:00:00',
            'pre_signing_data' => json_encode(['par15_has_previous' => false]),
        ]);

        $this->assertSame(
            ['is_main_employer' => false, 'other_employer' => 'Musterkantine GmbH'],
            SignedEmployerDeclaration::forApplicant(100),
        );
    }

    public function test_auch_die_zertifikats_variante_zaehlt(): void
    {
        $this->contract(['rec_contract_template_id' => 3]);

        $this->assertSame(
            ['is_main_employer' => false, 'other_employer' => 'Musterkantine GmbH'],
            SignedEmployerDeclaration::forApplicant(100),
        );
    }

    /**
     * Altvertraege aus der Zeit vor dieser Erweiterung tragen nur §15/§16.
     * Sie duerfen am Mitarbeiter nichts setzen.
     */
    public function test_altvertrag_ohne_erklaerung_liefert_nichts(): void
    {
        $this->contract(['pre_signing_data' => json_encode([
            'par15_has_previous'   => true,
            'par16_was_jobseeking' => false,
        ])]);

        $this->assertSame([], SignedEmployerDeclaration::forApplicant(100));
    }

    public function test_vertrag_ganz_ohne_vorschalt_daten_liefert_nichts(): void
    {
        $this->contract(['pre_signing_data' => null]);

        $this->assertSame([], SignedEmployerDeclaration::forApplicant(100));
    }
}
