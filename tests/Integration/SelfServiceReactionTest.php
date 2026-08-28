<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;

/**
 * Kampagne „Neue Termine“, Nachtrag 28.08.: Der Auto-Pilot wird NICHT beim
 * Versand wieder scharf (sonst zwei Erinnerungen fuer Leute, die die Nachricht
 * nur gelesen haben), sondern bei der REAKTION. Eine Selbstbuchung ueber die
 * oeffentliche Terminseite gilt wie eine WhatsApp-Antwort: Zyklus zurueck auf
 * Anfang, Status frei, dann sofort der Phasen-Abschluss-Check — damit ein
 * P2-Bucher nach P3 aufsteigt und den Onboarding-Erstkontakt bekommt, statt
 * als review_needed liegen zu bleiben und den Platz per ReleaseStaleSeats zu
 * verlieren.
 */
final class SelfServiceReactionTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-28 12:00:00');

        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('public_token')->nullable();
            $t->integer('team_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('auto_pilot')->default(true);
            $t->integer('auto_pilot_state_id')->nullable();
            $t->integer('auto_pilot_reminder_count')->default(0);
            $t->timestamp('auto_pilot_last_reminder_at')->nullable();
            $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->integer('progress')->default(0);
            $t->integer('rec_phase_id')->nullable();
            $t->timestamps();
        });
        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('type', 30);
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    /**
     * Subklasse mit stillgelegtem Phasen-Check: checkAutoPilotCompletion()
     * braucht Phasen, Extra-Felder und Gates — hier zaehlt nur, DASS er nach
     * dem Reset aufgerufen wird. Die Aufstiegs-Logik hat eigene Tests.
     */
    private function applicant(array $attrs): RecApplicant
    {
        $model = new class extends RecApplicant {
            public int $completionChecks = 0;

            public function checkAutoPilotCompletion(): void
            {
                $this->completionChecks++;
            }
        };

        return $model->newQuery()->getModel()->forceCreate(array_merge([
            'team_id' => 3,
            'auto_pilot' => true,
            'auto_pilot_state_id' => 5,           // review_needed
            'auto_pilot_reminder_count' => 2,
            'auto_pilot_last_reminder_at' => '2026-08-10 09:26:00',
            'progress' => 42,
        ], $attrs));
    }

    public function testBuchungSetztZyklusZurueckUndPrueftDenAufstieg(): void
    {
        $a = $this->applicant([]);

        $this->assertTrue($a->registerSelfServiceReaction('Terminbuchung'));

        $this->assertSame(1, $a->completionChecks, 'Aufstiegs-Check laeuft sofort, nicht erst im Cron.');

        $fresh = RecApplicant::find($a->id);
        $this->assertNull($fresh->auto_pilot_state_id, 'review_needed ist weg — der Cron nimmt ihn wieder auf.');
        $this->assertSame(0, (int) $fresh->auto_pilot_reminder_count);
        $this->assertNull($fresh->auto_pilot_last_reminder_at, 'null = naechster Lauf schickt den Erstkontakt der (neuen) Phase.');

        $log = RecAutoPilotLog::where('rec_applicant_id', $a->id)->where('type', 'autopilot_reacted')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Terminbuchung', (string) $log->summary);
    }

    public function testDirekteinstellungBleibtUnberuehrt(): void
    {
        $a = $this->applicant(['auto_pilot' => false]);

        $this->assertFalse($a->registerSelfServiceReaction('Terminbuchung'));

        $this->assertSame(0, $a->completionChecks, 'Ohne Auto-Pilot kein Phasen-Automatismus — HR schaltet manuell.');
        $this->assertSame(5, (int) RecApplicant::find($a->id)->auto_pilot_state_id);
        $this->assertSame(0, RecAutoPilotLog::count());
    }

    public function testAbgeschlossenerAutoPilotWirdNichtNeuGestartet(): void
    {
        $a = $this->applicant(['auto_pilot_completed_at' => '2026-08-01 10:00:00']);

        $this->assertFalse($a->registerSelfServiceReaction('Terminbuchung'));

        $fresh = RecApplicant::find($a->id);
        $this->assertNotNull($fresh->auto_pilot_completed_at, 'completed bleibt completed — sonst feuerte der Legacy-Buchungslink erneut.');
        $this->assertSame(0, $a->completionChecks);
    }
}
