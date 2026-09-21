<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Show;

/**
 * AKTIVITAETEN IN DER MA-AKTE (21.09.2026).
 *
 * Das Protokoll haengt an der BEWERBUNG (rec_auto_pilot_logs.rec_applicant_id).
 * HR landet aber von ueberall auf der MITARBEITER-Seite — und die zeigte von
 * der Vorgeschichte gar nichts. Aufgefallen an der Klaerungs-Historie: der
 * Namenslink der Schulungs-Detailansicht fuehrt fuer genau diese Menschen auf
 * die MA-Akte (sie haben per Definition eine Personalnummer), die Eintraege
 * standen aber eine Seite weiter.
 *
 * Geprueft wird die Datenseite, nicht das Markup: liefert die Komponente die
 * Eintraege der verknuepften Bewerbung — und schweigt sie sauber, wenn keine
 * Bewerbung dranhaengt (die ZAS-importierten Mitarbeiter)?
 */
final class EmployeeActivityLogTest extends TestCase
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

        Facade::setFacadeApplication($container);
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
            $t->timestamps();
        });
        $this->capsule->schema()->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('team_id');
            $t->timestamps();
        });
        $this->capsule->schema()->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('type', 40);
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Capsule::table('rec_applicants')->insert([
            ['id' => 50, 'uuid' => 'rapp-50', 'team_id' => self::TEAM],
        ]);
        Capsule::table('rec_employees')->insert([
            ['id' => 900, 'uuid' => 'remp-900', 'team_id' => self::TEAM, 'rec_applicant_id' => 50,
             'first_name' => 'Lydia', 'last_name' => 'Bontioti'],
            // Aus dem ZAS-Import angelegt: kein Bewerber dahinter.
            ['id' => 901, 'uuid' => 'remp-901', 'team_id' => self::TEAM, 'rec_applicant_id' => null,
             'first_name' => 'Lars', 'last_name' => 'Abdull'],
        ]);
        Capsule::table('rec_auto_pilot_logs')->insert([
            ['rec_applicant_id' => 50, 'type' => 'einsatz_klaerung_gesetzt',
             'summary' => 'Klärung „ohne Einsatz" gesetzt von Nina Personal: „fängt im Oktober an"',
             'created_at' => '2026-09-20 09:00:00'],
            ['rec_applicant_id' => 50, 'type' => 'einsatz_klaerung_aufgehoben',
             'summary' => 'Klärung „ohne Einsatz" aufgehoben von Nina Personal',
             'created_at' => '2026-09-21 11:00:00'],
            // Fremde Bewerbung — darf nicht mitkommen.
            ['rec_applicant_id' => 51, 'type' => 'run_started', 'summary' => 'Fremd',
             'created_at' => '2026-09-21 12:00:00'],
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

    public function test_ma_akte_zeigt_die_eintraege_der_verknuepften_bewerbung(): void
    {
        $logs = $this->component(900)->autoPilotLogs();

        $this->assertCount(2, $logs);
        $this->assertSame('einsatz_klaerung_aufgehoben', $logs->first()->type, 'neuester zuerst');
        $this->assertStringContainsString('Nina Personal', (string) $logs->first()->summary);
    }

    public function test_mitarbeiter_ohne_bewerbung_bleibt_leer_statt_fremde_eintraege_zu_zeigen(): void
    {
        $logs = $this->component(901)->autoPilotLogs();

        $this->assertCount(0, $logs, 'aus dem ZAS importiert: es gibt keine Vorgeschichte bei uns');
    }

    public function test_unbekannter_mitarbeiter_liefert_keine_eintraege(): void
    {
        $this->assertCount(0, $this->component(999999)->autoPilotLogs());
    }
}
