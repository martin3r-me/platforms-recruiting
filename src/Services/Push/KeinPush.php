<?php

namespace Platform\Recruiting\Services\Push;

/** Standard ohne Push-Dienst: nichts aktiv, nichts gesendet (Spec 6.7 — fehlende Schluessel lassen Wellen unberuehrt). */
final class KeinPush implements PortalPush
{
    public function aktiv(): bool
    {
        return false;
    }

    public function neueAngebote(array $employeeIds): void
    {
    }

    public function probe(int $employeeId): void
    {
    }
}
