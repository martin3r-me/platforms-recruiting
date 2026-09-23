<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Support\PersonProofScope;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Schreibt einen Nachweis — und spiegelt ihn in die alten Spalten.
 *
 * Warum gespiegelt wird
 * --------------------
 * Der ZAS-Export und der Datei-Endpunkt lesen die 16 `*_file_id`-Spalten und
 * neun `*_valid_until`-Spalten direkt aus rec_employees. Wuerden wir die Daten
 * ersatzlos in die neue Tabelle verlegen, meldete unser Export ueber Nacht
 * fehlende Dokumente fuer 1.558 Mitarbeiter. Also: neue Tabelle fuehrt, alte
 * Spalten werden mitgeschrieben, bis der Export umgestellt ist.
 *
 * Warum ohne Observer
 * -------------------
 * `RecEmployeeExportObserver` setzt bei jeder Eloquent-Aenderung an
 * rec_employees den Marker `zas_changed_at` — der Datensatz landet damit in
 * der naechsten updates.csv. Bei ueber 500 abgelaufenen Nachweisen im Bestand
 * wuerde die erste Upload-Welle den halben Bestand hineinspuelen. Genau das ist
 * am 02.09.2026 schon einmal passiert (Telefon-Lauf, 505 Datensaetze).
 * Deshalb schreibt die Spiegelung ueber den Query-Builder, an Eloquent vorbei.
 *
 * Folge, bewusst in Kauf genommen: ZAS erfaehrt von einem neuen Dokument erst
 * beim naechsten ohnehin faelligen Export dieses Mitarbeiters. Die Datei ist
 * dann enthalten, denn die Upl-Adressen werden beim Export aus den Spalten
 * gebaut. Ob Michel neue Dokumente sofort sehen will, ist eine offene Frage an
 * ZAS — kein technisches Hindernis.
 *
 * Gespiegelt wird auf ALLE Anstellungen der Person: Beide Gesellschaften
 * exportieren getrennt, und ein Ausweis gehoert dem Menschen, nicht der Stelle.
 */
final class ProofWriter
{
    /**
     * @param array{file_id?:?int, file_back_id?:?int, valid_until?:?string,
     *              uploaded_via?:string, uploaded_by_user_id?:?int} $daten
     */
    public function store(RecEmployee $employee, string $code, array $daten = []): RecEmployeeProof
    {
        if (!ProofTypes::exists($code)) {
            throw new InvalidArgumentException("Unbekannte Nachweisart '{$code}'");
        }

        $gueltigBis = $daten['valid_until'] ?? null;
        if ($gueltigBis !== null && !ProofTypes::hasExpiry($code)) {
            throw new InvalidArgumentException("Nachweisart '{$code}' hat kein Gueltig-bis");
        }

        $scope = $this->personScope($employee);

        return DB::transaction(function () use ($employee, $code, $daten, $gueltigBis, $scope) {
            // Bisherige Fassungen der Person abloesen — nicht loeschen.
            $bisher = RecEmployeeProof::query()
                ->aktuell()
                ->vonArt($code)
                ->whereIn('rec_employee_id', $scope['ids'])
                ->get();

            RecEmployeeProof::query()
                ->whereIn('id', $bisher->pluck('id'))
                ->update(['superseded_at' => now()]);

            $proof = RecEmployeeProof::create([
                'team_id'             => $employee->team_id,
                'rec_employee_id'     => $employee->id,
                'person_key'          => $employee->person_key,
                'proof_type_code'     => $code,
                'file_id'             => $daten['file_id'] ?? null,
                'file_back_id'        => $daten['file_back_id'] ?? null,
                'valid_until'         => $gueltigBis,
                'version'             => ((int) $bisher->max('version')) + 1,
                'uploaded_via'        => $daten['uploaded_via'] ?? 'employee',
                'uploaded_by_user_id' => $daten['uploaded_by_user_id'] ?? null,
            ]);

            $this->mirrorToLegacyColumns($scope['ids'], $code, $proof);

            return $proof;
        });
    }

    /**
     * Wessen Nachweise gelten als die dieser Person — gleicher Marker UND
     * gleiche Handynummer (PersonProofScope).
     *
     * @return array{ids: list<int>, abweichend: list<int>}
     */
    public function personScope(RecEmployee $employee): array
    {
        $self = ['id' => (int) $employee->id, 'person_key' => $employee->person_key, 'phone' => $employee->phone];

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

        return PersonProofScope::resolve($self, $geschwister);
    }

    /**
     * Spiegelt Datei- und Ablaufspalten — ueber den Query-Builder, damit der
     * Export-Observer nicht anspringt (siehe Klassenkommentar).
     *
     * @param list<int> $employeeIds
     */
    private function mirrorToLegacyColumns(array $employeeIds, string $code, RecEmployeeProof $proof): void
    {
        $spalten = ProofTypes::legacyFileColumns($code);
        if ($spalten === []) {
            return; // neue Art ohne Altbestand, z. B. der IBAN-Nachweis
        }

        $update = [$spalten[0] => $proof->file_id];
        if (isset($spalten[1])) {
            $update[$spalten[1]] = $proof->file_back_id;
        }

        $ablaufSpalte = ProofTypes::legacyExpiryColumn($code);
        if ($ablaufSpalte !== null) {
            $update[$ablaufSpalte] = $proof->valid_until?->toDateString();
        }

        DB::table('rec_employees')->whereIn('id', $employeeIds)->update($update);
    }
}
