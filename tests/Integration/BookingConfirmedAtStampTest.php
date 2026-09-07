<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * confirmed_at-Stempel: gesetzt beim Wechsel AUF 'confirmed', nie wieder
 * angefasst. Als MUTATOR statt Observer, damit der Stempel auch dort greift,
 * wo kein Event-Dispatcher laeuft (Integration-Suite, Konsole) — und weil er
 * so jeden Schreibweg automatisch abdeckt (Reminder-"Ja", HR-Dropdown,
 * MCP-Tool, Phasen-Hook), ohne dass einer davon angefasst wird.
 *
 * Kein Capsule noetig: der Mutator arbeitet auf dem Attribut-Array, nicht auf
 * der Datenbank. In der Integration-Suite, weil die Klasse unter Test ein
 * Eloquent-Model ist (die Unit-Suite bleibt frameworkfrei).
 */
final class BookingConfirmedAtStampTest extends TestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    public function test_wechsel_auf_confirmed_stempelt(): void
    {
        $booking = new RecInterviewBooking(['status' => 'booked']);
        $this->assertNull($booking->confirmed_at);

        $booking->status = 'confirmed';
        $this->assertSame('2026-09-07 10:00:00', $booking->confirmed_at?->format('Y-m-d H:i:s'));
    }

    public function test_stempel_ueberlebt_die_ueberschreibung_nach_der_schulung(): void
    {
        $booking = new RecInterviewBooking(['status' => 'confirmed']);
        $booking->status = 'attended';

        $this->assertSame('2026-09-07 10:00:00', $booking->confirmed_at?->format('Y-m-d H:i:s'),
            'genau der Verlust, an dem die alte Spalte gestorben ist');
    }

    public function test_erster_stempel_gewinnt(): void
    {
        $booking = new RecInterviewBooking(['status' => 'confirmed']);

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:00:00'));
        $booking->status = 'booked';
        $booking->status = 'confirmed';

        $this->assertSame('2026-09-07 10:00:00', $booking->confirmed_at?->format('Y-m-d H:i:s'),
            'erneutes Bestaetigen ueberschreibt den historischen Zeitpunkt nicht');
    }

    public function test_andere_status_stempeln_nicht(): void
    {
        $booking = new RecInterviewBooking(['status' => 'booked']);
        foreach (['registered', 'attended', 'no_show', 'cancelled', 'rejected_on_site'] as $status) {
            $booking->status = $status;
        }
        $this->assertNull($booking->confirmed_at);
    }
}
