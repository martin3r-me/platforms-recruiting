<?php

namespace Platform\Recruiting\Support;

/**
 * Storno-Metadaten bei Statuswechseln einer Buchung — EINE Regel fuer den
 * UI-Pfad (InterviewBookings\Index::updateStatus) und das MCP-Tool
 * (UpdateInterviewBookingTool):
 *
 *  - Wechsel AUF cancelled: cancelled_by='hr' + Zeitstempel. 'hr', weil beide
 *    Pfade HR-Handlungen sind — Selbst-Absagen des Bewerbers laufen ueber
 *    eigene Pfade (Public-Form, ReminderResponseHandler), die ihre Metadaten
 *    selbst setzen.
 *  - Wechsel WEG von cancelled: beide Felder abraeumen — eine reaktivierte
 *    Buchung darf kein Storno-Datum behalten, das nie wieder stimmt.
 *  - cancelled -> cancelled: NICHTS anfassen, sonst wuerde eine
 *    Bewerber-Absage (cancelled_by='applicant') still zu einer HR-Absage.
 *
 * Rueckgabe sind die zu mergenden Feld-Updates (leer = nichts zu tun).
 * Pure Entscheidungslogik ohne Framework (Muster SeatStandbyPolicy) — der
 * Zeitstempel kommt deshalb vom Aufrufer.
 *
 * @return array{cancelled_by?: ?string, cancelled_at?: ?string}
 */
final class BookingCancellationMeta
{
    public static function updatesFor(?string $oldStatus, string $newStatus, string $now): array
    {
        if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
            return ['cancelled_by' => 'hr', 'cancelled_at' => $now];
        }
        if ($newStatus !== 'cancelled' && $oldStatus === 'cancelled') {
            return ['cancelled_by' => null, 'cancelled_at' => null];
        }

        return [];
    }
}
