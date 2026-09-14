<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\RebookingCancellationLog;

/**
 * Marker fuer den Umbuchen-Pfad.
 *
 * Hintergrund (Ticket 2026-09-14-umbuchen-flow-stiller-abbruch): Von den drei
 * Wegen, die im Namen des Bewerbers stornieren, hinterliessen zwei einen
 * HR-Schreibtisch-Fall plus Log — der Umbuchen-Flow gar nichts. In der Datenbank
 * sahen alle drei gleich aus (cancelled_by='applicant'), "hat es sich anders
 * ueberlegt" war von "ist steckengeblieben" nicht zu unterscheiden.
 *
 * Der TYPE ist der Vertrag nach aussen: Diagnose-Abfragen und HR-Werkzeuge
 * filtern darauf. Deshalb hier festgenagelt — ein stiller Rename wuerde jede
 * bestehende Auswertung lautlos leerlaufen lassen, ohne dass etwas bricht.
 */
class RebookingCancellationLogTest extends TestCase
{
    public function test_typ_ist_festgenagelt(): void
    {
        $this->assertSame('cancelled_for_rebooking', RebookingCancellationLog::TYPE);
    }

    public function test_typ_kollidiert_nicht_mit_den_bewussten_absagen(): void
    {
        // cancelled_by_applicant = "endgueltig absagen", cancelled_by_reply =
        // Reminder mit "Nein". Beide bedeuten eine Entscheidung, dieser Typ nicht.
        $this->assertNotContains(
            RebookingCancellationLog::TYPE,
            ['cancelled_by_applicant', 'cancelled_by_reply'],
        );
    }

    public function test_summary_nennt_anzahl_und_termin(): void
    {
        $summary = RebookingCancellationLog::summary(1, 'Vorstellungsrunde Servicekräfte Düsseldorf', '14.09.2026 16:00');

        $this->assertStringContainsString('1 Buchung', $summary);
        $this->assertStringContainsString('Vorstellungsrunde Servicekräfte Düsseldorf', $summary);
        $this->assertStringContainsString('14.09.2026 16:00', $summary);
    }

    public function test_summary_nutzt_plural_bei_mehreren_buchungen(): void
    {
        $summary = RebookingCancellationLog::summary(3, 'Irgendein Termin', '01.10.2026 09:00');

        $this->assertStringContainsString('3 Buchungen', $summary);
    }

    public function test_summary_kommt_ohne_termindaten_aus(): void
    {
        $summary = RebookingCancellationLog::summary(1, null, null);

        $this->assertStringContainsString('1 Buchung', $summary);
        $this->assertStringNotContainsString('()', $summary, 'Keine leeren Klammern wenn der Termin fehlt.');
    }

    public function test_summary_macht_den_offenen_ausgang_deutlich(): void
    {
        // Der Eintrag muss ohne Zusatzwissen lesbar sein: Er bedeutet NICHT
        // "hat abgesagt", sondern "Umbuchung begonnen, Ausgang offen".
        $this->assertStringContainsString(
            'Umbuchung',
            RebookingCancellationLog::summary(1, 'Termin', '01.10.2026 09:00'),
        );
    }
}
