<?php

namespace Platform\Recruiting\Services\Comms;

final class ThemenAuswahlEmpfaenger
{
    public function __construct(private readonly ?EmployeeSenderResolver $resolver = null) {}

    /** Aktiver Mitarbeiter hinter dieser Nummer, eindeutig — sonst null. Eine Stelle, damit die Personen-Klammer sie später ersetzen kann (S-Themen §2.1). */
    public function aktiverMitarbeiter(string $phone, int $teamId): ?int
    {
        $r = ($this->resolver ?? app(EmployeeSenderResolver::class))->resolve($phone, $teamId);

        return $r['status'] === EmployeeSenderResolver::EMPLOYEE ? $r['employee_id'] : null;
    }
}
