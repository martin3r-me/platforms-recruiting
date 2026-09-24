<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Support\ProofChecklist;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Der Leseweg: was hat dieser Mensch, was fehlt ihm, was laeuft ab.
 *
 * Gelesen wird ueber die Anstellungen der PERSON, nicht ueber die eine, mit
 * der er sich angemeldet hat. Wer bei RHEINGEDECK und MA arbeitet, hat einen
 * Ausweis, keine zwei.
 */
final class ProofReader
{
    public function __construct(private readonly PersonScopeResolver $scope = new PersonScopeResolver()) {}

    /** Die jeweils aktuelle Fassung je Nachweisart dieser Person. */
    public function current(RecEmployee $employee): Collection
    {
        return RecEmployeeProof::query()
            ->aktuell()
            ->whereIn('rec_employee_id', $this->scope->forEmployee($employee)['ids'])
            ->orderBy('proof_type_code')
            ->get();
    }

    /**
     * Die Aufgabenliste — abgeleitet aus Katalog, Pflicht-Regel und Bestand.
     *
     * @return list<array{code:string, label:string, status:string, valid_until:?string, offen:bool}>
     */
    public function checklist(RecEmployee $employee, ?string $heute = null): array
    {
        $pflicht = ProofTypes::requiredFor([
            'is_eu_citizen'   => $employee->is_eu_citizen,
            'employment_type' => $employee->employment_type,
            'is_first_aider'  => $employee->is_first_aider,
        ]);

        $vorhanden = $this->current($employee)
            ->map(fn (RecEmployeeProof $p) => [
                'proof_type_code' => $p->proof_type_code,
                'valid_until'     => $p->valid_until?->toDateString(),
            ])
            ->all();

        return ProofChecklist::build($pflicht, $vorhanden, $heute ?? now()->toDateString());
    }

    public function openCount(RecEmployee $employee, ?string $heute = null): int
    {
        return ProofChecklist::offeneAnzahl($this->checklist($employee, $heute));
    }
}
