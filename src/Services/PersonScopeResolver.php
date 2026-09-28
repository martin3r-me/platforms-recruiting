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
     * Zweig 2 ist der Uebergang fuer Zeilen ohne rec_person_id. Er darf
     * NICHT entfernt werden, sobald der Backfill auf der Produktion
     * gelaufen ist — diese Zusage stand hier frueher und war falsch. Der
     * Backfill stellt die Klammer nur fuer EINEN ZEITPUNKT her: neue
     * Anstellungen aus CreateEmployeeFromApplicantService (Anlage aus der
     * Bewerbung) und aus ZasInboundEmployeeImporter (ZAS-Neuanlage) rufen
     * PersonLinker heute nicht unmittelbar und bekommen deshalb keine
     * Personen-Zeile. Genauer beim ZAS-Import: er erreicht PersonLinker nur
     * ueber die Paarung, also NUR beim doppelt-exakten Treffer — ein neu
     * angelegter Einzelfall bleibt ohne Zeile.
     * Zweig 2 verschwindet erst, wenn dieser Haken in beiden Wegen sitzt —
     * das ist eine eigene Aufgabe mit eigener Pruefung. Bis dahin ist Zweig
     * 2 der einzige Weg, auf dem ein nach dem Backfill angelegter Mensch
     * seine zweite Anstellung ueberhaupt sieht, und er muss mitgetestet
     * werden.
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
