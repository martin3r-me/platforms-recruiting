<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecEmployee;

/**
 * Darf die Person auf /jobs/{token} zusagen?
 *
 * Gesperrt, wenn das Portal irgendeines Datensatzes der Identitaets-Gruppe
 * gesperrt ist — oder wenn der Datensatz hinter dem Link inaktiv ist
 * (ZAS-Status inaktiv, Spec 2026-10-14): Inaktive nehmen keine Einsaetze an.
 * Ein inaktiver Alt-Datensatz derselben Person sperrt dagegen nicht.
 */
final class JobOffersZugang
{
    /** @param list<int> $employeeIds Identitaets-Gruppe der Person */
    public static function gesperrt(array $employeeIds, int $tokenEmployeeId): bool
    {
        if (RecEmployee::query()->whereIn('id', $employeeIds)->whereNotNull('portal_locked_at')->exists()) {
            return true;
        }

        return !RecEmployee::query()->whereKey($tokenEmployeeId)->where('is_active', true)->exists();
    }
}
