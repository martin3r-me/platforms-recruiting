<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\BookingAftercare;

/**
 * Nachpflege-Regel: welche Buchung braucht nach einem VERGANGENEN Termin noch
 * eine Entscheidung? Live-Befund (Kundenmail 31.08.2026): eine Buchung stand
 * sechs Tage nach der Schulung auf "registriert" (DUS), zwei weitere auf
 * "gebucht"/"bestaetigt" (MGL) — unsichtbar, bis der Kunde sie in einer Mail
 * fand. Pure Klasse ohne Framework (Muster SeatStandbyPolicy).
 */
final class BookingAftercareTest extends TestCase
{
    public function test_laufende_status_brauchen_nachpflege(): void
    {
        foreach (['booked', 'registered', 'confirmed'] as $s) {
            $this->assertTrue(BookingAftercare::needsResolution($s), $s);
        }
    }

    public function test_endzustaende_brauchen_keine(): void
    {
        foreach (['attended', 'no_show', 'rejected_on_site', 'cancelled'] as $s) {
            $this->assertFalse(BookingAftercare::needsResolution($s), $s);
        }
    }

    public function test_storno_ohne_begruendung_nur_solange_der_termin_laeuft(): void
    {
        // Storno-Bremse (Markus, 01.09.2026): nach Terminende ist „Abgesagt"
        // fast immer die falsche Pflege fuer „war nicht da" (MGL-Befund: null
        // No-Shows, alles storniert). Vor dem Termin bleibt Storno der normale
        // Weg; danach nur noch ueber die bewusste Aktion mit Pflicht-Begruendung.
        $this->assertTrue(BookingAftercare::allowsPlainCancellation(false), 'Termin laeuft noch');
        $this->assertFalse(BookingAftercare::allowsPlainCancellation(true), 'Termin vorbei — nur mit Begruendung');
    }

    public function test_unbekannter_status_gilt_als_nachzupflegen(): void
    {
        // Ein Wert, den niemand kennt, ist erst recht keine Entscheidung —
        // fail-visible statt still final.
        $this->assertTrue(BookingAftercare::needsResolution('weird_value'));
        $this->assertTrue(BookingAftercare::needsResolution(null));
    }
}
