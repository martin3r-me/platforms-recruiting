<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Show;

/**
 * NACHWEIS-BLOCK IN DER MA-AKTE (Task 5, 24.09.2026).
 *
 * Reine Anzeige je Person — Show::nachweisUebersicht() setzt ProofReader::checklist()
 * (Pflicht + Bestand) mit dem Bestaetigungsstatus aus ProofReader::current()
 * zusammen. Die eigentliche Bestaetigung passiert NICHT hier, sondern
 * ausschliesslich in ProofInbox — dort wird der Schreibweg getestet
 * (ProofInboxTest). Hier geht es nur um die Lesezusammensetzung.
 */
final class EmployeeNachweisUebersichtTest extends TestCase
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

        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('team_id');
            $t->integer('rec_applicant_id')->nullable();
            $t->integer('rec_position_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('person_key', 36)->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_eu_citizen')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_first_aider')->nullable();
            $t->timestamps();
        });

        $this->capsule->schema()->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->uuid('uuid');
            $t->integer('team_id')->nullable();
            $t->integer('rec_employee_id');
            $t->string('person_key', 64)->nullable();
            $t->string('proof_type_code', 40);
            $t->integer('file_id')->nullable();
            $t->integer('file_back_id')->nullable();
            $t->date('valid_until')->nullable();
            $t->integer('version')->default(1);
            $t->timestamp('superseded_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->integer('confirmed_by_user_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->string('uploaded_via', 20)->default('employee');
            $t->integer('uploaded_by_user_id')->nullable();
            $t->timestamps();
        });

        Capsule::table('rec_employees')->insert([
            // Nicht-EU, damit Aufenthaltstitel/Arbeitsgenehmigung Pflicht sind.
            ['id' => 900, 'uuid' => 'remp-900', 'team_id' => self::TEAM, 'first_name' => 'Lydia', 'last_name' => 'Bontioti', 'is_eu_citizen' => false],
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        $this->capsule->schema()->dropAllTables();
        parent::tearDown();
    }

    private function component(int $employeeId): Show
    {
        $component = new Show();
        $component->employeeId = $employeeId;

        return $component;
    }

    public function test_zeigt_wartet_auf_bestaetigung_solange_niemand_bestaetigt_hat(): void
    {
        Capsule::table('rec_employee_proofs')->insert([
            'uuid' => 'p1', 'team_id' => self::TEAM, 'rec_employee_id' => 900,
            'proof_type_code' => 'aufenthaltstitel', 'valid_until' => '2027-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $zeile = collect($this->component(900)->nachweisUebersicht())->firstWhere('code', 'aufenthaltstitel');

        $this->assertNotNull($zeile);
        $this->assertTrue($zeile['needs_confirmation']);
        $this->assertNull($zeile['confirmed_at']);
    }

    public function test_zeigt_bestaetigungsdatum_nach_bestaetigung(): void
    {
        Capsule::table('rec_employee_proofs')->insert([
            'uuid' => 'p2', 'team_id' => self::TEAM, 'rec_employee_id' => 900,
            'proof_type_code' => 'arbeitsgenehmigung', 'valid_until' => '2027-01-01',
            'confirmed_by_user_id' => 42, 'confirmed_at' => '2026-09-24 10:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $zeile = collect($this->component(900)->nachweisUebersicht())->firstWhere('code', 'arbeitsgenehmigung');

        $this->assertNotNull($zeile['confirmed_at']);
    }

    public function test_arten_ohne_bestaetigungspflicht_tragen_kein_bestaetigungsfeld_befuellt(): void
    {
        Capsule::table('rec_employee_proofs')->insert([
            'uuid' => 'p3', 'team_id' => self::TEAM, 'rec_employee_id' => 900,
            'proof_type_code' => 'ausweis',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $zeile = collect($this->component(900)->nachweisUebersicht())->firstWhere('code', 'ausweis');

        $this->assertFalse($zeile['needs_confirmation']);
        $this->assertNull($zeile['confirmed_at']);
    }

    public function test_unbekannter_mitarbeiter_liefert_leere_liste(): void
    {
        $this->assertSame([], $this->component(999999)->nachweisUebersicht());
    }
}
