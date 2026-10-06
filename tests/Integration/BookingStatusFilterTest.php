<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Support\BookingStatusFilter;

/**
 * Status-Filter der Teilnehmerliste (06.10.2026): „Keine Reaktion" als eigener
 * Eintrag, „Gebucht" zeigt nur noch die mit Platz. Die uebrigen Eintraege
 * verhalten sich wie vorher.
 */
final class BookingStatusFilterTest extends TestCase
{
    private const TERMIN = 85;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('rec_interview_bookings', function ($t) {
            $t->increments('id');
            $t->integer('rec_interview_id');
            $t->integer('rec_applicant_id');
            $t->string('status');
            $t->dateTime('seat_released_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        $zeilen = [
            // id => [termin, bewerber, status, freigegeben]
            1 => [self::TERMIN, 101, 'booked', null],
            2 => [self::TERMIN, 102, 'booked', '2026-10-04 08:00:00'],
            3 => [self::TERMIN, 103, 'confirmed', null],
            4 => [self::TERMIN, 104, 'cancelled', null],
            5 => [self::TERMIN, 105, 'cancelled', null],
            6 => [99, 105, 'booked', null],            // spaetere Buchung → 5 ist umgebucht
            7 => [self::TERMIN, 107, 'attended', null],
            8 => [self::TERMIN, 108, 'booked', '2026-10-05 08:00:00'],
        ];
        foreach ($zeilen as $id => [$termin, $bewerber, $status, $frei]) {
            Capsule::table('rec_interview_bookings')->insert([
                'id' => $id, 'rec_interview_id' => $termin, 'rec_applicant_id' => $bewerber,
                'status' => $status, 'seat_released_at' => $frei,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Capsule::schema()->drop('rec_interview_bookings');
        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');
        parent::tearDown();
    }

    private function ids(string $filter): array
    {
        $query = RecInterviewBooking::query()->where('rec_interview_id', self::TERMIN)->orderBy('id');

        return BookingStatusFilter::apply($query, $filter)->pluck('id')->all();
    }

    public function test_keine_reaktion_zeigt_nur_gebuchte_mit_freigegebenem_platz(): void
    {
        $this->assertSame([2, 8], $this->ids('standby'));
    }

    public function test_gebucht_zeigt_keine_reaktion_nicht_mehr(): void
    {
        $this->assertSame([1], $this->ids('booked'));
    }

    public function test_gebucht_und_keine_reaktion_ergeben_zusammen_alle_gebuchten(): void
    {
        $alleGebuchten = RecInterviewBooking::query()
            ->where('rec_interview_id', self::TERMIN)->where('status', 'booked')
            ->orderBy('id')->pluck('id')->all();
        $zusammen = array_merge($this->ids('booked'), $this->ids('standby'));
        sort($zusammen);

        $this->assertSame($alleGebuchten, $zusammen);
    }

    public function test_bisherige_eintraege_unveraendert(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 7, 8], $this->ids('all'));
        $this->assertSame([1, 2, 3, 4, 5, 7, 8], $this->ids(''));
        $this->assertSame([3], $this->ids('confirmed'));
        $this->assertSame([7], $this->ids('attended'));
        $this->assertSame([4], $this->ids('cancelled'));
        $this->assertSame([5], $this->ids('rebooked'));
    }

    public function test_jede_option_hat_einen_eindeutigen_wert(): void
    {
        $werte = array_column(BookingStatusFilter::OPTIONS, 'value');
        $this->assertSame(count($werte), count(array_unique($werte)));
        $this->assertContains('standby', $werte);
    }
}
