<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecEmployee;

/**
 * EINZIGE Tuer vom Dispo-Code zum Recruiting-Personal (Entkopplungs-
 * Leitplanke 3 der Spec): liefert die PNr-Map fuers Matching. Beim
 * spaeteren Staffing-Modul-Auszug wird genau diese Klasse gegen den
 * dortigen Personen-Verzeichnis-Adapter getauscht.
 */
class DispoEmployeeDirectory
{
    /** @return array<string, int> personnel_number => employee_id */
    public function map(): array
    {
        return RecEmployee::query()
            ->whereNotNull('personnel_number')
            ->where('personnel_number', '!=', '')
            ->pluck('id', 'personnel_number')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
