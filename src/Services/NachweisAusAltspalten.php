<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Support\ProofTypes;

/**
 * HR-Akte und ZAS-Datei-Eingang schreiben Dateien und Ablaufdaten in die
 * Altspalten EINER Anstellung — der Nachweis (rec_employee_proofs), aus dem
 * Portal, Aufgabenliste und Erinnerungen lesen, erfuhr davon nichts. Ein
 * Vermerk aus der alten Akte (AltaktenNachweise) blieb mit altem Datum
 * stehen, ein frischer HR-Upload galt im Portal als "fehlt" (30.10.2026).
 *
 * Hier wird danach NUR der Nachweis nachgezogen — bewusst ohne ProofWriter-
 * Spiegel auf die Geschwister-Anstellungen (Review 30.10.): deren Altspalten
 * koennen heute schon abweichen (HR/ZAS schrieben je Anstellung), ein
 * Spiegel wuerde dort neuere Dateien still ueberschreiben.
 *
 * Gebaut wird aus dem BISHERIGEN Nachweis plus dem, was gerade geschrieben
 * wurde — nicht aus den Spalten dieser Anstellung (sonst holt ein Rueckseiten-
 * Upload eine alte Vorderseite zurueck). Arten mit Ablauf bekommen nie einen
 * Nachweis ohne Datum: ohne Datum bleibt alles wie vorher (Portal fragt nach).
 */
final class NachweisAusAltspalten
{
    public function __construct(
        private readonly ProofReader $reader = new ProofReader(),
        private readonly PersonScopeResolver $scope = new PersonScopeResolver(),
    ) {}

    /** Nach einem Datei-Upload in die Altspalte $spalte. */
    public function nachDatei(RecEmployee $employee, string $spalte, int $fileId, string $via, ?int $userId = null): ?RecEmployeeProof
    {
        $code = ProofTypes::codeForLegacyColumn($spalte);
        if ($code === null) {
            return null;
        }

        $bis = null;
        if (ProofTypes::hasExpiry($code)) {
            $bis = $this->datumAusSpalte($employee, $code);
            if ($bis === null) {
                return null;   // ohne Datum kein Nachweis — das Portal fragt weiter danach
            }
        }

        $spalten = ProofTypes::legacyFileColumns($code);
        $aktuell = $this->aktuell($employee, $code);

        $vorne = $spalte === ($spalten[0] ?? null)
            ? $fileId
            : ($aktuell?->file_id ?? $this->id($employee->getAttribute($spalten[0] ?? '')));
        $hinten = isset($spalten[1]) && $spalte === $spalten[1]
            ? $fileId
            : $aktuell?->file_back_id;

        if ($vorne === null) {
            return null;   // nur eine Rueckseite, keine Vorderseite — kein vollstaendiger Nachweis
        }

        return $this->schreiben($employee, $code, $aktuell, (int) $vorne, $hinten, $bis, $via, $userId);
    }

    /**
     * Nach einer Aenderung von "gueltig bis" in der HR-Akte: ein bestehender
     * Nachweis bekommt das neue Datum; eine schon hochgeladene, dem Nachweis
     * unbekannte Datei dieser Anstellung wird dabei zum Nachweis. Leeren
     * aendert nichts (keine Aussage — das bisherige Datum bleibt).
     */
    public function nachDatum(RecEmployee $employee, string $code, ?int $userId = null): ?RecEmployeeProof
    {
        $bis = $this->datumAusSpalte($employee, $code);
        if ($bis === null) {
            return null;   // Leeren ist keine Aussage — das bisherige Datum bleibt
        }
        $aktuell = $this->aktuell($employee, $code);

        // Erst hochgeladen, dann das Datum eingetragen (Review 30.10.): liegt
        // hier eine Datei, die der Nachweis noch nicht kennt, kommt sie mit.
        $spalten = ProofTypes::legacyFileColumns($code);
        $spalteVorne = $this->id($employee->getAttribute($spalten[0] ?? ''));
        if ($spalteVorne !== null && ($aktuell === null || $aktuell->file_id === null)) {
            $hinten = isset($spalten[1]) ? $this->id($employee->getAttribute($spalten[1])) : null;

            return $this->schreiben($employee, $code, $aktuell, $spalteVorne, $hinten, $bis, 'hr', $userId);
        }

        if ($aktuell === null || $bis === $aktuell->valid_until?->format('Y-m-d')) {
            return null;
        }

        return $this->schreiben(
            $employee, $code, $aktuell,
            $aktuell->file_id !== null ? (int) $aktuell->file_id : null,
            $aktuell->file_back_id !== null ? (int) $aktuell->file_back_id : null,
            $bis, (string) $aktuell->uploaded_via, $userId,
        );
    }

    /** Nachweisarten, deren Ablaufspalte in $felder vorkommt. @return list<string> */
    public static function codesFuerAblaufSpalten(array $felder): array
    {
        $codes = [];
        foreach (ProofTypes::all() as $code) {
            $spalte = ProofTypes::legacyExpiryColumn($code);
            if ($spalte !== null && in_array($spalte, $felder, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function schreiben(
        RecEmployee $employee, string $code, ?RecEmployeeProof $aktuell,
        ?int $vorne, ?int $hinten, ?string $bis, string $via, ?int $userId,
    ): ?RecEmployeeProof {
        if ($aktuell !== null
            && $this->id($aktuell->file_id) === $vorne
            && $this->id($aktuell->file_back_id) === $hinten
            && $aktuell->valid_until?->format('Y-m-d') === $bis) {
            return null;   // nichts Neues
        }

        $ids = $this->scope->forEmployee($employee)['ids'];

        return DB::transaction(function () use ($employee, $code, $ids, $vorne, $hinten, $bis, $via, $userId) {
            // Wie ProofWriter: alle aktuellen Fassungen der Person abloesen.
            $bisher = RecEmployeeProof::query()->aktuell()->vonArt($code)->whereIn('rec_employee_id', $ids)->get();
            RecEmployeeProof::query()->whereIn('id', $bisher->pluck('id'))->update(['superseded_at' => now()]);
            $version = (int) RecEmployeeProof::query()->vonArt($code)->whereIn('rec_employee_id', $ids)->max('version');

            return RecEmployeeProof::create([
                'team_id'             => $employee->team_id,
                'rec_employee_id'     => $employee->id,
                'person_key'          => $employee->person_key,
                'proof_type_code'     => $code,
                'file_id'             => $vorne,
                'file_back_id'        => $hinten,
                'valid_until'         => $bis,
                'version'             => $version + 1,
                'uploaded_via'        => $via,
                'uploaded_by_user_id' => $userId,
            ]);
        });
    }

    private function aktuell(RecEmployee $employee, string $code): ?RecEmployeeProof
    {
        return $this->reader->current($employee)->first(fn ($p) => $p->proof_type_code === $code);
    }

    private function datumAusSpalte(RecEmployee $employee, string $code): ?string
    {
        $spalte = ProofTypes::legacyExpiryColumn($code);
        $wert = $spalte !== null ? $employee->getAttribute($spalte) : null;
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }

        return $wert ? substr((string) $wert, 0, 10) : null;
    }

    private function id($wert): ?int
    {
        return $wert === null || $wert === '' ? null : (int) $wert;
    }
}
