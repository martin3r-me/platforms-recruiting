<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Reine Gruppenbildung "welche Mitarbeiter-Datensaetze sind EINE Person" —
 * Kriterium: gemeinsamer CRM-Kontakt (transitiv). Kein DB-Zugriff (testbar).
 * Kanonische id einer Gruppe = kleinste id (stabil, ohne Zusatzdaten).
 */
final class DispoIdentityGroups
{
    /**
     * @param array<int, list<int>> $contactIdsByEmployee employee_id => contact_ids
     * @param list<int> $activeIds Kandidaten (aktive MA des Teams)
     * @return array<int, list<int>> fuer jede id aus $activeIds ihre sortierte Gruppe (enthaelt sich selbst)
     */
    public static function build(array $contactIdsByEmployee, array $activeIds): array
    {
        $active = array_values(array_unique(array_map('intval', $activeIds)));
        $groupOf = [];
        foreach ($active as $id) {
            $groupOf[$id] = [$id];
        }

        $membersByContact = [];
        foreach ($contactIdsByEmployee as $employeeId => $contactIds) {
            $employeeId = (int) $employeeId;
            if (!isset($groupOf[$employeeId])) {
                continue; // inaktiv / fremdes Team
            }
            foreach ((array) $contactIds as $contactId) {
                $membersByContact[(int) $contactId][] = $employeeId;
            }
        }

        // Full-Rewrite statt Union-Find (jede Gruppe wird komplett neu geschrieben) —
        // O(Kontakte x Gruppengroesse), fuer MA-Zahlen im Dispo-Kontext voellig
        // ausreichend; reihenfolgeunabhaengig, weil jeder Merge den AKTUELLEN
        // Gruppenstand aller Mitglieder vereinigt.
        foreach ($membersByContact as $members) {
            $members = array_values(array_unique($members));
            if (count($members) < 2) {
                continue;
            }
            $merged = [];
            foreach ($members as $m) {
                $merged = array_merge($merged, $groupOf[$m]);
            }
            $merged = array_values(array_unique($merged));
            sort($merged);
            foreach ($merged as $m) {
                $groupOf[$m] = $merged;
            }
        }

        foreach ($groupOf as &$g) {
            sort($g);
        }
        unset($g);

        return $groupOf;
    }

    /** @param list<int> $group */
    public static function canonical(array $group): int
    {
        return (int) min($group);
    }

    /** @param array<int, list<int>> $groups @return array<int,int> */
    public static function canonicalMap(array $groups): array
    {
        $map = [];
        foreach ($groups as $id => $group) {
            $map[(int) $id] = self::canonical($group);
        }

        return $map;
    }

    /**
     * Schreibt $row[$key] auf die kanonische id um (unbekannt -> unveraendert, null bleibt null).
     * @param list<array<string,mixed>> $rows @param array<int,int> $canonicalMap
     */
    public static function canonicalize(array $rows, array $canonicalMap, string $key = 'employee_id'): array
    {
        foreach ($rows as &$row) {
            if (isset($row[$key]) && $row[$key] !== null) {
                $row[$key] = $canonicalMap[(int) $row[$key]] ?? (int) $row[$key];
            }
        }
        unset($row);

        return $rows;
    }
}
