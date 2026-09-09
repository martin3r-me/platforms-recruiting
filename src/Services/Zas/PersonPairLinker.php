<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PersonPairing;
use Symfony\Component\Uid\UuidV7;

/**
 * Personen-Paarung der Mitarbeiter-Datensaetze (Chaieb-Befund 10.09.2026):
 * ZAS bedient zwei Firmen — eine Person kann einen RG- und einen MA-Datensatz
 * mit zwei Personalnummern haben. Der person_key ist der Gruppen-Marker
 * „dieselbe Person"; gesetzt wird er NUR hier:
 *
 *  - pairIfExact(): beim Stammdaten-Import fuer einen frisch angelegten
 *    Datensatz — automatisch nur beim doppelt-exakten Treffer (voller Name
 *    UND Geburtsdatum identisch, PersonPairing) mit hoechstens EINER
 *    Bewerbung in der Gruppe. Der unverlinkte Datensatz erbt die Bewerbung.
 *    Namensvarianten und Mehrdeutigkeit stempeln nie (Ergebnis 'ambiguous'
 *    bzw. 'none') — die gehoeren dem Menschen (Audit-Kommando).
 *  - stamp(): der gemeinsame Schreibweg fuer Audit-Kommando und Hand-Link.
 *
 * Schreibt per Query-Builder (observer-frei): ein Paar-Stempel ist keine
 * fachliche Aenderung des Mitarbeiters und darf ihn nicht in den
 * ZAS-Update-Export spuelen (zas_changed_at bleibt unberuehrt — dasselbe
 * Muster wie createEmployee im Importer).
 */
class PersonPairLinker
{
    /** @return array{status: 'paired'|'ambiguous'|'none', sibling_ids?: list<int>, person_key?: string} */
    public function pairIfExact(RecEmployee $employee): array
    {
        $nameKey = PersonPairing::nameKey($employee->first_name, $employee->last_name);
        $geburt = $employee->birth_date?->toDateString();
        if ($nameKey === null || $geburt === null) {
            return ['status' => 'none'];
        }

        // Kandidaten grob per SQL, exakt per Regel — die Normalisierung
        // (mb_strtolower/trim) soll nur an EINER Stelle leben (PersonPairing).
        $kandidaten = RecEmployee::query()
            ->where('team_id', $employee->team_id)
            ->where('id', '!=', $employee->id)
            ->whereDate('birth_date', $geburt)
            ->get(['id', 'first_name', 'last_name', 'birth_date', 'rec_applicant_id', 'person_key'])
            ->filter(fn ($k) => PersonPairing::isExactMatch(
                ['first_name' => $employee->first_name, 'last_name' => $employee->last_name, 'birth_date' => $geburt],
                ['first_name' => $k->first_name, 'last_name' => $k->last_name, 'birth_date' => $k->birth_date?->toDateString()],
            ))
            ->values();

        if ($kandidaten->isEmpty()) {
            return ['status' => 'none'];
        }

        $applicantIds = $kandidaten->pluck('rec_applicant_id')
            ->push($employee->rec_applicant_id)
            ->filter()
            ->unique()
            ->values();

        // Mehr als ein Kandidat oder mehr als eine Bewerbung in der Gruppe:
        // automatisch zu stempeln hiesse, still zwei Bewerbungen zu einer
        // Person zu erklaeren — Menschen-Entscheidung (Audit).
        if ($kandidaten->count() > 1 || $applicantIds->count() > 1) {
            return ['status' => 'ambiguous', 'sibling_ids' => $kandidaten->pluck('id')->map(fn ($i) => (int) $i)->all()];
        }

        $ids = [(int) $kandidaten->first()->id, (int) $employee->id];
        $key = self::stamp($ids, $applicantIds->first() !== null ? (int) $applicantIds->first() : null);

        return ['status' => 'paired', 'sibling_ids' => [$ids[0]], 'person_key' => $key];
    }

    /**
     * Stempelt eine bestaetigte Gruppe: gemeinsamer person_key (ein bereits
     * vorhandener Key der Gruppe gewinnt — idempotent, keine Rotation) und
     * optional die Bewerbung fuer alle noch unverlinkten Datensaetze.
     *
     * @param  list<int>  $employeeIds
     */
    public static function stamp(array $employeeIds, ?int $applicantId): string
    {
        $key = DB::table('rec_employees')
            ->whereIn('id', $employeeIds)
            ->whereNotNull('person_key')
            ->value('person_key') ?? (string) UuidV7::generate();

        DB::table('rec_employees')->whereIn('id', $employeeIds)->update(['person_key' => $key]);

        if ($applicantId !== null) {
            DB::table('rec_employees')
                ->whereIn('id', $employeeIds)
                ->whereNull('rec_applicant_id')
                ->update(['rec_applicant_id' => $applicantId]);
        }

        return $key;
    }
}
