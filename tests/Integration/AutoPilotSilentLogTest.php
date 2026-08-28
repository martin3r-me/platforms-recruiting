<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Support\AutoPilotSilentLog;

/**
 * Ticket docs/tickets/2026-08-28-autopilot-silent-log-flood.md: der Auto-Pilot
 * lief jede Minute und schrieb pro stillem Bewerber (auto_pilot_disabled-Phase,
 * offene Warteliste) jedes Mal denselben `silent`-Eintrag — ~84.000 Zeilen/Tag
 * allein fuer MGL, Log-IDs bei 19 Mio., Enrichment-Log dieser Bewerber
 * unbrauchbar.
 *
 * Regel jetzt: `silent` wird nur geschrieben, wenn der JUENGSTE Log-Eintrag
 * des Bewerbers nicht bereits derselbe stille Text ist. Ein Eintrag beim
 * Eintritt in den Zustand; passiert dazwischen etwas anderes (Erinnerung,
 * Buchung, anderer stiller Grund), wird der Zustand einmal neu festgehalten.
 */
final class AutoPilotSilentLogTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $this->capsule->schema()->create('rec_auto_pilot_logs', function ($t) {
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
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private const P4 = 'Phase "Schulung & Verträge versenden" ist als auto_pilot_disabled markiert — kein Template-Versand.';
    private const WL = 'Bewerber steht auf der Warteliste — Auto-Pilot pausiert (kein Reminder).';

    public function testErsterEintragWirdGeschrieben(): void
    {
        $this->assertTrue(AutoPilotSilentLog::record(7, self::P4));

        $this->assertSame(1, RecAutoPilotLog::where('rec_applicant_id', 7)->count());
        $log = RecAutoPilotLog::where('rec_applicant_id', 7)->first();
        $this->assertSame('silent', $log->type);
        $this->assertSame(self::P4, $log->summary);
    }

    public function testWiederholungImMinutentaktWirdVerschluckt(): void
    {
        AutoPilotSilentLog::record(7, self::P4);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(AutoPilotSilentLog::record(7, self::P4), 'Lauf ' . ($i + 2) . ' darf nichts schreiben');
        }

        $this->assertSame(1, RecAutoPilotLog::where('rec_applicant_id', 7)->count());
    }

    public function testAndererStillerGrundWirdGeschrieben(): void
    {
        AutoPilotSilentLog::record(7, self::P4);

        $this->assertTrue(AutoPilotSilentLog::record(7, self::WL), 'anderer Text = anderer Zustand');
        $this->assertFalse(AutoPilotSilentLog::record(7, self::WL));

        $this->assertSame(2, RecAutoPilotLog::where('rec_applicant_id', 7)->count());
    }

    public function testNachEinemAnderenEreignisWirdDerZustandNeuFestgehalten(): void
    {
        AutoPilotSilentLog::record(7, self::P4);
        RecAutoPilotLog::create(['rec_applicant_id' => 7, 'type' => 'reminder_sent', 'summary' => 'Erinnerung 1/2 per whatsapp gesendet.']);

        $this->assertTrue(AutoPilotSilentLog::record(7, self::P4), 'Zwischen-Ereignis → stiller Zustand wird einmal neu protokolliert');
        $this->assertFalse(AutoPilotSilentLog::record(7, self::P4));

        $this->assertSame(3, RecAutoPilotLog::where('rec_applicant_id', 7)->count());
    }

    public function testDedupeIstProBewerber(): void
    {
        AutoPilotSilentLog::record(7, self::P4);

        $this->assertTrue(AutoPilotSilentLog::record(8, self::P4), 'gleicher Text, anderer Bewerber → eigener Eintrag');
        $this->assertSame(1, RecAutoPilotLog::where('rec_applicant_id', 8)->count());
    }
}
