<?php

namespace Platform\Recruiting\Services\Statistics;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;

/**
 * SCHULUNG → EINSATZ (Markus, 09.09.2026): je Bewerbung eine von drei
 * ehrlichen Aussagen fuer den Dispo-Abgleich.
 *
 * Zuweisungen matchen im Dispo-Import ausschliesslich ueber die
 * ZAS-Personalnummer des Mitarbeiters — ohne MA oder ohne PersNr ist die Frage
 * NICHT pruefbar und darf nicht still als „ohne Einsatz“ zaehlen (das waere die
 * naechste Zahl, an der es zwischen Dispo und HR knirscht).
 *
 * Ein Einsatz zaehlt, wenn er kein Storno ist (status_id 3) und nicht aus dem
 * ZAS entfernt/geloescht wurde. Angebote (status_id 0) zaehlen MIT: „geplant
 * oder vergangen“ war die ausdrueckliche Anforderung.
 *
 * ALLE Anstellungen je Bewerbung: ZAS bedient zwei Firmen (RG/MA), eine Person
 * kann zwei Datensaetze mit zwei Nummern haben — die Einsaetze BEIDER zaehlen
 * (Chaieb-Befund 10.09.2026). „Pruefbar“ heisst: mindestens eine Anstellung
 * traegt eine Nummer.
 *
 * Seit 14.09.2026 eine eigene Einheit statt inline in Statistics\Index::cohort():
 * der Sammelversand „ohne Einsatz“ stellt dieselbe Frage unmittelbar vor dem
 * Senden noch einmal, und zwei handgeschriebene Kopien derselben Regel laufen
 * frueher oder spaeter auseinander.
 *
 * ZWEI Queries, beide ueber die ganze Menge (Query-Budget der Statistik-Seite
 * ist Abnahmekriterium): die person_key-Geschwister und die Zuweisungen.
 */
final class EinsatzLookup
{
    public const FLAG_UNVERIFIABLE = 'unverifiable';
    public const FLAG_DEPLOYED = 'deployed';
    public const FLAG_NONE = 'none';

    /** @param array<int, array{count:int, first:?string, grund:?string}> $info */
    private function __construct(private readonly array $info)
    {
    }

    /**
     * @param iterable<object> $applicants Bewerbungen mit geladener
     *        employees-Relation (id, rec_applicant_id, personnel_number, person_key)
     */
    public static function for(int $teamId, iterable $applicants): self
    {
        $employeesByApplicant = [];
        foreach ($applicants as $a) {
            $employeesByApplicant[(int) $a->id] = [];
            foreach ($a->employees as $employee) {
                $employeesByApplicant[(int) $a->id][] = $employee;
            }
        }

        // GESCHWISTER ueber den person_key: der zweite Firmen-Datensatz haengt
        // oft NICHT an der Bewerbung (er kam per ZAS-Lieferung), traegt aber
        // denselben person_key wie der verlinkte. Ohne diesen Schritt saehe der
        // Abgleich nur die Nummern der verlinkten Anstellungen — genau der
        // Chaieb-Fall (MA18232 unverlinkt, RG18231 verlinkt).
        $keyZuApplicants = [];
        $bekannteEmployeeIds = [];
        foreach ($employeesByApplicant as $applicantId => $employees) {
            foreach ($employees as $employee) {
                $bekannteEmployeeIds[] = (int) $employee->id;
                if (trim((string) $employee->person_key) !== '') {
                    $keyZuApplicants[$employee->person_key][] = (int) $applicantId;
                }
            }
        }
        if ($keyZuApplicants !== []) {
            $geschwister = RecEmployee::query()
                ->where('team_id', $teamId)
                ->whereIn('person_key', array_keys($keyZuApplicants))
                ->whereNotIn('id', $bekannteEmployeeIds)
                ->get(['id', 'rec_applicant_id', 'personnel_number', 'person_key']);
            foreach ($geschwister as $employee) {
                foreach (array_unique($keyZuApplicants[$employee->person_key] ?? []) as $applicantId) {
                    $employeesByApplicant[$applicantId][] = $employee;
                }
            }
        }

        $pruefbareEmployeeIds = collect($employeesByApplicant)
            ->flatten(1)
            ->filter(fn ($e) => trim((string) $e->personnel_number) !== '')
            ->map(fn ($e) => (int) $e->id)
            ->values();
        $einsatzJeEmployee = $pruefbareEmployeeIds->isEmpty() ? collect() :
            RecDispoAssignment::query()
                ->whereIn('rec_employee_id', $pruefbareEmployeeIds)
                ->where('status_id', '!=', RecDispoAssignment::STATUS_STORNO)
                ->whereNull('zas_removed_at')
                ->whereNull('deletion_confirmed_at')
                ->groupBy('rec_employee_id')
                ->selectRaw('rec_employee_id, COUNT(*) as anzahl, MIN(datum) as erster')
                ->get()
                ->keyBy('rec_employee_id');

        $info = [];
        foreach ($employeesByApplicant as $applicantId => $employees) {
            if ($employees === []) {
                $info[$applicantId] = ['count' => 0, 'first' => null, 'grund' => 'kein_ma'];
                continue;
            }
            $mitNummer = array_filter($employees, fn ($e) => trim((string) $e->personnel_number) !== '');
            if ($mitNummer === []) {
                $info[$applicantId] = ['count' => 0, 'first' => null, 'grund' => 'keine_pnr'];
                continue;
            }

            // Summe/Minimum ueber ALLE Anstellungen mit Nummer
            $count = 0;
            $first = null;
            foreach ($mitNummer as $employee) {
                $treffer = $einsatzJeEmployee->get((int) $employee->id);
                if ($treffer === null) {
                    continue;
                }
                $count += (int) $treffer->anzahl;
                $erster = $treffer->erster !== null ? (string) $treffer->erster : null;
                if ($erster !== null && ($first === null || $erster < $first)) {
                    $first = $erster;
                }
            }
            $info[$applicantId] = ['count' => $count, 'first' => $first, 'grund' => null];
        }

        return new self($info);
    }

    /**
     * Eine der drei Aussagen. Eine unbekannte Bewerbung (nicht in der Menge)
     * ist nicht pruefbar — fail-closed: lieber „keine Aussage“ als eine
     * erfundene.
     */
    public function flag(int $applicantId): string
    {
        $info = $this->info[$applicantId] ?? null;
        if ($info === null || $info['grund'] !== null) {
            return self::FLAG_UNVERIFIABLE;
        }

        return $info['count'] > 0 ? self::FLAG_DEPLOYED : self::FLAG_NONE;
    }

    /** @return array{count:int, first:?string, grund:?string} */
    public function infoFor(int $applicantId): array
    {
        return $this->info[$applicantId] ?? ['count' => 0, 'first' => null, 'grund' => 'kein_ma'];
    }

    /** @return array<int, array{count:int, first:?string, grund:?string}> */
    public function info(): array
    {
        return $this->info;
    }
}
