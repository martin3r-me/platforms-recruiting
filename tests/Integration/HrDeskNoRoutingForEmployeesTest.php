<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\HrDeskRoutingService;

/**
 * Wer schon Mitarbeiter ist, wird nicht mehr auf den HR-Schreibtisch
 * geroutet — die Regel greift nicht ins Leere, sie protokolliert.
 *
 * Anlass sind zwei Faelle, die genau so entstanden sind (19.09.2026):
 * Der Bewerber-Datensatz lebt nach der MA-Anlage weiter, `is_active=false`
 * nimmt ihn nur aus dem Dashboard. Phasen-Maschinerie, oeffentliches
 * Formular und Routing-Regeln laufen unveraendert weiter:
 *
 *  - Bewerber 1831: MA angelegt am 27.05., am 29.05. fuellt er das
 *    Onboarding-Formular aus, die Nicht-EU-Antwort oeffnet einen Fall.
 *  - Bewerber 1578: MA angelegt am 27.05., Fall am 06.07. — vermutlich
 *    durch den MigrateNonEuCases-Backfill.
 *
 * Der Fall wird deshalb gar nicht erst eroeffnet. Die Log-Zeile bleibt,
 * damit sichtbar ist, dass die Regel angeschlagen hat: bei einem
 * Mitarbeiter ohne geklaerten Rechtsstatus ist das die Information, die
 * zaehlt — sie gehoert nur nicht in eine Bewerber-Triage-Liste.
 */
final class HrDeskNoRoutingForEmployeesTest extends TestCase
{
    private const TEAM = 3;

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

        $schema = $this->capsule->schema();

        $schema->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->string('uuid', 36)->nullable();
            $t->string('public_token')->nullable();
            $t->integer('team_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_parked')->default(false);
            $t->boolean('is_on_hr_desk')->default(false);
            $t->boolean('is_unrouted')->default(false);
            $t->boolean('auto_pilot')->default(true);
            $t->timestamp('rejected_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_hr_desk_cases', function ($t) {
            $t->increments('id');
            $t->string('uuid', 36)->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('team_id');
            $t->string('reason', 50);
            $t->string('status', 20)->default('open');
            $t->text('notes')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->integer('opened_by_user_id')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->integer('resolved_by_user_id')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->string('uuid', 36)->nullable();
            $t->integer('rec_applicant_id');
            $t->string('type', 50);
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $schema = $this->capsule->schema();
        foreach (['rec_auto_pilot_logs', 'rec_hr_desk_cases', 'rec_employees', 'rec_applicants'] as $table) {
            $schema->drop($table);
        }
        parent::tearDown();
    }

    public function testMitarbeiterBekommtKeinenNeuenFall(): void
    {
        $applicant = $this->applicantMitMitarbeiter();

        (new HrDeskRoutingService())->routeToHrDesk(
            $applicant,
            RecHrDeskCase::REASON_NON_EU_CITIZEN,
        );

        $this->assertSame(0, RecHrDeskCase::where('rec_applicant_id', $applicant->id)->count());
        $this->assertFalse((bool) $applicant->fresh()->is_on_hr_desk);
    }

    public function testDasUebergehenWirdProtokolliert(): void
    {
        $applicant = $this->applicantMitMitarbeiter();

        (new HrDeskRoutingService())->routeToHrDesk(
            $applicant,
            RecHrDeskCase::REASON_NON_EU_CITIZEN,
        );

        $log = RecAutoPilotLog::where('rec_applicant_id', $applicant->id)
            ->where('type', 'hr_desk_routing_skipped')
            ->first();

        $this->assertNotNull($log, 'Das Uebergehen hinterlaesst keine Spur.');
        $this->assertStringContainsString('Mitarbeiter', (string) $log->summary);
    }

    /**
     * Die Gegenprobe: ohne Mitarbeiter-Datensatz laeuft das Routing
     * unveraendert. Sonst wuerde der Test auch gruen, wenn der Guard alles
     * blockiert.
     */
    public function testBewerberOhneMitarbeiterWirdWeiterhinGeroutet(): void
    {
        $applicant = $this->applicant();

        $case = (new HrDeskRoutingService())->routeToHrDesk(
            $applicant,
            RecHrDeskCase::REASON_NON_EU_CITIZEN,
        );

        $this->assertNotNull($case);
        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $case->status);
        $this->assertTrue((bool) $applicant->fresh()->is_on_hr_desk);
    }

    private function applicant(): RecApplicant
    {
        $applicant = new RecApplicant();
        $applicant->forceFill([
            'uuid' => 'uuid-' . uniqid('', true),
            'public_token' => 'tok-' . uniqid('', true),
            'team_id' => self::TEAM,
            'is_active' => true,
            'is_on_hr_desk' => false,
        ]);
        $applicant->save();

        return $applicant;
    }

    private function applicantMitMitarbeiter(): RecApplicant
    {
        $applicant = $this->applicant();
        $this->capsule->table('rec_employees')->insert([
            'rec_applicant_id' => $applicant->id,
        ]);

        return $applicant;
    }
}
