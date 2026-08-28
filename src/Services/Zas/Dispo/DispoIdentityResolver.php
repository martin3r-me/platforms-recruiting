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
 *
 * Ohne Team-Anker (inbound_team_id) wird NICHT gruppiert (fail-closed) — der
 * Resolver speist eine oeffentliche Token-Seite.
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

        if ($teamId <= 0) {
            // Fail-closed: ohne Team-Anker keine Gruppierung.
            $out = [];
            foreach ($employeeIds as $id) {
                $out[$id] = [$id];
            }

            return $out;
        }

        $contactIds = CrmContactLink::query()
            ->where('linkable_type', $morph)
            ->where('team_id', $teamId)
            ->whereIn('linkable_id', $employeeIds)
            ->pluck('contact_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $links = $contactIds === []
            ? collect()
            : CrmContactLink::query()->where('linkable_type', $morph)->where('team_id', $teamId)->whereIn('contact_id', $contactIds)->get(['contact_id', 'linkable_id']);

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

    /**
     * Fuer die Kommunikation: der an einen WhatsApp-Thread verknuepfte
     * CRM-Kontakt zeigt direkt auf die Person (kein Telefon-Raten noetig).
     * Gleiche Team-/Aktiv-Regeln wie groupsFor.
     *
     * @param list<int> $contactIds
     * @return array<int, list<int>> contact_id => sortierte aktive MA-ids (nur Kontakte mit Treffern)
     */
    public function employeeIdsByContact(array $contactIds): array
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
        if ($contactIds === []) {
            return [];
        }

        $morph = (new RecEmployee())->getMorphClass();
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);

        if ($teamId <= 0) {
            // Fail-closed: ohne Team-Anker keine Kontakt-Aufloesung.
            return [];
        }

        $links = CrmContactLink::query()
            ->where('linkable_type', $morph)
            ->where('team_id', $teamId)
            ->whereIn('contact_id', $contactIds)
            ->get(['contact_id', 'linkable_id']);

        if ($links->isEmpty()) {
            return [];
        }

        $candidateIds = $links->pluck('linkable_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $activeIds = RecEmployee::query()
            ->whereIn('id', $candidateIds)
            ->where('is_active', true)
            ->when($teamId > 0, fn ($q) => $q->where('team_id', $teamId))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
        $activeSet = array_flip($activeIds);

        $out = [];
        foreach ($links as $link) {
            $employeeId = (int) $link->linkable_id;
            if (!isset($activeSet[$employeeId])) {
                continue;
            }
            $out[(int) $link->contact_id][] = $employeeId;
        }

        foreach ($out as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
        }
        unset($ids);

        return $out;
    }

    /**
     * Umkehrung von employeeIdsByContact(): CRM-Kontakt-IDs je Mitarbeiter-ID
     * (nur Links des Anker-Teams). Fail-closed ohne inbound_team_id -> [].
     *
     * @param list<int> $employeeIds
     * @return array<int, list<int>> employee_id => contact_ids
     */
    public function contactIdsByEmployee(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }

        $morph = (new RecEmployee())->getMorphClass();
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);

        if ($teamId <= 0) {
            // Fail-closed: ohne Team-Anker keine Kontakt-Aufloesung.
            return [];
        }

        $links = CrmContactLink::query()
            ->where('linkable_type', $morph)
            ->where('team_id', $teamId)
            ->whereIn('linkable_id', $employeeIds)
            ->get(['contact_id', 'linkable_id']);

        if ($links->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($links as $link) {
            $out[(int) $link->linkable_id][] = (int) $link->contact_id;
        }

        foreach ($out as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
        }
        unset($ids);

        return $out;
    }
}
