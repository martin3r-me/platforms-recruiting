<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecEmployee;

/**
 * ZWEITE Tuer vom Dispo-Code zum Recruiting-Personal (neben
 * DispoEmployeeDirectory, Entkopplungs-Leitplanke 3): liefert Kontaktdaten
 * fuer den Bestaetigungs-Versand. Beim Staffing-Auszug wird diese Klasse
 * gegen den dortigen Personen-Adapter getauscht.
 */
class DispoEmployeeGateway
{
    /** @return array<int, array{name: string, phone: ?string, portal_token: string}> */
    public function contacts(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return RecEmployee::query()
            ->whereIn('id', $employeeIds)
            ->get(['id', 'first_name', 'last_name', 'phone', 'portal_token'])
            ->mapWithKeys(fn ($e) => [(int) $e->id => [
                'name'         => trim($e->first_name . ' ' . $e->last_name),
                'phone'        => ($e->phone !== null && trim($e->phone) !== '') ? trim($e->phone) : null,
                'portal_token' => (string) $e->portal_token,
            ]])
            ->all();
    }

    /** @return array<int, ?string> employee_id => phone */
    public function phones(array $employeeIds): array
    {
        return array_map(fn ($c) => $c['phone'], $this->contacts($employeeIds));
    }
}
