<?php

namespace Platform\Recruiting\Support;

/**
 * Zugriffsentscheidung fuer den Portal-Download eines Dokuments (Spec §5.3).
 * Reihenfolge wie DispoAttachmentAccess: unbekannte Zustellung → 404 (kein
 * Hinweis, ob es sie gibt); keine Sitzung → 403; Portalsperre → 403;
 * zurueckgezogen → 404; sonst 200.
 */
final class DokumentZugriff
{
    public static function entscheide(bool $empfaengerGefunden, bool $sitzungGueltig, bool $portalGesperrt, bool $zurueckgezogen): int
    {
        if (!$empfaengerGefunden) {
            return 404;
        }
        if (!$sitzungGueltig || $portalGesperrt) {
            return 403;
        }

        return $zurueckgezogen ? 404 : 200;
    }
}
