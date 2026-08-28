<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Jobs\SendNewDatesCampaign;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignRecipients;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignSender;

/**
 * Der Job orchestriert: Re-Check → Template nach Segment → Senden →
 * Ort-Warteliste schliessen → Fortschritt. Sender und Loader sind Attrappen
 * (ihre Tests stehen daneben); hier zaehlt die Reihenfolge und dass ein
 * Fehlschlag den Zustand der Person NICHT anfasst.
 */
final class SendNewDatesCampaignJobTest extends TestCase
{
    private Capsule $capsule;
    private Repository $cache;
    private int $waitingId;
    private int $reviewId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-28 12:00:00');

        if (!class_exists('Str', false)) {
            class_alias(\Illuminate\Support\Str::class, 'Str');
        }

        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();
        $this->cache = new Repository(new ArrayStore());

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->boolean('auto_pilot')->default(true);
            $t->integer('auto_pilot_state_id')->nullable(); $t->integer('auto_pilot_reminder_count')->default(0);
            $t->timestamp('auto_pilot_last_reminder_at')->nullable(); $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->integer('progress')->default(0); $t->integer('rec_phase_id')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_states', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('code'); $t->string('name');
            $t->text('description')->nullable(); $t->boolean('is_active')->default(true); $t->integer('team_id')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->string('type', 30);
            $t->text('summary')->nullable(); $t->text('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        $s->create('rec_interview_waitlist', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id')->nullable(); $t->integer('team_id')->nullable(); $t->boolean('armed')->default(false);
            $t->text('wunschorte')->nullable(); $t->timestamp('enrolled_at')->nullable(); $t->timestamp('notified_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        $this->waitingId = (int) RecAutoPilotState::create(['code' => 'waiting_for_applicant', 'name' => 'Wartet'])->id;
        $this->reviewId = (int) RecAutoPilotState::create(['code' => 'review_needed', 'name' => 'Prüfung'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(int $id): RecApplicant
    {
        return RecApplicant::forceCreate(['id' => $id, 'team_id' => 3, 'auto_pilot_state_id' => $this->reviewId, 'auto_pilot_reminder_count' => 2]);
    }

    /** @param array<int, array> $rows  @param array<int, string> $statusById */
    private function runCampaign(array $rows, array $statusById, ?int $a = 10, ?int $b = 20): array
    {
        $recipients = new class($rows) extends NewDatesCampaignRecipients {
            public function __construct(private array $rows) {}
            public function load(int $teamId, array $applicantIds, \DateTimeImmutable $now): array
            {
                return array_intersect_key($this->rows, array_flip($applicantIds));
            }
        };
        $sender = new class($statusById) extends NewDatesCampaignSender {
            public array $calls = [];
            public function __construct(private array $statusById) {}
            public function send(RecApplicant $applicant, int $templateId, string $segment, string $campaignUuid, ?int $sentByUserId): array
            {
                $this->calls[] = ['id' => $applicant->id, 'template' => $templateId, 'segment' => $segment];
                $status = $this->statusById[$applicant->id] ?? NewDatesCampaignSender::STATUS_SENT;
                return ['status' => $status, 'error' => $status === 'sent' ? null : 'Fehler ' . $applicant->id];
            }
        };

        $job = new SendNewDatesCampaign('uuid-x', 3, 42, array_keys($rows), $a, $b);
        $this->cache->put(SendNewDatesCampaign::cacheKey('uuid-x'), SendNewDatesCampaign::initialProgress(count($rows)), 86400);
        $job->handle($this->cache, $recipients, $sender);

        return ['calls' => $sender->calls, 'progress' => $this->cache->get(SendNewDatesCampaign::cacheKey('uuid-x'))];
    }

    private function row(int $id, string $template, bool $selectable = true): array
    {
        return ['applicant_id' => $id, 'name' => 'N' . $id, 'applied_at' => null, 'phase' => 'P', 'template' => $template, 'selectable' => $selectable, 'checked' => true, 'badges' => []];
    }

    public function testSegmentWaehltDasTemplateUndErfolgReArmtUndSchliesstWarteliste(): void
    {
        $this->applicant(1); $this->applicant(2);
        RecInterviewWaitlist::forceCreate(['rec_applicant_id' => 2, 'team_id' => 3, 'wunschorte' => ['moenchengladbach'], 'enrolled_at' => now(), 'notified_at' => now()]);
        RecInterviewWaitlist::forceCreate(['rec_applicant_id' => 2, 'team_id' => 3, 'rec_interview_id' => 86, 'armed' => true, 'enrolled_at' => now()]); // Termin-Abo bleibt

        $r = $this->runCampaign([1 => $this->row(1, 'A'), 2 => $this->row(2, 'B')], []);

        $this->assertSame([['id' => 1, 'template' => 10, 'segment' => 'A'], ['id' => 2, 'template' => 20, 'segment' => 'B']], $r['calls']);
        $this->assertSame(2, $r['progress']['sent']);
        $this->assertTrue($r['progress']['done']);

        // Nachtrag 28.08.: KEIN Re-Arm beim Versand. Wer die Nachricht nur
        // liest, bekommt keine zwei Erinnerungen hinterher; der Auto-Pilot geht
        // erst bei einer Reaktion (Buchung/Formular) wieder an —
        // RecApplicant::registerSelfServiceReaction(), SelfServiceReactionTest.
        $a1 = RecApplicant::find(1);
        $this->assertSame($this->reviewId, (int) $a1->auto_pilot_state_id, 'Status bleibt review_needed — kein Re-Arm beim Versand');
        $this->assertSame(2, (int) $a1->auto_pilot_reminder_count, 'Zaehler unangetastet');

        $this->assertNotNull(RecInterviewWaitlist::where('rec_applicant_id', 2)->whereNull('rec_interview_id')->value('cancelled_at'), 'Ort-Eintrag geschlossen');
        $this->assertNull(RecInterviewWaitlist::where('rec_applicant_id', 2)->where('rec_interview_id', 86)->value('cancelled_at'), 'Termin-Abo nicht angefasst');
        $this->assertSame(1, RecAutoPilotLog::where('rec_applicant_id', 2)->where('type', 'waitlist_replaced')->count());
    }

    public function testFehlschlagLaesstZustandStehen(): void
    {
        $this->applicant(3);
        RecInterviewWaitlist::forceCreate(['rec_applicant_id' => 3, 'team_id' => 3, 'wunschorte' => ['moenchengladbach'], 'enrolled_at' => now()]);

        $r = $this->runCampaign([3 => $this->row(3, 'B')], [3 => NewDatesCampaignSender::STATUS_FAILED]);

        $this->assertSame(1, $r['progress']['failed']);
        $this->assertSame(['N3: Fehler 3'], $r['progress']['errors']);
        $this->assertSame($this->reviewId, (int) RecApplicant::find(3)->auto_pilot_state_id, 'kein Re-Arm');
        $this->assertNull(RecInterviewWaitlist::where('rec_applicant_id', 3)->value('cancelled_at'), 'Warteliste offen');
    }

    public function testNichtWaehlbareUndFehlendeTemplatesWerdenUebersprungen(): void
    {
        $this->applicant(4); $this->applicant(5); $this->applicant(6);

        $r = $this->runCampaign(
            [4 => $this->row(4, 'B', selectable: false), 5 => $this->row(5, 'A'), 6 => $this->row(6, 'B')],
            [],
            a: null, // Template A fehlt → 5 wird uebersprungen
        );

        $this->assertSame([['id' => 6, 'template' => 20, 'segment' => 'B']], $r['calls']);
        $this->assertSame(2, $r['progress']['skipped']);
        $this->assertSame(1, $r['progress']['sent']);
        $this->assertSame($this->reviewId, (int) RecApplicant::find(5)->auto_pilot_state_id, 'uebersprungen = nicht angefasst');
    }

    public function testFortschrittZaehltAuchOhneCacheEintrag(): void
    {
        $this->applicant(7);
        $recipients = new class extends NewDatesCampaignRecipients {
            public function __construct() {}
            public function load(int $teamId, array $applicantIds, \DateTimeImmutable $now): array { return []; }
        };
        $sender = new class extends NewDatesCampaignSender { public function __construct() {} };

        (new SendNewDatesCampaign('uuid-y', 3, null, [7], 10, 20))->handle($this->cache, $recipients, $sender);

        $p = $this->cache->get(SendNewDatesCampaign::cacheKey('uuid-y'));
        $this->assertTrue($p['done']);
        $this->assertSame(1, $p['skipped'], 'ID ohne Zeile (Team-fremd/geloescht) zaehlt als uebersprungen.');
    }

    /**
     * Final-Review: failed() (bzw. die dahinterliegende markFailed()) muss den
     * Fortschritt auf done=true setzen und die Abbruch-Ursache als Fehlerzeile
     * anhaengen — sonst poll(t) das Statistik-Modal endlos weiter, wenn der
     * Job z. B. an einem Timeout stirbt statt handle() sauber zu durchlaufen.
     */
    public function testMarkFailedSetztDoneUndHaengtFehlerAn(): void
    {
        $job = new SendNewDatesCampaign('uuid-z', 3, 42, [1, 2], 10, 20);
        $this->cache->put(SendNewDatesCampaign::cacheKey('uuid-z'), SendNewDatesCampaign::initialProgress(2), 86400);

        $job->markFailed($this->cache, 'Job abgebrochen: Timeout');

        $p = $this->cache->get(SendNewDatesCampaign::cacheKey('uuid-z'));
        $this->assertTrue($p['done']);
        $this->assertSame(['Job abgebrochen: Timeout'], $p['errors']);
    }

    /**
     * Ohne vorhandenen Cache-Eintrag (Job starb, bevor handle() je einen
     * Fortschritt geschrieben hat) baut markFailed() den Fortschritt frisch
     * auf initialProgress() auf statt zu werfen.
     */
    public function testMarkFailedOhneVorherigenCacheEintrag(): void
    {
        $job = new SendNewDatesCampaign('uuid-zz', 3, null, [1], 10, 20);

        $job->markFailed($this->cache, 'Job abgebrochen: unbekannt');

        $p = $this->cache->get(SendNewDatesCampaign::cacheKey('uuid-zz'));
        $this->assertTrue($p['done']);
        $this->assertSame(1, $p['total']);
        $this->assertSame(['Job abgebrochen: unbekannt'], $p['errors']);
    }
}
