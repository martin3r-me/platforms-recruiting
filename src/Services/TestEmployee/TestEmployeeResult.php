<?php

namespace Platform\Recruiting\Services\TestEmployee;

/**
 * Ergebnis von TestEmployeeCreator::create() — fuer die Ausgabe des Kommandos.
 */
final class TestEmployeeResult
{
    /**
     * @param list<string> $filled Feldnamen mit Wert
     * @param list<string> $empty  Feldnamen ohne Wert (bewusst leer oder ohne Profilwert)
     */
    public function __construct(
        public readonly int $applicantId,
        public readonly int $employeeId,
        public readonly int $contactId,
        public readonly ?string $portalUrl,
        public readonly array $filled,
        public readonly array $empty,
    ) {}
}
