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

    /** Spalten, die Aufrufer an der employees-Relation mitladen muessen. */
    public const EMPLOYEE_COLUMNS = 'employees:id,rec_applicant_id,personnel_number,person_key,is_active,employment_ended_at';

    /** @param array<int, array{count:int, first:?string, grund:?string}> $info */
    private function __construct(private readonly array $info)
    {
    }

    /**
     * @param iterable<object> $applicants Bewerbungen mit geladener
     *        employees-Relation (self::EMPLOYEE_COLUMNS)
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
                ->get(['id', 'rec_applicant_id', 'personnel_number', 'person_key', 'is_active', 'employment_ended_at']);
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
                $info[$applicantId] = ['count' => 0, 'first' => null, 'grund' => 'kein_ma', 'inaktiv' => false, 'beendet_am' => null];
                continue;
            }
            [$inaktiv, $beendetAm] = self::inaktivStatus($employees);
            $mitNummer = array_filter($employees, fn ($e) => trim((string) $e->personnel_number) !== '');
            if ($mitNummer === []) {
                $info[$applicantId] = ['count' => 0, 'first' => null, 'grund' => 'keine_pnr', 'inaktiv' => $inaktiv, 'beendet_am' => $beendetAm];
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
            $info[$applicantId] = ['count' => $count, 'first' => $first, 'grund' => null, 'inaktiv' => $inaktiv, 'beendet_am' => $beendetAm];
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

    /** @return array{count:int, first:?string, grund:?string, inaktiv:bool, beendet_am:?string} */
    public function infoFor(int $applicantId): array
    {
        return $this->info[$applicantId] ?? ['count' => 0, 'first' => null, 'grund' => 'kein_ma', 'inaktiv' => false, 'beendet_am' => null];
    }

    /**
     * Sind ALLE Anstellungen der Person inaktiv (Kunde 14.10.2026)? Dann gilt
     * sie im Dispo-Abgleich als geklaert statt als „ohne Einsatz“ — ohne
     * Anstellung gibt es nichts zu deaktivieren.
     */
    public function inaktiv(int $applicantId): bool
    {
        return (bool) ($this->info[$applicantId]['inaktiv'] ?? false);
    }

    /**
     * Inaktiv nur, wenn JEDE Anstellung ausdruecklich is_active === false traegt
     * (RG + MA: eine aktive Anstellung reicht, um die Person aktiv zu halten).
     * Strikt auf false, nicht auf „falsy“: fehlt die Spalte im Select, bleibt
     * der Wert null — und niemand darf deshalb still aus der Arbeitsliste fallen.
     *
     * @param  list<object>  $employees
     * @return array{0: bool, 1: ?string} [inaktiv, spaetestes „Beschaeftigung beendet am“ als Y-m-d]
     */
    private static function inaktivStatus(array $employees): array
    {
        $beendet = null;
        foreach ($employees as $employee) {
            if ($employee->is_active !== false) {
                return [false, null];
            }
            $ende = $employee->employment_ended_at;
            $ymd = $ende instanceof \DateTimeInterface ? $ende->format('Y-m-d') : (is_string($ende) && $ende !== '' ? substr($ende, 0, 10) : null);
            if ($ymd !== null && ($beendet === null || $ymd > $beendet)) {
                $beendet = $ymd;
            }
        }

        return [true, $beendet];
    }

    /** @return array<int, array{count:int, first:?string, grund:?string}> */
    public function info(): array
    {
        return $this->info;
    }
}
