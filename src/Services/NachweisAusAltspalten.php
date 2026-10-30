<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Support\ProofTypes;

/**
 * HR-Akte und ZAS-Datei-Eingang schreiben Dateien und Ablaufdaten in die
 * Altspalten von rec_employees — der Nachweis (rec_employee_proofs), aus dem
 * Portal, Aufgabenliste und Erinnerungen lesen, erfuhr davon nichts. Ein
 * Vermerk aus der alten Akte (AltaktenNachweise) blieb mit altem Datum
 * stehen, ein frischer HR-Upload galt im Portal als "fehlt" (30.10.2026).
 *
 * Hier wird nach so einem Schreiben der Nachweis ueber ProofWriter
 * nachgezogen — derselbe Weg wie beim Portal-Upload: neue Fassung, alte
 * abgeloest, Spiegel auf alle Anstellungen der Person.
 */
final class NachweisAusAltspalten
{
    public function __construct(
        private readonly ProofWriter $writer = new ProofWriter(),
        private readonly ProofReader $reader = new ProofReader(),
    ) {}

    /**
     * @param string $via uploaded_via der neuen Fassung (hr, zas)
     */
    public function nachziehen(RecEmployee $employee, string $code, string $via, ?int $userId = null): ?RecEmployeeProof
    {
        if (!ProofTypes::exists($code)) {
            return null;
        }

        $spalten = ProofTypes::legacyFileColumns($code);
        $vorne = isset($spalten[0]) ? $this->id($employee->getAttribute($spalten[0])) : null;
        $hinten = isset($spalten[1]) ? $this->id($employee->getAttribute($spalten[1])) : null;

        $aktuell = $this->reader->current($employee)->first(fn ($p) => $p->proof_type_code === $code);

        $bis = null;
        if (ProofTypes::hasExpiry($code)) {
            $spalte = ProofTypes::legacyExpiryColumn($code);
            $wert = $spalte !== null ? $employee->getAttribute($spalte) : null;
            // Leere Spalte heisst "keine Aussage" — dann gilt das bisherige
            // Datum weiter (ProofWriter spiegelt es sonst als null auf alle
            // Anstellungen der Person).
            $bis = $wert instanceof \DateTimeInterface
                ? $wert->format('Y-m-d')
                : ($wert ? substr((string) $wert, 0, 10) : $aktuell?->valid_until?->format('Y-m-d'));
        }

        if ($vorne === null) {
            // Ohne Vorderseite nur ein geaendertes Datum an einem bestehenden
            // Nachweis (z. B. HR korrigiert "Ausweis gueltig bis").
            if ($aktuell === null || $bis === $aktuell->valid_until?->format('Y-m-d')) {
                return null;
            }
            $vorne = $aktuell->file_id;
            $hinten ??= $aktuell->file_back_id;
            $via = (string) $aktuell->uploaded_via;
        }

        if ($aktuell !== null
            && $this->id($aktuell->file_id) === $vorne
            && ($hinten === null || $this->id($aktuell->file_back_id) === $hinten)
            && $aktuell->valid_until?->format('Y-m-d') === $bis) {
            return null;   // nichts Neues
        }

        return $this->writer->store($employee, $code, [
            'file_id'             => $vorne,
            'file_back_id'        => $hinten,
            'valid_until'         => $bis,
            'uploaded_via'        => $via,
            'uploaded_by_user_id' => $userId,
        ]);
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

    private function id($wert): ?int
    {
        return $wert === null || $wert === '' ? null : (int) $wert;
    }
}
