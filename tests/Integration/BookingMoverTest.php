<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\BookingMover;

/**
 * Teilnehmer zwischen Schulungsterminen verschieben (05.10.2026).
 *
 * Kernregeln: nur innerhalb derselben Stelle und desselben Teams, nur in
 * kuenftige aktive Termine, nur Buchungen vor der Schulung, Kapazitaet des
 * Ziels wird respektiert. Die Buchungszeile wandert mit (Status, Bestaetigung,
 * Notizen bleiben), nur der Erinnerungsstempel wird zurueckgesetzt.
 */
final class BookingMoverTest extends TestCase
{
    private Capsule $capsule;

    /** @var list<array{0:string,1:int}> */
    private array $waitlistPings = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00');

        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();

        $schema->create('rec_interviews', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('team_id');
            $t->integer('rec_position_id')->nullable();
            $t->string('title')->nullable();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at')->nullable();
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
            $t->integer('moved_from_interview_id')->nullable();
            $t->dateTime('moved_at')->nullable();
            $t->integer('moved_by_user_id')->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('team_id')->nullable();
            $t->integer('created_by_user_id')->nullable();
            $t->string('status')->default('booked');
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('booked_at')->nullable();
            $t->dateTime('reminder_sent_at')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->dateTime('seat_released_at')->nullable();
            $t->string('cancelled_by')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['rec_interview_id', 'rec_applicant_id']);
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
        foreach (['rec_interviews', 'rec_interview_bookings', 'rec_auto_pilot_logs'] as $table) {
            Capsule::schema()->drop($table);
        }
        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function mover(): BookingMover
    {
        $pings = &$this->waitlistPings;

        return new class($pings) extends BookingMover {
            public function __construct(private array &$pings) {}

            protected function notifyWaitlistSeatFreed(int $interviewId): void
            {
                $this->pings[] = ['freed', $interviewId];
            }

            protected function rearmWaitlistIfFull(int $interviewId): void
            {
                $this->pings[] = ['rearm', $interviewId];
            }
        };
    }

    private function termin(array $o = []): int
    {
        return Capsule::table('rec_interviews')->insertGetId(array_merge([
            'team_id'          => 1,
            'rec_position_id'  => 16,
            'title'            => 'Schulung',
            'starts_at'        => '2026-10-20 09:00:00',
            'max_participants' => 100,
            'status'           => 'planned',
            'is_active'        => true,
        ], $o));
    }

    private function buchung(int $interviewId, int $applicantId, array $o = []): int
    {
        return Capsule::table('rec_interview_bookings')->insertGetId(array_merge([
            'uuid'             => 'u-' . $interviewId . '-' . $applicantId,
            'rec_interview_id' => $interviewId,
            'rec_applicant_id' => $applicantId,
            'team_id'          => 1,
            'status'           => 'booked',
            'booked_at'        => '2026-10-01 12:00:00',
        ], $o));
    }

    private function row(int $id): object
    {
        return Capsule::table('rec_interview_bookings')->where('id', $id)->first();
    }

    private function move(int $from, array $ids, int $to, ?string $kommentar = null)
    {
        return $this->mover()->move($from, $ids, $to, teamId: 1, userId: 7, userName: 'Clara', comment: $kommentar);
    }

    public function test_buchung_wandert_mit_status_notizen_und_bestaetigung(): void
    {
        $a = $this->termin();
        $b = $this->termin(['title' => 'Logistik', 'starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42, [
            'status' => 'confirmed', 'notes' => 'kommt mit Bus',
            'confirmed_at' => '2026-10-02 08:00:00', 'reminder_sent_at' => '2026-10-02 07:00:00',
        ]);

        $result = $this->move($a, [$id], $b, 'Logistik-Gruppe');

        $this->assertSame([$id], $result->moved);
        $this->assertSame([], $result->skipped);

        $row = $this->row($id);
        $this->assertSame($b, (int) $row->rec_interview_id);
        $this->assertSame('confirmed', $row->status);
        $this->assertSame('kommt mit Bus', $row->notes);
        $this->assertSame('2026-10-02 08:00:00', $row->confirmed_at);
        $this->assertNull($row->reminder_sent_at, 'Erinnerung muss fuer den neuen Termin erneut laufen');
        $this->assertSame($a, (int) $row->moved_from_interview_id);
        $this->assertSame(7, (int) $row->moved_by_user_id);
        $this->assertSame('2026-10-05 10:00:00', $row->moved_at);
        $this->assertSame(1, Capsule::table('rec_interview_bookings')->count(), 'kein Storno plus Neuanlage');
    }

    public function test_verlaufseintrag_mit_user_und_kommentar(): void
    {
        $a = $this->termin(['title' => 'Schulung MGL']);
        $b = $this->termin(['title' => 'Logistik MGL', 'starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42);

        $this->move($a, [$id], $b, 'Logistik-Gruppe');

        $log = Capsule::table('rec_auto_pilot_logs')->first();
        $this->assertSame(42, (int) $log->rec_applicant_id);
        $this->assertSame('booking_moved', $log->type);
        $this->assertStringContainsString('Schulung MGL', $log->summary);
        $this->assertStringContainsString('Logistik MGL', $log->summary);
        $this->assertStringContainsString('Clara', $log->summary);
        $this->assertStringContainsString('Logistik-Gruppe', $log->summary);

        $details = json_decode($log->details, true);
        $this->assertSame($id, $details['booking_id']);
        $this->assertSame($a, $details['from_interview_id']);
        $this->assertSame($b, $details['to_interview_id']);
        $this->assertSame(7, $details['user_id']);
        $this->assertSame('Logistik-Gruppe', $details['comment']);
    }

    public function test_ohne_kommentar_kein_leerer_kommentar_im_text(): void
    {
        $a = $this->termin();
        $b = $this->termin(['starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42);

        $this->move($a, [$id], $b, '   ');

        $log = Capsule::table('rec_auto_pilot_logs')->first();
        $this->assertStringNotContainsString('Kommentar', $log->summary);
        $this->assertNull(json_decode($log->details, true)['comment']);
    }

    public function test_andere_stelle_wird_abgelehnt(): void
    {
        $a = $this->termin(['rec_position_id' => 16]);
        $b = $this->termin(['rec_position_id' => 17, 'starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42);

        $result = $this->move($a, [$id], $b);

        $this->assertNotNull($result->error);
        $this->assertSame([], $result->moved);
        $this->assertSame($a, (int) $this->row($id)->rec_interview_id);
        $this->assertSame(0, Capsule::table('rec_auto_pilot_logs')->count());
    }

    public function test_ohne_stelle_am_quelltermin_geht_nichts(): void
    {
        $a = $this->termin(['rec_position_id' => null]);
        $b = $this->termin(['rec_position_id' => null, 'starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42);

        $this->assertNotNull($this->move($a, [$id], $b)->error);
        $this->assertSame([], $this->mover()->targetsFor($a, 1)->pluck('id')->all());
    }

    public function test_fremdes_team_wird_abgelehnt(): void
    {
        $a = $this->termin();
        $b = $this->termin(['team_id' => 2, 'starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42);

        $this->assertNotNull($this->move($a, [$id], $b)->error);
        $this->assertSame($a, (int) $this->row($id)->rec_interview_id);
    }

    public function test_quelltermin_aus_fremdem_team_wird_abgelehnt(): void
    {
        $a = $this->termin(['team_id' => 2]);
        $b = $this->termin(['team_id' => 2, 'starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42, ['team_id' => 2]);

        $this->assertNotNull($this->move($a, [$id], $b)->error);
        $this->assertSame($a, (int) $this->row($id)->rec_interview_id);
    }

    public function test_vergangener_abgesagter_inaktiver_oder_gleicher_zieltermin_wird_abgelehnt(): void
    {
        $a = $this->termin();
        $vorbei = $this->termin(['starts_at' => '2026-10-04 09:00:00']);
        $abgesagt = $this->termin(['status' => 'cancelled', 'starts_at' => '2026-10-21 09:00:00']);
        $inaktiv = $this->termin(['is_active' => false, 'starts_at' => '2026-10-22 09:00:00']);
        $id = $this->buchung($a, 42);

        foreach ([$vorbei, $abgesagt, $inaktiv, $a] as $ziel) {
            $this->assertNotNull($this->move($a, [$id], $ziel)->error, "Ziel {$ziel}");
        }
        $this->assertSame($a, (int) $this->row($id)->rec_interview_id);
    }

    public function test_zielauswahl_zeigt_nur_passende_termine(): void
    {
        $a = $this->termin();
        $ok = $this->termin(['title' => 'Logistik', 'starts_at' => '2026-10-21 09:00:00']);
        $this->termin(['rec_position_id' => 17, 'starts_at' => '2026-10-21 09:00:00']);
        $this->termin(['team_id' => 2, 'starts_at' => '2026-10-21 09:00:00']);
        $this->termin(['starts_at' => '2026-10-04 09:00:00']);
        $this->termin(['status' => 'cancelled', 'starts_at' => '2026-10-21 09:00:00']);
        $this->termin(['is_active' => false, 'starts_at' => '2026-10-21 09:00:00']);

        $this->assertSame([$ok], $this->mover()->targetsFor($a, 1)->pluck('id')->all());
    }

    public function test_nur_buchungen_vor_der_schulung_wandern(): void
    {
        $a = $this->termin();
        $b = $this->termin(['starts_at' => '2026-10-21 09:00:00']);
        $ids = [];
        foreach (['booked', 'registered', 'confirmed', 'attended', 'no_show', 'cancelled', 'rejected_on_site'] as $i => $status) {
            $ids[$status] = $this->buchung($a, 100 + $i, ['status' => $status]);
        }

        $result = $this->move($a, array_values($ids), $b);

        $this->assertEqualsCanonicalizing([$ids['booked'], $ids['registered'], $ids['confirmed']], $result->moved);
        $this->assertEqualsCanonicalizing(
            [$ids['attended'], $ids['no_show'], $ids['cancelled'], $ids['rejected_on_site']],
            array_keys($result->skipped),
        );
    }

    public function test_buchung_aus_anderem_termin_wird_nicht_angefasst(): void
    {
        $a = $this->termin();
        $b = $this->termin(['starts_at' => '2026-10-21 09:00:00']);
        $c = $this->termin(['starts_at' => '2026-10-22 09:00:00']);
        $fremd = $this->buchung($c, 42);

        $result = $this->move($a, [$fremd], $b);

        $this->assertSame([], $result->moved);
        $this->assertSame($c, (int) $this->row($fremd)->rec_interview_id);
    }

    public function test_kapazitaet_des_ziels_wird_respektiert_teilweise_verschoben(): void
    {
        $a = $this->termin();
        $b = $this->termin(['max_participants' => 3, 'starts_at' => '2026-10-21 09:00:00']);
        $this->buchung($b, 1);
        $ids = [$this->buchung($a, 42), $this->buchung($a, 43), $this->buchung($a, 44)];

        $result = $this->move($a, $ids, $b);

        $this->assertSame([$ids[0], $ids[1]], $result->moved);
        $this->assertSame([$ids[2]], array_keys($result->skipped));
        $this->assertStringContainsString('voll', $result->skipped[$ids[2]]);
        $this->assertSame($a, (int) $this->row($ids[2])->rec_interview_id);
    }

    public function test_standby_buchung_belegt_im_ziel_keinen_platz(): void
    {
        $a = $this->termin();
        $b = $this->termin(['max_participants' => 1, 'starts_at' => '2026-10-21 09:00:00']);
        $this->buchung($b, 1);
        $standby = $this->buchung($a, 42, ['seat_released_at' => '2026-10-03 10:00:00']);

        $result = $this->move($a, [$standby], $b);

        $this->assertSame([$standby], $result->moved);
        $this->assertNotNull($this->row($standby)->seat_released_at);
    }

    public function test_alte_buchung_desselben_bewerbers_im_ziel_blockiert(): void
    {
        $a = $this->termin();
        $b = $this->termin(['starts_at' => '2026-10-21 09:00:00']);
        $this->buchung($b, 42, ['status' => 'cancelled', 'uuid' => 'alt']);
        $id = $this->buchung($a, 42);

        $result = $this->move($a, [$id], $b);

        $this->assertSame([], $result->moved);
        $this->assertArrayHasKey($id, $result->skipped);
        $this->assertSame($a, (int) $this->row($id)->rec_interview_id);
    }

    public function test_auch_geloeschte_alte_buchung_im_ziel_blockiert(): void
    {
        $a = $this->termin();
        $b = $this->termin(['starts_at' => '2026-10-21 09:00:00']);
        $this->buchung($b, 42, ['deleted_at' => '2026-10-01 00:00:00', 'uuid' => 'alt']);
        $id = $this->buchung($a, 42);

        $result = $this->move($a, [$id], $b);

        $this->assertSame([], $result->moved);
        $this->assertArrayHasKey($id, $result->skipped);
    }

    public function test_warteliste_quelle_frei_und_ziel_rearm(): void
    {
        $a = $this->termin();
        $b = $this->termin(['starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42);

        $this->move($a, [$id], $b);

        $this->assertSame([['freed', $a], ['rearm', $b]], $this->waitlistPings);
    }

    public function test_ohne_verschobene_buchung_keine_wartelisten_anstoesse(): void
    {
        $a = $this->termin();
        $b = $this->termin(['starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42, ['status' => 'attended']);

        $this->move($a, [$id], $b);

        $this->assertSame([], $this->waitlistPings);
    }

    public function test_hin_und_zurueck_haelt_letzten_umzug_fest(): void
    {
        $a = $this->termin();
        $b = $this->termin(['starts_at' => '2026-10-21 09:00:00']);
        $id = $this->buchung($a, 42);

        $this->move($a, [$id], $b);
        $this->move($b, [$id], $a);

        $row = $this->row($id);
        $this->assertSame($a, (int) $row->rec_interview_id);
        $this->assertSame($b, (int) $row->moved_from_interview_id);
        $this->assertSame(2, Capsule::table('rec_auto_pilot_logs')->count());
    }
}
