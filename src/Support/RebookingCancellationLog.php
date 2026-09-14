<?php

namespace Platform\Recruiting\Support;

/**
 * Marker fuer den Umbuchen-Pfad (Public\InterviewBooking::cancelAndRebook).
 *
 * Drei Wege stornieren im Namen des Bewerbers. Zwei hinterlassen einen
 * HR-Schreibtisch-Fall plus Log:
 *
 *   - cancelSchulung()          → 'cancelled_by_applicant'  (endgueltig absagen)
 *   - ReminderResponseHandler   → 'cancelled_by_reply'      (Reminder mit "Nein")
 *
 * Der Umbuchen-Flow hinterliess bis dahin nichts. Da er beim BETRETEN storniert
 * und nicht beim Abschluss, sah ein Abbruch in der Datenbank exakt aus wie eine
 * bewusste Absage (cancelled_by='applicant') — "hat es sich anders ueberlegt"
 * war von "ist steckengeblieben" nicht zu unterscheiden, und niemand bei HR
 * bekam den zweiten Fall zu sehen.
 *
 * Dieser Eintrag behebt den Abbruch NICHT, er macht ihn nur sichtbar. Siehe
 * docs/tickets/2026-09-14-umbuchen-flow-stiller-abbruch.md, Stufe 2.
 *
 * Der TYPE ist ein Vertrag nach aussen: Diagnose-Abfragen und HR-Werkzeuge
 * filtern darauf. Ein Rename laesst bestehende Auswertungen lautlos leerlaufen.
 */
final class RebookingCancellationLog
{
    public const TYPE = 'cancelled_for_rebooking';

    /**
     * Der Text muss ohne Zusatzwissen lesbar sein und darf nicht nach Absage
     * klingen — zum Zeitpunkt des Eintrags ist der Ausgang offen.
     */
    public static function summary(int $cancelledCount, ?string $interviewTitle, ?string $startsAt): string
    {
        $buchungen = $cancelledCount === 1 ? '1 Buchung' : "{$cancelledCount} Buchungen";

        $termin = trim(implode(' ', array_filter([
            trim((string) $interviewTitle),
            trim((string) $startsAt),
        ])));

        return 'Bewerber hat die Umbuchung ueber den Buchungslink begonnen — '
            . $buchungen . ' storniert'
            . ($termin !== '' ? " ({$termin})" : '')
            . '. Ausgang offen: eine neue Buchung ist damit noch nicht erfolgt.';
    }
}
