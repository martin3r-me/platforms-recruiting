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
use Platform\Recruiting\Models\RecAutoPilotState;

/**
 * Spec §5.4 Schritt 5: Nach einer Kampagnen-WhatsApp laeuft der Auto-Pilot
 * wieder — Status waiting, Zaehler 0, Timer = jetzt (Kampagne ist der
 * Erstkontakt des neuen Zyklus). Direkteinstellungen (auto_pilot=false)
 * bleiben unberuehrt.
 */
final class RearmAutoPilotTest extends TestCase
{
    private Capsule $capsule;
    private int $waitingId;
    private int $reviewId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-28 12:00:00');

        if (!class_exists('Str')) {
            class_alias(\Illuminate\Support\Str::class, 'Str');
        }

        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $schema = $this->capsule->schema();
        $schema->create('rec_applicants', function ($t) {
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
        $schema->create('rec_auto_pilot_states', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('code');
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('team_id')->nullable();
            $t->timestamps();
        });
        $schema->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('type', 30);
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        $this->waitingId = (int) RecAutoPilotState::create(['code' => 'waiting_for_applicant', 'name' => 'Wartet'])->id;
        $this->reviewId = (int) RecAutoPilotState::create(['code' => 'review_needed', 'name' => 'Prüfung'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::clearBootedModels();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    private function applicant(bool $autoPilot = true): RecApplicant
    {
        return RecApplicant::forceCreate([
            'team_id' => 3,
            'auto_pilot' => $autoPilot,
            'auto_pilot_state_id' => $this->reviewId,
            'auto_pilot_reminder_count' => 2,
            'auto_pilot_last_reminder_at' => '2026-08-10 09:26:00',
        ]);
    }

    public function testRearmSetztStatusZaehlerUndTimer(): void
    {
        $a = $this->applicant();

        $this->assertTrue($a->rearmAutoPilot('Kampagne Neue Termine'));

        $a->refresh();
        $this->assertSame($this->waitingId, (int) $a->auto_pilot_state_id);
        $this->assertSame(0, (int) $a->auto_pilot_reminder_count);
        $this->assertSame('2026-08-28 12:00:00', $a->auto_pilot_last_reminder_at->format('Y-m-d H:i:s'));

        $log = RecAutoPilotLog::where('rec_applicant_id', $a->id)->where('type', 'autopilot_rearmed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Kampagne Neue Termine', (string) $log->summary);
    }

    public function testDirekteinstellungBleibtUnberuehrt(): void
    {
        $a = $this->applicant(autoPilot: false);

        $this->assertFalse($a->rearmAutoPilot('Kampagne Neue Termine'));

        $a->refresh();
        $this->assertSame($this->reviewId, (int) $a->auto_pilot_state_id);
        $this->assertSame(2, (int) $a->auto_pilot_reminder_count);
        $this->assertSame(0, RecAutoPilotLog::count());
    }
}
