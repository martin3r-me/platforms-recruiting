<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoDeclineCheck;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclineAlarm;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclineCandidates;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclineCheckRunner;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclineClassifier;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclineReview;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory;

/**
 * Absage-Erkennung Ende zu Ende gegen echte Migrationen (Spec 2026-10-08):
 * wer geprueft wird, was protokolliert wird, wann gemeldet und alarmiert wird.
 * Sprachmodell und Alarm sind Attrappen; die Einbuchungen selbst darf der
 * Ablauf NIE anfassen (Entscheidung 1).
 */
class DispoDeclineCheckTest extends TestCase
{
    private const TEAM = 7301;
    private const FILIALE = 3;

    /** @var list<array> an das Modell gesendete Prompts */
    private static array $llmCalls = [];
    /** @var list<array> Optionen der Modell-Aufrufe */
    private static array $llmOptions = [];
    /** @var callable|null laeuft waehrend des Modell-Aufrufs (simuliert einen parallelen Job) */
    private static $duringLlm = null;
    /** @var \Throwable|null wirft der Alarm */
    private ?\Throwable $alarmThrows = null;
    /** @var string|\Throwable naechste Antwort des Modells */
    private static mixed $llmAnswer = '';
    /** @var list<array{event:int, name:string, dates:string}> */
    private array $alarms = [];

    private int $channelId;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        $container = Container::getInstance();
        $container->forgetInstance('config');
        $container->forgetInstance(\Platform\Core\Services\OpenAiService::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_decline_checks', 'comms_whatsapp_messages', 'comms_whatsapp_threads', 'comms_channels',
            'rec_dispo_assignments', 'rec_dispo_events', 'rec_dispo_filiale_settings', 'crm_contact_links', 'rec_employees'] as $table) {
            Capsule::table($table)->delete();
        }

        self::$llmCalls = [];
        self::$llmOptions = [];
        self::$duringLlm = null;
        self::$llmAnswer = '';
        $this->alarmThrows = null;
        $this->alarms = [];

        // Attrappe fuer OpenAiService::chat — app() liefert die gebundene Instanz.
        Container::getInstance()->instance(\Platform\Core\Services\OpenAiService::class, new class {
            public function chat(array $messages, string $model, array $options = []): array
            {
                DispoDeclineCheckTest::recordLlmCall($messages, $options);
                $answer = DispoDeclineCheckTest::llmAnswer();
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return ['content' => $answer];
            }
        });

        // Log-Attrappe (Facade cacht — siehe reference_log_facade_test_stub).
        Container::getInstance()->instance('log', new class {
            public function __call($name, $args) {}
        });
        Facade::clearResolvedInstance('log');

        $this->channelId = (int) CommsChannel::create([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5550001',
        ])->id;
    }

    public static function recordLlmCall(array $messages, array $options = []): void
    {
        self::$llmCalls[] = $messages;
        self::$llmOptions[] = $options;
        if (self::$duringLlm !== null) {
            (self::$duringLlm)();
        }
    }

    public static function llmAnswer(): mixed
    {
        return self::$llmAnswer;
    }

    // ---- Aufbau ----

    private function enable(string $since = '-1 day'): void
    {
        RecDispoFilialeSettings::create([
            'team_id' => self::TEAM, 'filial_nr' => self::FILIALE,
            'decline_check_enabled_at' => now()->modify($since),
        ]);
    }

    private function employee(string $pnr, string $phone): int
    {
        return (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Lea', 'last_name' => $pnr,
            'personnel_number' => $pnr, 'phone' => $phone, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ])->id;
    }

    private function event(string $ref, int $filiale = self::FILIALE): int
    {
        return (int) RecDispoEvent::create(['einsatz_ref' => $ref, 'name' => 'VA ' . $ref, 'filial_nr' => $filiale])->id;
    }

    private function assignment(int $eventId, int $employeeId, string $datum, array $extra = []): int
    {
        return (int) RecDispoAssignment::create(array_merge([
            'ds_ref' => 'DS-' . uniqid(), 'rec_dispo_event_id' => $eventId, 'pnr_raw' => 'X',
            'rec_employee_id' => $employeeId, 'datum' => $datum, 'von' => '15:00', 'bis' => '23:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
            'reminder_sent_at' => now()->subHours(3),
        ], $extra))->id;
    }

    private function thread(string $phone, ?int $channelId = null): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => $channelId ?? $this->channelId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => $phone, 'is_unread' => true,
        ])->id;
    }

    private function message(int $threadId, string $body, string $direction = 'inbound'): int
    {
        return (int) CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => $direction,
            'body' => $body, 'message_type' => 'text', 'status' => 'received',
        ])->id;
    }

    private function runner(): DispoDeclineCheckRunner
    {
        $directory = new DispoThreadDirectory(new DispoIdentityResolver(), new DispoEmployeeGateway());
        $test = $this;
        $alarm = new class(new DispoChannelResolver(), $test) extends DispoDeclineAlarm {
            public function __construct(DispoChannelResolver $r, private DispoDeclineCheckTest $test)
            {
                parent::__construct($r);
            }

            public function send(RecDispoEvent $event, string $name, string $dates): ?int
            {
                return $this->test->recordAlarm((int) $event->id, $name, $dates);
            }
        };

        return new DispoDeclineCheckRunner(
            new DispoDeclineCandidates(new DispoIdentityResolver(), $directory),
            new DispoDeclineClassifier($directory),
            $alarm,
        );
    }

    public function recordAlarm(int $eventId, string $name, string $dates): int
    {
        if ($this->alarmThrows !== null) {
            throw $this->alarmThrows;
        }
        $this->alarms[] = ['event' => $eventId, 'name' => $name, 'dates' => $dates];

        return 9000 + count($this->alarms);
    }

    private function check(int $messageId, bool $final = true): void
    {
        $this->runner()->run($messageId, $final, [$this->channelId]);
    }

    /** Standardfall: eine Person, eine VA, ein angeschriebener Tag. */
    private function scenario(): array
    {
        $this->enable();
        $e = $this->employee('RG1', '+49 171 1234567');
        $event = $this->event('VA1');
        $a = $this->assignment($event, $e, now()->addDays(2)->toDateString(), ['confirmed_at' => now()->subHour()]);
        $t = $this->thread('491711234567');

        return [$e, $event, $a, $t];
    }

    // ---- Faelle ----

    public function test_a_confirmed_person_who_declines_is_reported_and_alarmed_but_not_declined(): void
    {
        [$e, $event, $a, $t] = $this->scenario();
        self::$llmAnswer = json_encode(['absage' => true, 'sicherheit' => 'high', 'einbuchungen' => [$a], 'grund' => 'krank']);

        $this->check($this->message($t, 'Sorry, ich bin krank und kann morgen nicht'));

        $check = RecDispoDeclineCheck::sole();
        $this->assertSame(RecDispoDeclineCheck::OUTCOME_DECLINE, $check->outcome);
        $this->assertSame(RecDispoDeclineCheck::REVIEW_OPEN, $check->review_status);
        $this->assertSame([$a], $check->assignment_ids);
        $this->assertSame($e, $check->rec_employee_id);
        $this->assertTrue($check->used_llm);
        $this->assertSame(9001, $check->alarm_message_id);

        $this->assertCount(1, $this->alarms);
        $this->assertSame('Lea RG1', $this->alarms[0]['name']);
        $this->assertSame(now()->addDays(2)->format('d.m.'), $this->alarms[0]['dates']);

        $row = RecDispoAssignment::find($a);
        $this->assertNull($row->declined_at, 'Die KI sagt nie selbst ab.');
        $this->assertNotNull($row->confirmed_at);
    }

    public function test_messages_from_before_switching_on_are_not_checked(): void
    {
        [, , , $t] = $this->scenario();
        RecDispoFilialeSettings::query()->update(['decline_check_enabled_at' => now()->addMinute()]);

        $this->check($this->message($t, 'ich kann nicht'));

        $this->assertSame(0, RecDispoDeclineCheck::count());
        $this->assertSame([], self::$llmCalls);
    }

    public function test_switched_off_filiale_is_not_checked(): void
    {
        [, , , $t] = $this->scenario();
        RecDispoFilialeSettings::query()->update(['decline_check_enabled_at' => null]);

        $this->check($this->message($t, 'ich kann nicht'));

        $this->assertSame(0, RecDispoDeclineCheck::count());
    }

    public function test_person_not_yet_asked_for_confirmation_is_not_checked(): void
    {
        [, , $a, $t] = $this->scenario();
        RecDispoAssignment::query()->whereKey($a)->update(['reminder_sent_at' => null]);

        $this->check($this->message($t, 'ich kann nicht'));

        $this->assertSame(0, RecDispoDeclineCheck::count());
        $this->assertSame([], self::$llmCalls);
    }

    public function test_past_declined_missing_and_deletion_marked_days_are_no_candidates(): void
    {
        [$e, $event, $a, $t] = $this->scenario();
        RecDispoAssignment::query()->whereKey($a)->update(['declined_at' => now()]);
        $this->assignment($event, $e, now()->subDay()->toDateString());
        $this->assignment($event, $e, now()->addDay()->toDateString(), ['missing_since' => now()]);
        $this->assignment($event, $e, now()->addDay()->toDateString(), ['deletion_marked_at' => now()]);

        $this->check($this->message($t, 'ich kann nicht'));

        $this->assertSame(0, RecDispoDeclineCheck::count());
    }

    public function test_other_filiale_and_other_channel_are_not_checked(): void
    {
        $this->enable();
        $e = $this->employee('RG1', '+49 171 1234567');
        $this->assignment($this->event('VA-F9', 9), $e, now()->addDay()->toDateString());
        $t = $this->thread('491711234567');
        $this->check($this->message($t, 'ich kann nicht'));
        $this->assertSame(0, RecDispoDeclineCheck::count(), 'Filiale 9 hat den Schalter nicht an.');

        $this->assignment($this->event('VA-F3'), $e, now()->addDay()->toDateString());
        $foreign = (int) CommsChannel::create(['team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta', 'sender_identifier' => '+49 160 999'])->id;
        $this->check($this->message($this->thread('491711234567', $foreign), 'ich kann nicht'));
        $this->assertSame(0, RecDispoDeclineCheck::count(), 'Kein Dispo-Kanal.');
    }

    public function test_outbound_and_empty_messages_are_ignored(): void
    {
        [, , , $t] = $this->scenario();

        $this->check($this->message($t, 'Bitte bestaetige deinen Einsatz', 'outbound'));
        $this->check($this->message($t, '   '));

        $this->assertSame(0, RecDispoDeclineCheck::count());
    }

    public function test_obvious_acknowledgement_is_logged_without_the_model(): void
    {
        [, , , $t] = $this->scenario();

        $this->check($this->message($t, 'Passt 👍'));

        $check = RecDispoDeclineCheck::sole();
        $this->assertSame(RecDispoDeclineCheck::OUTCOME_PATTERN_SKIP, $check->outcome);
        $this->assertFalse($check->used_llm);
        $this->assertNull($check->review_status);
        $this->assertSame([], self::$llmCalls);
    }

    public function test_low_confidence_decline_is_logged_but_not_reported(): void
    {
        [, , $a, $t] = $this->scenario();
        self::$llmAnswer = json_encode(['absage' => true, 'sicherheit' => 'low', 'einbuchungen' => [$a]]);

        $this->check($this->message($t, 'mal sehen ob das klappt'));

        $check = RecDispoDeclineCheck::sole();
        $this->assertSame(RecDispoDeclineCheck::OUTCOME_DECLINE, $check->outcome);
        $this->assertNull($check->review_status);
        $this->assertSame([], $this->alarms);
    }

    public function test_no_decline_is_logged_as_such(): void
    {
        [, , , $t] = $this->scenario();
        self::$llmAnswer = '{"absage": false, "sicherheit": "high", "einbuchungen": []}';

        $this->check($this->message($t, 'Wo genau ist der Eingang?'));

        $check = RecDispoDeclineCheck::sole();
        $this->assertSame(RecDispoDeclineCheck::OUTCOME_NO_DECLINE, $check->outcome);
        $this->assertNull($check->review_status);
        $this->assertSame([], $this->alarms);
    }

    public function test_transport_error_is_rethrown_before_the_last_attempt_and_logged_as_failed_on_it(): void
    {
        [, , , $t] = $this->scenario();
        $m = $this->message($t, 'ich kann nicht');
        self::$llmAnswer = new \RuntimeException('quota');

        try {
            $this->check($m, false);
            $this->fail('Vor dem letzten Versuch muss der Fehler fuer den Job-Retry durchschlagen.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, RecDispoDeclineCheck::count(), 'Ohne Zeile, sonst blockiert die Dublettensperre den Retry.');

        $this->check($m, true);
        $check = RecDispoDeclineCheck::sole();
        $this->assertSame(RecDispoDeclineCheck::OUTCOME_FAILED, $check->outcome);
        $this->assertNull($check->review_status);
        $this->assertSame([], $this->alarms);
    }

    public function test_unreadable_answer_is_failed_and_reports_nothing(): void
    {
        [, , , $t] = $this->scenario();
        self::$llmAnswer = 'Das ist wohl eine Absage.';

        $this->check($this->message($t, 'ich kann nicht'));

        $this->assertSame(RecDispoDeclineCheck::OUTCOME_FAILED, RecDispoDeclineCheck::sole()->outcome);
        $this->assertSame([], $this->alarms);
    }

    public function test_the_same_message_is_never_checked_twice(): void
    {
        [, , $a, $t] = $this->scenario();
        self::$llmAnswer = json_encode(['absage' => true, 'sicherheit' => 'high', 'einbuchungen' => [$a]]);
        $m = $this->message($t, 'ich kann nicht');

        $this->check($m);
        $this->check($m);

        $this->assertCount(1, self::$llmCalls);
        $this->assertCount(1, $this->alarms);
        $this->assertSame(1, RecDispoDeclineCheck::count());
    }

    public function test_decline_for_one_event_leaves_the_other_event_unreported(): void
    {
        [$e, $eventA, $a, $t] = $this->scenario();
        $eventB = $this->event('VA2');
        $b = $this->assignment($eventB, $e, now()->addDays(5)->toDateString());
        self::$llmAnswer = json_encode(['absage' => true, 'sicherheit' => 'medium', 'einbuchungen' => [$a]]);

        $this->check($this->message($t, 'uebermorgen kann ich nicht'));

        $this->assertSame(RecDispoDeclineCheck::REVIEW_OPEN, RecDispoDeclineCheck::where('rec_dispo_event_id', $eventA)->sole()->review_status);
        $other = RecDispoDeclineCheck::where('rec_dispo_event_id', $eventB)->sole();
        $this->assertSame(RecDispoDeclineCheck::OUTCOME_NO_DECLINE, $other->outcome);
        $this->assertNull($other->review_status);
        $this->assertSame([$eventA], array_column($this->alarms, 'event'));
    }

    public function test_without_named_days_all_candidates_of_the_event_are_affected(): void
    {
        [$e, $event, $a, $t] = $this->scenario();
        $a2 = $this->assignment($event, $e, now()->addDays(3)->toDateString());
        self::$llmAnswer = '{"absage": true, "sicherheit": "high", "einbuchungen": []}';

        $this->check($this->message($t, 'ich bin raus'));

        $this->assertSame([$a, $a2], RecDispoDeclineCheck::sole()->assignment_ids);
        $this->assertSame(now()->addDays(2)->format('d.m.') . ', ' . now()->addDays(3)->format('d.m.'), $this->alarms[0]['dates']);
    }

    public function test_second_record_of_the_same_person_is_one_person(): void
    {
        $this->enable();
        $rg = $this->employee('RG1', '+49 171 1234567');
        $ma = $this->employee('MA1', '+49 171 1234567');
        foreach ([$rg, $ma] as $id) {
            Capsule::table('crm_contact_links')->insert([
                'uuid' => 'lnk-' . $id, 'contact_id' => 77, 'team_id' => self::TEAM, 'created_by_user_id' => 1,
                'linkable_id' => $id, 'linkable_type' => (new RecEmployee())->getMorphClass(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $event = $this->event('VA1');
        $a = $this->assignment($event, $ma, now()->addDay()->toDateString());
        $t = $this->thread('491711234567');
        self::$llmAnswer = json_encode(['absage' => true, 'sicherheit' => 'high', 'einbuchungen' => [$a]]);

        $this->check($this->message($t, 'ich kann nicht'));

        $check = RecDispoDeclineCheck::sole();
        $this->assertSame(min($rg, $ma), $check->rec_employee_id, 'Kanonische id der Identitaetsgruppe.');
        $this->assertSame([$a], $check->assignment_ids);
    }

    public function test_prompt_carries_the_history_before_the_message_and_the_candidate_list(): void
    {
        [, , $a, $t] = $this->scenario();
        $this->message($t, 'Bitte bestaetige deinen Einsatz am Samstag', 'outbound');
        $m = $this->message($t, 'kann leider nicht');
        $this->message($t, 'spaetere Nachricht');
        self::$llmAnswer = '{"absage": false}';

        $this->check($m);

        $payload = json_decode(self::$llmCalls[0][1]['content'], true);
        $this->assertSame('kann leider nicht', $payload['nachricht']);
        $this->assertSame(['Wir: Bitte bestaetige deinen Einsatz am Samstag'], $payload['verlauf']);
        $this->assertSame($a, $payload['einbuchungen'][0]['id']);
        $this->assertTrue($payload['einbuchungen'][0]['bestaetigt']);
    }

    // ---- Pruefen in der VA (DispoDeclineReview) ----

    /** Meldet eine Absage fuer $ids und liefert die Meldung. */
    private function reported(int $threadId, array $ids): RecDispoDeclineCheck
    {
        self::$llmAnswer = json_encode(['absage' => true, 'sicherheit' => 'high', 'einbuchungen' => $ids]);
        $this->check($this->message($threadId, 'ich kann nicht'));

        return RecDispoDeclineCheck::query()->where('review_status', 'open')->latest('id')->firstOrFail();
    }

    public function test_reported_decline_is_open_in_the_event_with_its_days(): void
    {
        [$e, $event, $a, $t] = $this->scenario();
        $check = $this->reported($t, [$a]);

        $review = new DispoDeclineReview();
        $open = $review->openByEvent($event);

        $this->assertSame([$e], array_keys($open));
        $this->assertSame($check->id, $open[$e][0]['id']);
        $this->assertSame([$a], $open[$e][0]['assignment_ids']);
        $this->assertSame('ich kann nicht', $open[$e][0]['excerpt']);
        $this->assertSame([$event => 1], $review->openCountsByEvent([$event]));
    }

    public function test_confirmation_after_the_report_clears_it_one_before_does_not(): void
    {
        [$e, $event, $a, $t] = $this->scenario(); // bestaetigt eine Stunde VOR der Meldung
        $b = $this->assignment($event, $e, now()->addDays(4)->toDateString());
        $this->reported($t, [$a, $b]);
        $review = new DispoDeclineReview();

        $this->assertSame([$a, $b], $review->openByEvent($event)[$e][0]['assignment_ids'], 'Alte Bestaetigung hebt die Meldung nicht auf.');

        RecDispoAssignment::query()->whereKey($b)->update(['confirmed_at' => now()->addMinute()]);
        $this->assertSame([$a], $review->openByEvent($event)[$e][0]['assignment_ids'], 'Nur der spaeter bestaetigte Tag faellt raus.');

        RecDispoAssignment::query()->whereKey($a)->update(['confirmed_at' => now()->addMinute()]);
        $this->assertSame([], $review->openByEvent($event));
        $this->assertSame([], $review->openCountsByEvent([$event]));
    }

    public function test_declined_missing_or_deletion_marked_days_close_the_report(): void
    {
        foreach (['declined_at', 'missing_since', 'deletion_marked_at'] as $column) {
            $this->setUp();
            [, $event, $a, $t] = $this->scenario();
            $this->reported($t, [$a]);
            RecDispoAssignment::query()->whereKey($a)->update([$column => now()]);

            $this->assertSame([], (new DispoDeclineReview())->openByEvent($event), $column);
        }
    }

    public function test_dismiss_and_accept_only_touch_the_reports_of_that_person_and_event(): void
    {
        [$e, $event, $a, $t] = $this->scenario();
        $other = $this->employee('RG2', '+49 171 7654321');
        $o = $this->assignment($event, $other, now()->addDays(2)->toDateString());
        $mine = $this->reported($t, [$a]);
        $theirs = $this->reported($this->thread('491717654321'), [$o]);
        $review = new DispoDeclineReview();

        $this->assertSame(0, $review->dismissForPerson($event, $e, 42, []), 'Ohne gezeigte Meldung wird nichts verworfen.');
        $this->assertSame(0, $review->dismissForPerson($event, $e, 42, [$theirs->id]), 'Fremde Meldung ueber eigene Person: nichts.');
        $this->assertSame(1, $review->dismissForPerson($event, $e, 42, [$mine->id]));
        $mine->refresh();
        $this->assertSame(RecDispoDeclineCheck::REVIEW_DISMISSED, $mine->review_status);
        $this->assertSame(42, $mine->reviewed_by_user_id);
        $this->assertNotNull($mine->reviewed_at);
        $this->assertSame(RecDispoDeclineCheck::REVIEW_OPEN, $theirs->fresh()->review_status);

        $this->assertSame(1, $review->acceptForPerson($event, $other, 7));
        $this->assertSame(RecDispoDeclineCheck::REVIEW_ACCEPTED, $theirs->fresh()->review_status);
        $this->assertSame([], $review->openByEvent($event));
        $this->assertNull(RecDispoAssignment::find($a)->declined_at, 'Verwerfen/Annehmen sagt nie selbst ab.');
    }

    // ---- Nachtraege aus dem Review ----

    public function test_model_is_called_without_platform_tools_and_without_assistant_context(): void
    {
        [, , , $t] = $this->scenario();
        self::$llmAnswer = '{"absage": false}';

        $this->check($this->message($t, 'ich kann nicht'));

        $this->assertFalse(self::$llmOptions[0]['tools']);
        $this->assertFalse(self::$llmOptions[0]['with_context']);
    }

    public function test_empty_model_answer_is_retried_and_only_failed_on_the_last_attempt(): void
    {
        [, , , $t] = $this->scenario();
        $m = $this->message($t, 'ich kann nicht');
        self::$llmAnswer = '';

        try {
            $this->check($m, false);
            $this->fail('Leere Antwort muss vor dem letzten Versuch in die Wiederholung.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, RecDispoDeclineCheck::count());

        $this->check($m, true);
        $this->assertSame(RecDispoDeclineCheck::OUTCOME_FAILED, RecDispoDeclineCheck::sole()->outcome);
    }

    public function test_parallel_run_of_the_same_message_does_not_alarm_twice(): void
    {
        [$e, $event, $a, $t] = $this->scenario();
        $m = $this->message($t, 'ich kann nicht');
        self::$llmAnswer = json_encode(['absage' => true, 'sicherheit' => 'high', 'einbuchungen' => [$a]]);
        // Der "andere" Job schreibt seine Zeile, waehrend dieser noch auf das Modell wartet.
        self::$duringLlm = function () use ($e, $event, $a, $m) {
            RecDispoDeclineCheck::create([
                'team_id' => self::TEAM, 'filial_nr' => self::FILIALE, 'rec_dispo_event_id' => $event,
                'rec_employee_id' => $e, 'comms_whatsapp_message_id' => $m, 'outcome' => 'decline',
                'used_llm' => true, 'assignment_ids' => [$a], 'review_status' => 'open', 'alarm_message_id' => 1,
            ]);
        };

        $this->check($m);

        $this->assertSame([], $this->alarms, 'Der andere Lauf hat schon alarmiert.');
        $this->assertSame(1, RecDispoDeclineCheck::count());
    }

    public function test_failing_alarm_keeps_the_report_and_the_other_events(): void
    {
        [$e, $eventA, $a, $t] = $this->scenario();
        $eventB = $this->event('VA2');
        $b = $this->assignment($eventB, $e, now()->addDays(5)->toDateString());
        self::$llmAnswer = json_encode(['absage' => true, 'sicherheit' => 'high', 'einbuchungen' => [$a, $b]]);
        $this->alarmThrows = new \RuntimeException('Einstellungen nicht lesbar');

        $this->check($this->message($t, 'ich bin die ganze Woche krank'));

        $this->assertSame(2, RecDispoDeclineCheck::where('review_status', 'open')->count());
        $this->assertSame(0, RecDispoDeclineCheck::whereNotNull('alarm_message_id')->count());
    }

    public function test_report_arriving_after_the_banner_was_shown_survives_keine_absage(): void
    {
        [$e, $event, $a, $t] = $this->scenario();
        $shown = $this->reported($t, [$a]);
        $later = $this->reported($t, [$a]);

        (new DispoDeclineReview())->dismissForPerson($event, $e, 1, [$shown->id]);

        $this->assertSame(RecDispoDeclineCheck::REVIEW_DISMISSED, $shown->fresh()->review_status);
        $this->assertSame(RecDispoDeclineCheck::REVIEW_OPEN, $later->fresh()->review_status);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContactLink::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_24_000001_create_rec_dispo_filiale_settings_table.php'],
            [$own, 'database/migrations/2026_10_08_000002_add_decline_check_to_rec_dispo_filiale_settings.php'],
            [$own, 'database/migrations/2026_10_08_000003_create_rec_dispo_decline_checks_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    /** Wurzel des Composer-Pakets einer geladenen Klasse (Modulmuster). */
    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
