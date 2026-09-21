<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Jobs\SendNoAssignmentCampaign;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\Campaign\NoAssignmentCampaignRecipients;
use Platform\Recruiting\Services\Campaign\NoAssignmentCampaignSender;

/**
 * Der Job orchestriert: Re-Check ueber den Loader → Senden → Fortschritt.
 * Loader und Sender sind Attrappen (ihre Tests stehen daneben); hier zaehlt,
 * dass niemand angeschrieben wird, den der Re-Check ausschliesst, und dass der
 * Fortschritt am Ende in JEDEM Fall auf done steht — sonst pollt das Modal
 * endlos.
 */
final class SendNoAssignmentCampaignJobTest extends TestCase
{
    private Capsule $capsule;
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->capsule->schema()->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(int $id): void
    {
        RecApplicant::forceCreate(['id' => $id, 'team_id' => 3]);
    }

    private function row(int $id, bool $selectable = true): array
    {
        return ['applicant_id' => $id, 'name' => 'Person ' . $id, 'selectable' => $selectable, 'checked' => true, 'badges' => []];
    }

    /**
     * @param array<int, array> $rows
     * @param array<int, string> $statusById
     * @return array{calls:list<array>, progress:array}
     */
    private function runJob(array $rows, array $statusById = [], int $templateId = 88): array
    {
        $recipients = new class($rows) extends NoAssignmentCampaignRecipients {
            public array $interviewIds = [];

            public function __construct(private array $rows) {}

            public function load(int $teamId, array $applicantIds, ?int $interviewId = null): array
            {
                // Mitgeschrieben, damit der Test sieht, dass der Job die
                // Schulung durchreicht — daran haengt das zweite Klaerungs-Tor.
                $this->interviewIds[] = $interviewId;

                return array_intersect_key($this->rows, array_flip($applicantIds));
            }
        };
        $sender = new class($statusById) extends NoAssignmentCampaignSender {
            public array $calls = [];
            public function __construct(private array $statusById) {}
            public function send(RecApplicant $applicant, int $templateId, int $interviewId, string $campaignUuid, ?int $sentByUserId): array
            {
                $this->calls[] = ['id' => (int) $applicant->id, 'template' => $templateId, 'interview' => $interviewId];
                $status = $this->statusById[$applicant->id] ?? NoAssignmentCampaignSender::STATUS_SENT;

                return ['status' => $status, 'error' => $status === 'sent' ? null : 'Fehler ' . $applicant->id];
            }
        };

        $job = new SendNoAssignmentCampaign('uuid-x', 3, 42, 57, array_keys($rows), $templateId);
        $this->cache->put(SendNoAssignmentCampaign::cacheKey('uuid-x'), SendNoAssignmentCampaign::initialProgress(count($rows)), 86400);
        $job->handle($this->cache, $recipients, $sender);

        // Die Schulung des Jobs (57) muss bei der zweiten Empfaenger-Pruefung
        // ankommen, sonst greift der Klaerungs-Haken dort nicht.
        $this->assertSame([57], $recipients->interviewIds);

        return ['calls' => $sender->calls, 'progress' => $this->cache->get(SendNoAssignmentCampaign::cacheKey('uuid-x'))];
    }

    public function testSendetNacheinanderUndZaehltDenFortschritt(): void
    {
        $this->applicant(1);
        $this->applicant(2);

        $r = $this->runJob([1 => $this->row(1), 2 => $this->row(2)]);

        $this->assertSame([
            ['id' => 1, 'template' => 88, 'interview' => 57],
            ['id' => 2, 'template' => 88, 'interview' => 57],
        ], $r['calls']);
        $this->assertSame(2, $r['progress']['sent']);
        $this->assertSame(0, $r['progress']['failed']);
        $this->assertTrue($r['progress']['done']);
    }

    public function testReCheckSchliesstAusUndVerhindertDenVersand(): void
    {
        $this->applicant(3);
        $this->applicant(4);

        $r = $this->runJob([3 => $this->row(3, selectable: false), 4 => $this->row(4)]);

        $this->assertSame([['id' => 4, 'template' => 88, 'interview' => 57]], $r['calls'], 'Wer inzwischen disponiert ist, wird nicht angeschrieben.');
        $this->assertSame(1, $r['progress']['skipped']);
        $this->assertSame(1, $r['progress']['sent']);
    }

    public function testFehlschlagStopptDenLaufNichtUndNenntDenNamen(): void
    {
        $this->applicant(5);
        $this->applicant(6);

        $r = $this->runJob([5 => $this->row(5), 6 => $this->row(6)], [5 => NoAssignmentCampaignSender::STATUS_NO_PHONE]);

        $this->assertSame(1, $r['progress']['failed']);
        $this->assertSame(1, $r['progress']['sent']);
        $this->assertSame(['Person 5: Fehler 5'], $r['progress']['errors']);
        $this->assertTrue($r['progress']['done']);
    }

    public function testBewerbungOhneZeileZaehltAlsUebersprungen(): void
    {
        // ID ohne Loader-Zeile: team-fremd, inaktiv oder inzwischen geloescht.
        $r = $this->runJob([7 => $this->row(7)]);

        $this->assertSame([], $r['calls'], 'Ohne Bewerbung in der DB kein Versand.');
        $this->assertSame(1, $r['progress']['skipped']);
        $this->assertTrue($r['progress']['done']);
    }

    public function testOhneTemplateWirdNichtsGesendet(): void
    {
        $this->applicant(8);

        $r = $this->runJob([8 => $this->row(8)], templateId: 0);

        $this->assertSame([], $r['calls']);
        $this->assertSame(1, $r['progress']['skipped']);
        $this->assertSame(['Person 8: kein Template gewählt'], $r['progress']['errors']);
    }

    public function testMarkFailedSetztDoneUndHaengtFehlerAn(): void
    {
        $job = new SendNoAssignmentCampaign('uuid-z', 3, 42, 57, [1, 2], 88);

        $job->markFailed($this->cache, 'Job abgebrochen: Timeout');

        $p = $this->cache->get(SendNoAssignmentCampaign::cacheKey('uuid-z'));
        $this->assertTrue($p['done']);
        $this->assertSame(2, $p['total']);
        $this->assertSame(['Job abgebrochen: Timeout'], $p['errors']);
    }
}
