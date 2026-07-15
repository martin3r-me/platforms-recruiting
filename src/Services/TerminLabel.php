<?php

namespace Platform\Recruiting\Services;

use Carbon\CarbonInterface;

/**
 * Formatiert einen Schulungstermin für Bewerber-Nachrichten
 * ({{termin}}-Variable im WhatsApp-Template).
 *
 * Bewusst explizites ->locale('de') statt Verlass auf die globale
 * Carbon-Locale: die kommt aus APP_LOCALE (Default 'en') und wäre im
 * Queue-Worker eine stille Env-Abhängigkeit. Nur starts_at — ends_at
 * ist nullable und nicht garantiert gefüllt.
 */
class TerminLabel
{
    public static function format(CarbonInterface $startsAt): string
    {
        return $startsAt->locale('de')->translatedFormat('l, j. F Y')
            . ' um ' . $startsAt->format('H:i') . ' Uhr';
    }
}
