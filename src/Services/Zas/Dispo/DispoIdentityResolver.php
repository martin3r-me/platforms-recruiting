<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Dispo-Identitaet = CRM-Kontakt (Spec 2026-08-28): mehrere aktive Mitarbeiter-
 * Datensaetze desselben Teams am selben crm_contacts-Eintrag sind EINE Person
 * (z. B. Personalnummern RG… und MA… derselben Person).
 *
 * EINZIGE Tuer vom Dispo-Code zu crm_contact_links — nur lesend. Ohne Link,
 * inaktiv oder fremdes Team -> Gruppe = [id] (heutiges Verhalten).
 */
class DispoIdentityResolver
{
    /**
     * @param list<int> $employeeIds
     * @return array<int, list<int>> employee_id => sortierte Gruppe (enthaelt die id selbst)
     */
    public function groupsFor(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }

        $morph = (new RecEmployee())->getMorphClass();
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);

        $contactIds = CrmContactLink::query()
            ->where('linkable_type', $morph)
            ->whereIn('linkable_id', $employeeIds)
            ->pluck('contact_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $links = $contactIds === []
            ? collect()
            : CrmContactLink::query()->where('linkable_type', $morph)->whereIn('contact_id', $contactIds)->get(['contact_id', 'linkable_id']);

        $candidateIds = array_values(array_unique(array_merge(
            $employeeIds,
            $links->pluck('linkable_id')->map(fn ($v) => (int) $v)->all()
        )));

        $activeIds = RecEmployee::query()
            ->whereIn('id', $candidateIds)
            ->where('is_active', true)
            ->when($teamId > 0, fn ($q) => $q->where('team_id', $teamId))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        $byEmployee = [];
        foreach ($links as $link) {
            $byEmployee[(int) $link->linkable_id][] = (int) $link->contact_id;
        }

        $groups = DispoIdentityGroups::build($byEmployee, $activeIds);

        $out = [];
        foreach ($employeeIds as $id) {
            $out[$id] = $groups[$id] ?? [$id];
        }

        return $out;
    }

    /** @return list<int> */
    public function groupFor(int $employeeId): array
    {
        return $this->groupsFor([$employeeId])[$employeeId] ?? [$employeeId];
    }
}
