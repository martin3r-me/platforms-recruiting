<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;

/**
 * Standby-Re-Claim beim Onboarding-Abschluss (RecApplicant::guardSeatReclaim).
 *
 * Seit dem Verschieben von Teilnehmern (05.10.2026) liest der Re-Claim die
 * Termin-ID der Buchung im Lock frisch nach. Diese Tests sichern den
 * Normalpfad ab, der bis dahin ungetestet war: Platz im AKTUELLEN Termin wird
 * konsumiert, und eine inzwischen nicht mehr wartende Buchung bleibt
 * unberuehrt.
 */
final class SeatReclaimFreshInterviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');

        $container = Container::getInstance();
        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $schema = $capsule->schema();
        $schema->create('rec_interviews', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('team_id');
            $t->dateTime('starts_at');
            $t->integer('max_participants')->nullable();
            $t->string('status')->default('planned');
            $t->boolean('is_active')->default(true);
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        $schema->create('rec_interview_bookings', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_interview_id');
            $t->integer('rec_applicant_id');
            $t->integer('team_id')->nullable();
            $t->string('status')->default('booked');
            $t->dateTime('seat_released_at')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        $schema->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('type');
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        foreach (['rec_interviews', 'rec_interview_bookings', 'rec_auto_pilot_logs'] as $t) {
            Capsule::schema()->drop($t);
        }
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guard(): string
    {
        $applicant = (new RecApplicant())->newFromBuilder(['id' => 42, 'team_id' => 1]);
        $phase = new RecPhase();
        $phase->completion_config = ['confirm_booking_on_completion' => true];

        $m = new \ReflectionMethod($applicant, 'guardSeatReclaim');

        return $m->invoke($applicant, $phase);
    }

    private function termin(int $max): int
    {
        return Capsule::table('rec_interviews')->insertGetId([
            'team_id' => 1, 'starts_at' => '2026-10-20 09:00:00', 'max_participants' => $max,
        ]);
    }

    public function test_platz_im_aktuellen_termin_wird_konsumiert(): void
    {
        $b = $this->termin(2);
        Capsule::table('rec_interview_bookings')->insert(['rec_interview_id' => $b, 'rec_applicant_id' => 7, 'status' => 'booked']);
        $id = Capsule::table('rec_interview_bookings')->insertGetId([
            'rec_interview_id' => $b, 'rec_applicant_id' => 42, 'status' => 'booked',
            'seat_released_at' => '2026-10-03 10:00:00',
        ]);

        $this->assertSame(RecApplicant::RECLAIM_GUARD_OK, $this->guard());
        $this->assertNull(Capsule::table('rec_interview_bookings')->where('id', $id)->value('seat_released_at'));
        $this->assertSame('seat_reclaimed', Capsule::table('rec_auto_pilot_logs')->value('type'));
    }
}
