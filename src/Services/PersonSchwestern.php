<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PersonDatensatz;

/**
 * Die AKTIVEN, nicht-Test-Schwestern einer Akte (gleiche Person ueber
 * PersonScopeResolver), juengste zuerst (Spec 2026-10-22 §3). Eine Stelle
 * fuer Portal-Anzeige (PortalShell) und Speicher-Waechter
 * (PortalProfileWriter) — sonst zeigt das Blatt einen Wert der Schwester,
 * den der Waechter beim Speichern nicht kennt (Review I1, 22.10.).
 */
final class PersonSchwestern
{
    public function __construct(private readonly ?PersonScopeResolver $scope = null)
    {
    }

    /** @return list<RecEmployee> */
    public function aktive(RecEmployee $employee): array
    {
        $id = (int) $employee->id;
        $ids = array_values(array_diff(($this->scope ?? new PersonScopeResolver())->forEmployee($employee)['ids'], [$id]));
        if ($ids === []) {
            return [];
        }

        return RecEmployee::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->where('is_test', false)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * Eigener Wert, sonst der erste gefuellte der Schwestern — OHNE
     * Feld-Filter (der Aufrufer entscheidet, welche Felder personenweit
     * gelten). Leer wie PersonDatensatz::istLeer.
     *
     * @param list<RecEmployee> $schwestern
     */
    public static function ersterWert(RecEmployee $employee, string $feld, array $schwestern): mixed
    {
        $eigen = $employee->getAttribute($feld);
        if (!PersonDatensatz::istLeer($eigen)) {
            return $eigen;
        }
        foreach ($schwestern as $s) {
            $w = $s->getAttribute($feld);
            if (!PersonDatensatz::istLeer($w)) {
                return $w;
            }
        }

        return $eigen;
    }
}
