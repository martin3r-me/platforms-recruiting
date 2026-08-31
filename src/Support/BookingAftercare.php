<?php

namespace Platform\Recruiting\Support;

/**
 * Nachpflege-Regel fuer Buchungen an VERGANGENEN Terminen: welche Buchung
 * wartet noch auf eine Entscheidung (teilgenommen / nicht erschienen /
 * aussortiert / storniert)?
 *
 * Anlass (Kundenmail 31.08.2026): sechs Tage nach der Schulung standen drei
 * Buchungen noch auf gebucht/registriert/bestaetigt — unsichtbar, bis der
 * Kunde sie beim Nachrechnen der Statistik fand. Die Termin-Uebersicht zeigt
 * darum je vergangenem Termin, wie viele Buchungen noch keinen Endzustand
 * haben.
 *
 * Fail-visible: ein UNBEKANNTER Status gilt als nachzupflegen — ein Wert, den
 * niemand kennt, ist erst recht keine Entscheidung. Deshalb ist die Liste hier
 * die der ENDzustaende, nicht die der laufenden.
 *
 * Pure Entscheidungslogik ohne Framework (Muster SeatStandbyPolicy).
 */
final class BookingAftercare
{
    /** @var list<string> Endzustaende — hier ist nichts mehr zu entscheiden. */
    public const FINAL_STATUSES = ['attended', 'no_show', 'rejected_on_site', 'cancelled'];

    public static function needsResolution(?string $status): bool
    {
        return !in_array($status, self::FINAL_STATUSES, true);
    }
}
