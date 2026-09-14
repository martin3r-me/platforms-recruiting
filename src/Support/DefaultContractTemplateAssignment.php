<?php

namespace Platform\Recruiting\Support;

/**
 * "Ab Status Teilgenommen wird die AV-default-Vorlage zugewiesen — HR waehlt
 * nichts mehr aus." Die Entscheidung als reine Funktion, weil sie seit dem
 * 14.09.2026 von zwei Oberflaechen ausgeloest wird: der grossen Nachbereitung
 * und der schlanken Teamleiter-Ansicht.
 */
final class DefaultContractTemplateAssignment
{
    /**
     * Die zu setzende Vorlagen-id — oder null, wenn nichts zu tun ist.
     * Eine bereits gewaehlte Vorlage wird NIE ueberschrieben: sonst setzte
     * ein zweiter Klick auf "Teilgenommen" eine bewusst gewaehlte
     * Zuschlags-Variante auf default zurueck.
     */
    public static function resolve(?int $currentTemplateId, ?int $defaultTemplateId): ?int
    {
        if ($currentTemplateId) {
            return null;
        }

        return $defaultTemplateId ?: null;
    }
}
