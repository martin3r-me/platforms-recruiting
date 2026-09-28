<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PersonProofScope;

/**
 * Welche Anstellungen gehoeren zu diesem Menschen?
 *
 * Eine Stelle fuer Lesen und Schreiben — wuerden Aufgabenliste und Upload
 * unterschiedlich aufloesen, saehe jemand einen Nachweis, den er nicht
 * hochladen kann, oder umgekehrt.
 */
final class PersonScopeResolver
{
    /**
     * Zweig 1 (rec_person_id gesetzt) sticht Zweig 2 (person_key + Telefon):
     * Identitaet gehoert in eine Spalte, nicht in eine Suchabfrage. Eine
     * gesetzte Spalte ist eine getroffene Entscheidung; ein Abgleich zur
     * Laufzeit bleibt ein Rateversuch, der ohne Telefonnummer gar nicht erst
     * paart (Demo-Bestand, siehe PersonProofScope) und bei einem geteilten
     * Handy zwei verschiedene Menschen faelschlich zusammenzieht.
     *
     * Zweig 2 ist ein Uebergang fuer noch nicht gebackfillte Zeilen. Er
     * wird entfernt, sobald der Backfill auf der Produktion gelaufen ist —
     * bis dahin muss er mitgetestet werden.
     *
     * @return array{ids: list<int>, abweichend: list<int>}
     */
    public function forEmployee(RecEmployee $employee): array
    {
        $personId = $employee->rec_person_id;
        if ($personId !== null) {
            // Entschiedene Zuordnung: alle Anstellungen derselben Person,
            // kein Zweifelsfall mehr moeglich.
            $ids = DB::table('rec_employees')
                ->where('rec_person_id', $personId)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            return ['ids' => $ids, 'abweichend' => []];
        }

        $key = trim((string) $employee->person_key);
        if ($key === '') {
            return ['ids' => [(int) $employee->id], 'abweichend' => []];
        }

        $geschwister = DB::table('rec_employees')
            ->where('person_key', $key)
            ->where('id', '!=', $employee->id)
            ->get(['id', 'person_key', 'phone'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'person_key' => $r->person_key, 'phone' => $r->phone])
            ->all();

        return PersonProofScope::resolve(
            ['id' => (int) $employee->id, 'person_key' => $employee->person_key, 'phone' => $employee->phone],
            $geschwister,
        );
    }
}
