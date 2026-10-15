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
            // Siehe ProofReaderTest: ohne diese Spalte uebersieht SQLite den
            // Filter auf die aktiven Anstellungen stillschweigend.
            $t->boolean('is_active')->nullable();
            $t->boolean('is_eu_citizen')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_first_aider')->nullable();
            // Nur Umgestellte zeigen den Block (15.10.2026).
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamps();
        });

        // Vertrag an der Anstellung (07.10.2026, main): Employees/Show laedt
        // contracts.contractTemplate eager. Leer — hier geht es um Nachweise.
        $this->capsule->schema()->create('rec_contracts', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->integer('rec_employee_id')->nullable();
            $t->integer('rec_contract_template_id');
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

        // confirmedByUser() wird jetzt angezeigt (Fixrunde 1) — nachweisUebersicht()
        // laedt die Relation eager, ohne Tabelle wirft das eine SQL-Exception.
        $this->capsule->schema()->create('users', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
        });

        Capsule::table('rec_employees')->insert([
            // Nicht-EU, damit Aufenthaltstitel/Arbeitsgenehmigung Pflicht sind.
            ['id' => 900, 'uuid' => 'remp-900', 'team_id' => self::TEAM, 'first_name' => 'Lydia', 'last_name' => 'Bontioti', 'is_active' => true, 'is_eu_citizen' => false, 'portal_v2_since' => '2026-10-01 08:00:00'],
            // Bestandsakte, noch NICHT aufs neue Portal umgestellt.
            ['id' => 901, 'uuid' => 'remp-901', 'team_id' => self::TEAM, 'first_name' => 'Bernd', 'last_name' => 'Bestand', 'is_active' => true, 'is_eu_citizen' => false, 'portal_v2_since' => null],
        ]);

        Capsule::table('users')->insert([
            ['id' => 42, 'name' => 'Nina Personal'],
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Container::getInstance()->forgetInstance('url');
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
        $this->assertSame('Nina Personal', $zeile['confirmed_by'], 'Kleinigkeit aus Fixrunde 1: wer bestaetigt hat, wird angezeigt');
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

    /**
     * Nur bei Umgestellten (15.10.2026, Sebastian): ohne portal_v2_since
     * liefert die Uebersicht nichts — sonst stuenden rund 1300 Bestandsakten
     * nach dem Deploy sofort rot auf "Ausweis fehlt".
     */
    public function test_nicht_umgestellte_akte_liefert_leere_liste(): void
    {
        $this->assertSame([], $this->component(901)->nachweisUebersicht());
    }

    public function test_umgestellte_akte_liefert_zeilen_auch_fuer_fehlende_nachweise(): void
    {
        $zeilen = $this->component(900)->nachweisUebersicht();

        $this->assertNotEmpty($zeilen);
        $this->assertSame('fehlt', collect($zeilen)->firstWhere('code', 'ausweis')['status'] ?? null);
    }

    public function test_block_in_der_akte_nur_bei_umgestellten(): void
    {
        $umgestellt = $this->renderNachweisBlock(900);
        $this->assertStringContainsString('Nachweise-Inbox', $umgestellt);
        $this->assertStringContainsString('Fehlt', $umgestellt);

        $bestand = $this->renderNachweisBlock(901);
        $this->assertStringNotContainsString('Nachweise-Inbox', $bestand);
        $this->assertStringNotContainsString('Fehlt', $bestand);
        $this->assertSame('', trim($bestand));
    }

    /**
     * Rendert genau den Nachweis-Block aus show.blade.php (Quelle zwischen
     * der Zuweisung von $nachweise und dem Block "Offene Vertraege") gegen
     * ein Stellvertreter-$this mit dem echten Ergebnis von nachweisUebersicht().
     * Ohne Livewire-Laufzeit — die ganze Akte braucht zu viel Welt.
     */
    private function renderNachweisBlock(int $employeeId): string
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/show.blade.php');
        $start = strpos($quelle, '@php $nachweise = $this->nachweisUebersicht; @endphp');
        $ende = strpos($quelle, '{{-- Offene Vertraege', (int) $start);
        $this->assertNotFalse($start, 'Nachweis-Block nicht gefunden');
        $this->assertNotFalse($ende);
        $block = substr($quelle, $start, $ende - $start);

        Container::getInstance()->instance('url', new class
        {
            public function route($name, $parameters = [], $absolute = true): string
            {
                return '/' . $name;
            }
        });

        $files = new \Illuminate\Filesystem\Filesystem();
        $compiler = new \Illuminate\View\Compilers\BladeCompiler($files, sys_get_temp_dir());
        $php = $compiler->compileString($block);
        $env = new \Illuminate\View\Factory(
            new \Illuminate\View\Engines\EngineResolver(),
            new \Illuminate\View\FileViewFinder($files, []),
            new \Illuminate\Events\Dispatcher(new Container()),
        );
        $stellvertreter = new class
        {
            public array $nachweisUebersicht = [];
        };
        $stellvertreter->nachweisUebersicht = $this->component($employeeId)->nachweisUebersicht();

        $render = function (string $__php, $__env): string {
            ob_start();
            eval('?>' . $__php);

            return (string) ob_get_clean();
        };

        return \Closure::bind($render, $stellvertreter, null)($php, $env);
    }
}
