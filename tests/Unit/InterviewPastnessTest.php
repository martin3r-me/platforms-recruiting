<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\InterviewPastness;

/**
 * "Vorbei" fuer die Termin- und die Schulungsliste — eine Regel, zwei
 * Ansichten (Terminuebersicht seit 09/2026, Teamleiter-Bewertung seit
 * 14.09.2026). Beide klappen Vergangenes ein, und beide muessen dieselbe
 * Grenze ziehen.
 *
 * Der Kern: Ein Termin ist vorbei, wenn sein ENDE hinter uns liegt, nicht
 * schon sein Start. Sonst verschwaende eine laufende Schulung genau in dem
 * Moment im eingeklappten Bereich, in dem jemand sie braucht.
 */
class InterviewPastnessTest extends TestCase
{
    private const JETZT = '2026-09-14 12:00:00';

    public function test_laufende_schulung_gilt_nicht_als_vergangen(): void
    {
        $this->assertFalse($this->istVorbei(
            startsAt: '2026-09-14 09:00:00',
            endsAt: '2026-09-14 17:00:00',
        ));
    }

    public function test_beendete_schulung_ist_vergangen(): void
    {
        $this->assertTrue($this->istVorbei(
            startsAt: '2026-09-13 09:00:00',
            endsAt: '2026-09-13 17:00:00',
        ));
    }

    public function test_ohne_ende_zaehlt_der_start(): void
    {
        $this->assertTrue($this->istVorbei(startsAt: '2026-09-14 11:00:00', endsAt: null));
        $this->assertFalse($this->istVorbei(startsAt: '2026-09-14 13:00:00', endsAt: null));
    }

    public function test_kuenftige_schulung_ist_nicht_vergangen(): void
    {
        $this->assertFalse($this->istVorbei(
            startsAt: '2026-09-20 09:00:00',
            endsAt: '2026-09-20 17:00:00',
        ));
    }

    private function istVorbei(string $startsAt, ?string $endsAt): bool
    {
        return InterviewPastness::isPast(
            new \DateTimeImmutable($startsAt),
            $endsAt === null ? null : new \DateTimeImmutable($endsAt),
            new \DateTimeImmutable(self::JETZT),
        );
    }
}
