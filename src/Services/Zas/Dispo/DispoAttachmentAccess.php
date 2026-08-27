<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Reine Zugriffsentscheidung fuer den oeffentlichen Anhang-Download (Runde 3, #8).
 * Reihenfolge ist Absicht: unbekannter Token -> 404 (kein Hinweis, ob es den
 * Anhang gibt); Portalsperre -> 403 (Eskalations-Stufe 3, analog Einsatz-Seite);
 * fremder/fehlender Anhang -> 404 (nie 403 — keine Existenz-Orakel).
 */
final class DispoAttachmentAccess
{
    public static function decide(bool $employeeFound, bool $portalLocked, bool $attachmentOwned): int
    {
        if (!$employeeFound) {
            return 404;
        }
        if ($portalLocked) {
            return 403;
        }
        return $attachmentOwned ? 200 : 404;
    }
}
