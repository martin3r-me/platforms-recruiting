<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoPhoneMatcher;

/**
 * Ist der Absender einer WhatsApp auf der HR-Nummer ein aktiver Mitarbeiter?
 *
 * Hintergrund: Mitarbeiter aus dem ZAS-Import haben ihre Nummer nur in
 * rec_employees.phone — keinen CRM-Kontakt, keine Bewerbung. Der Bestandscheck
 * des Eingangs (Bewerber per CRM-Kontakt) lief bei ihnen ins Leere, und jede
 * Nachricht an die HR-Nummer legte einen neuen Bewerber an.
 *
 * Gleiche Regeln wie die Dispo-Kommunikation, damit HR und Dispo einen
 * Mitarbeiter garantiert gleich erkennen:
 *  - Quelle: aktive Mitarbeiter des Teams mit Telefonnummer
 *  - Vergleich: DispoPhoneMatcher::normalize() auf beiden Seiten, dann exakt
 *  - mehrere Datensaetze DERSELBEN Person (gemeinsamer CRM-Kontakt wie im
 *    DispoIdentityResolver, zusaetzlich gleicher person_key) sind EIN Treffer;
 *    kanonische id = kleinste id der Gruppe
 *  - verschiedene Personen mit derselben Nummer = mehrdeutig, nie raten
 */
final class EmployeeSenderResolver
{
    public const NONE = 'none';
    public const EMPLOYEE = 'employee';
    public const AMBIGUOUS = 'ambiguous';

    /** @return array{status: string, employee_id: ?int, employee_ids: list<int>} */
    public function resolve(string $senderPhone, int $teamId): array
    {
        if (DispoPhoneMatcher::normalize($senderPhone) === null) {
            return self::result(self::NONE);
        }

        $rows = RecEmployee::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'phone', 'person_key']);

        if ($rows->isEmpty()) {
            return self::result(self::NONE);
        }

        $phonesById = [];
        $personKeyById = [];
        foreach ($rows as $row) {
            $phonesById[(int) $row->id] = (string) $row->phone;
            if ((string) $row->person_key !== '') {
                $personKeyById[(int) $row->id] = (string) $row->person_key;
            }
        }

        // Gruppen nur fuer die Treffer aufloesen — der Resolver fragt sonst
        // CRM-Links fuer jeden aktiven Mitarbeiter ab.
        $hitIds = self::hitIds($senderPhone, $phonesById);
        $groups = count($hitIds) > 1
            ? app(DispoIdentityResolver::class)->groupsFor($hitIds)
            : [];

        return self::decide($senderPhone, $phonesById, $groups, $personKeyById);
    }

    /**
     * Reine Entscheidung (ohne DB), damit sie im Unit-Test greifbar ist.
     *
     * @param array<int, string>     $phonesById    employee_id => Roh-Telefonnummer
     * @param array<int, list<int>>  $groupsById    employee_id => Identitaetsgruppe (DispoIdentityResolver::groupsFor)
     * @param array<int, string>     $personKeyById employee_id => person_key (nur gesetzte)
     * @return array{status: string, employee_id: ?int, employee_ids: list<int>}
     */
    public static function decide(string $senderPhone, array $phonesById, array $groupsById, array $personKeyById): array
    {
        $hitIds = self::hitIds($senderPhone, $phonesById);
        if ($hitIds === []) {
            return self::result(self::NONE);
        }

        // Union-Find ueber die Treffer: gemeinsame Identitaetsgruppe ODER
        // gleicher person_key = dieselbe Person.
        $parent = array_combine($hitIds, $hitIds);
        $find = function (int $id) use (&$parent, &$find): int {
            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        };

        foreach ($hitIds as $id) {
            foreach ($groupsById[$id] ?? [] as $member) {
                if (isset($parent[(int) $member])) {
                    $union($id, (int) $member);
                }
            }
        }

        $byPersonKey = [];
        foreach ($hitIds as $id) {
            if (isset($personKeyById[$id])) {
                $byPersonKey[$personKeyById[$id]][] = $id;
            }
        }
        foreach ($byPersonKey as $ids) {
            foreach ($ids as $id) {
                $union($ids[0], $id);
            }
        }

        $persons = array_values(array_unique(array_map($find, $hitIds)));

        return count($persons) === 1
            ? self::result(self::EMPLOYEE, $persons[0], $hitIds)
            : self::result(self::AMBIGUOUS, null, $hitIds);
    }

    /**
     * @param array<int, string> $phonesById
     * @return list<int> aufsteigend sortiert
     */
    private static function hitIds(string $senderPhone, array $phonesById): array
    {
        $needle = DispoPhoneMatcher::normalize($senderPhone);
        if ($needle === null) {
            return [];
        }

        $hits = [];
        foreach ($phonesById as $id => $phone) {
            if (DispoPhoneMatcher::normalize($phone) === $needle) {
                $hits[] = (int) $id;
            }
        }
        sort($hits);

        return $hits;
    }

    /** @param list<int> $employeeIds */
    private static function result(string $status, ?int $employeeId = null, array $employeeIds = []): array
    {
        return ['status' => $status, 'employee_id' => $employeeId, 'employee_ids' => $employeeIds];
    }
}
