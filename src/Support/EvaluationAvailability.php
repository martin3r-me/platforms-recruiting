<?php

namespace Platform\Recruiting\Support;

/**
 * Freigabe der Bewertungserfassung: genau dann, wenn die Buchung auf
 * 'attended' steht.
 *
 * BEWUSST kein ODER mit "Employee existiert" (Spec §2): der einzige Bestandsfall
 * ohne attended-Buchung hat keine Bewertung, es gibt nichts zu retten. Und ein
 * fehlender Status ist NICHT durch einen beilaeufigen Klick zu heilen — ein
 * Wechsel auf 'attended' kann ueber den Compliance-Observer einen
 * HR-Schreibtisch-Fall anlegen und auto_pilot abschalten (Spec F15).
 */
final class EvaluationAvailability
{
    public static function isOpen(?string $bookingStatus): bool
    {
        return $bookingStatus === 'attended';
    }
}
