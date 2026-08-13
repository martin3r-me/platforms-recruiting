<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Observers\RecInterviewBookingWaitlistObserver;

/**
 * Eine Buchung nimmt den Bewerber von der Warteliste — Ort-Eintrag UND
 * Termin-Abo, egal fuer welchen Termin. Gleiche Semantik, die der oeffentliche
 * Pfad schon hat (Public/InterviewBooking.php:337-340); der Observer zieht sie
 * auf alle Buchungspfade (HR-Dialog, MCP-Tool, CSV-Sammelbuchung).
 */
final class BookingClosesWaitlistTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Pflicht: ohne Dispatcher feuert der Observer nicht und die
        // uuid-creating-Hooks der Models fallen aus.
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();

        $schema->create('rec_interview_bookings', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id');
            $t->integer('team_id')->nullable();
            $t->integer('created_by_user_id')->nullable();
            $t->string('status')->default('booked');
            $t->boolean('is_active')->default(true);
            $t->timestamp('booked_at')->nullable();
            $t->timestamp('seat_released_at')->nullable();
            $t->string('cancelled_by')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_interview_waitlist', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id')->nullable();
            $t->integer('team_id')->nullable();
            $t->boolean('armed')->default(false);
            $t->text('wunschorte')->nullable();
            $t->timestamp('enrolled_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
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

        RecInterviewBookingWaitlistObserver::register();
    }

    protected function tearDown(): void
    {
        // Listener abraeumen, sonst sehen spaetere Testklassen im geteilten
        // Prozess unsere Observer-Registrierung.
        RecInterviewBooking::flushEventListeners();

        foreach (['rec_interview_bookings', 'rec_interview_waitlist', 'rec_auto_pilot_logs'] as $table) {
            Capsule::schema()->drop($table);
        }

        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');

        parent::tearDown();
    }

    private function warteliste(array $overrides = []): int
    {
        return Capsule::table('rec_interview_waitlist')->insertGetId(array_merge([
            'rec_applicant_id' => 42,
            'rec_interview_id' => null,
            'armed' => false,
            'enrolled_at' => '2026-08-01 09:00:00',
        ], $overrides));
    }

    private function offen(int $id): bool
    {
        $row = Capsule::table('rec_interview_waitlist')->where('id', $id)->first();

        return $row->fulfilled_at === null && $row->cancelled_at === null;
    }

    private function buchung(array $overrides = []): RecInterviewBooking
    {
        return RecInterviewBooking::create(array_merge([
            'rec_applicant_id'   => 42,
            'rec_interview_id'   => 7,
            'status'             => 'booked',
            'created_by_user_id' => 1,
        ], $overrides));
    }

    public function test_ort_eintrag_wird_geschlossen(): void
    {
        $eintrag = $this->warteliste();

        $this->buchung();

        $this->assertFalse($this->offen($eintrag));
    }

    public function test_termin_abo_wird_auch_geschlossen(): void
    {
        $abo = $this->warteliste(['rec_interview_id' => 99, 'armed' => true]);

        $this->buchung();

        $this->assertFalse($this->offen($abo));
    }

    public function test_fremder_bewerber_bleibt_unberuehrt(): void
    {
        $fremd = $this->warteliste(['rec_applicant_id' => 43]);

        $this->buchung();

        $this->assertTrue($this->offen($fremd));
    }

    public function test_stornierte_buchung_schliesst_nichts(): void
    {
        $eintrag = $this->warteliste();

        $this->buchung(['status' => 'cancelled']);

        $this->assertTrue($this->offen($eintrag));
    }

    public function test_reaktivierte_buchung_schliesst_ebenfalls(): void
    {
        // Der HR-Dialog nutzt updateOrCreate und kann eine alte stornierte
        // Zeile wiederbeleben — dann ist wasRecentlyCreated false.
        $buchung = $this->buchung(['status' => 'cancelled']);
        $eintrag = $this->warteliste();

        $buchung->status = 'booked';
        $buchung->save();

        $this->assertFalse($this->offen($eintrag));
    }

    public function test_nur_die_hr_buchung_wird_geloggt(): void
    {
        $this->warteliste();
        $this->buchung();

        $this->warteliste(['rec_applicant_id' => 44]);
        $this->buchung(['rec_applicant_id' => 44, 'created_by_user_id' => null]);

        $hr = Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', 42)->first();

        $this->assertSame('waitlist_closed', $hr->type);
        $this->assertStringContainsString('HR', $hr->summary);
        // Selbstbuchung: Warteliste zu, aber kein Log — sonst haette jede
        // Bewerber-Timeline eine neue Zeile ohne Aussage.
        $this->assertSame(0, Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', 44)->count());
    }

    public function test_selbstbuchung_schliesst_die_warteliste_trotzdem(): void
    {
        $eintrag = $this->warteliste();

        $this->buchung(['created_by_user_id' => null]);

        $this->assertFalse($this->offen($eintrag));
    }

    public function test_ohne_offene_eintraege_kein_log(): void
    {
        $this->buchung();

        $this->assertSame(0, Capsule::table('rec_auto_pilot_logs')->count());
    }

    public function test_bereits_erfuellter_eintrag_wird_nicht_neu_gestempelt(): void
    {
        $alt = $this->warteliste(['fulfilled_at' => '2026-07-01 08:00:00']);

        $this->buchung();

        $row = Capsule::table('rec_interview_waitlist')->where('id', $alt)->first();
        $this->assertStringStartsWith('2026-07-01', (string) $row->fulfilled_at);
    }
}
