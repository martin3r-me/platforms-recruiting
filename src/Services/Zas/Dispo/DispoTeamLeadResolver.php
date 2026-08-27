<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Reine Erkennung "wer ist Teamleitung in dieser VA" — kein DB-/Config-Zugriff.
 * Eingabe sind flache Arrays (Einbuchungen + Kontakte aus dem Gateway), Ausgabe
 * die Kandidaten fuer den Ansprechpartner vor Ort: dedupliziert pro MA,
 * Reihenfolge = erstes Vorkommen. Vergleich exakt (getrimmt, Gross/Klein egal)
 * gegen die konfigurierten Bezeichnungen — bewusst KEIN "beginnt mit"
 * ("Teamleitung Logisitk" ist nicht automatisch der Service-Ansprechpartner).
 */
class DispoTeamLeadResolver
{
    /**
     * @param list<array{employee_id:?int, taetigkeit:?string, datum:string}> $assignments
     * @param array<int, array{name:string, first_name:string, phone:?string}> $contacts employee_id => Kontakt
     * @param list<string> $leadTaetigkeiten konfigurierte Bezeichnungen
     * @param string $onlyDay Y-m-d, '' = alle Tage
     * @return list<array{employee_id:int, name:string, phone:?string, label:string}>
     */
    public function resolve(array $assignments, array $contacts, array $leadTaetigkeiten, string $onlyDay = ''): array
    {
        $wanted = [];
        foreach ($leadTaetigkeiten as $t) {
            $t = mb_strtolower(trim((string) $t));
            if ($t !== '') {
                $wanted[] = $t;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $leads = [];
        foreach ($assignments as $a) {
            $employeeId = $a['employee_id'] ?? null;
            if ($employeeId === null) {
                continue;
            }
            if ($onlyDay !== '' && (string) ($a['datum'] ?? '') !== $onlyDay) {
                continue;
            }
            if (!in_array(mb_strtolower(trim((string) ($a['taetigkeit'] ?? ''))), $wanted, true)) {
                continue;
            }
            $employeeId = (int) $employeeId;
            if (isset($leads[$employeeId])) {
                continue;
            }
            $contact = $contacts[$employeeId] ?? null;
            if ($contact === null) {
                continue; // kein Kontakt bekannt -> kein brauchbarer Ansprechpartner
            }
            $name  = trim((string) ($contact['name'] ?? ''));
            $phone = $contact['phone'] ?? null;
            $leads[$employeeId] = [
                'employee_id' => $employeeId,
                'name'        => $name,
                'phone'       => $phone,
                'label'       => $phone !== null ? "{$name} ({$phone})" : $name,
            ];
        }

        return array_values($leads);
    }
}
