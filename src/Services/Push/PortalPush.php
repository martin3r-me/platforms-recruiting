<?php

namespace Platform\Recruiting\Services\Push;

/**
 * Push ans Mitarbeiter-Portal (Spec Jobs anbieten 6.7, Markus 13.11.2026).
 * Ausloeser sind nur Portal-Wellen; ein Fehler hier darf nie eine Welle
 * abbrechen — der Aufrufer faengt ab.
 */
interface PortalPush
{
    /** Gibt es einen Push-Dienst (Schluessel vorhanden)? Sonst kein Einschalt-Knopf im Portal. */
    public function aktiv(): bool;

    /**
     * „Neue Einsätze für dich“ an alle Abos dieser Personen.
     *
     * @param list<int> $employeeIds
     */
    public function neueAngebote(array $employeeIds): void;

    /** Probe-Benachrichtigung direkt nach dem Einschalten. */
    public function probe(int $employeeId): void;
}
