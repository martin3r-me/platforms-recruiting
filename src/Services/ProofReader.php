<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Support\PersonPflichten;
use Platform\Recruiting\Support\ProofChecklist;

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
        $pflicht = PersonPflichten::vereinige($this->pflichtQuellen($employee));

        $vorhanden = $this->current($employee)
            ->map(fn (RecEmployeeProof $p) => [
                'proof_type_code' => $p->proof_type_code,
                'valid_until'     => $p->valid_until?->toDateString(),
            ])
            ->all();

        return ProofChecklist::build($pflicht, $vorhanden, $heute ?? now()->toDateString());
    }

    /**
     * Die Felder, aus denen sich die Pflichtliste des MENSCHEN ergibt.
     *
     * Die Pflichten gehoeren dem Menschen (Spec 2.5): wer bei RG als Student
     * und bei MA als Aushilfe gefuehrt wird, braucht die Immatrikulation —
     * egal, mit welcher Anstellung er gerade zu tun hat. Die vorhandenen
     * Nachweise liest current() ohnehin schon ueber die ganze Person.
     *
     * Gezaehlt werden die AKTIVEN Anstellungen: was eine beendete Anstellung
     * einmal verlangt hat, ist erledigt, sobald eine andere weiterlaeuft.
     *
     * Der Rueckfall darunter ist die eigentliche Wache. Ohne ihn kaeme bei
     * einem Menschen OHNE aktive Anstellung eine leere Pflichtliste heraus —
     * und eine leere Checkliste liest sich in der Ansicht als "alles
     * erledigt" statt als "kein Nachweis da". Im Portal ist der Fall
     * unmoeglich (verifyPortalAccess verlangt is_active), in der
     * HR-Mitarbeiterakte nicht: Livewire/Employees/Show.php ruft checklist()
     * auch fuer beendete Mitarbeiter.
     *
     * Abgerufen wird deshalb EINMAL ohne Filter und danach in PHP gefiltert:
     * der Rueckfall braucht die beendeten Zeilen ohnehin, ein zweiter Abruf
     * waere nur teurer.
     *
     * Zum === true: rec_employees.is_active ist NOT NULL mit Vorgabe true
     * (Migration 2026_05_20_000001), ein NULL kann aus der echten Tabelle also
     * gar nicht kommen. Das ist keine Haltung zu NULL, sondern nur die strenge
     * Schreibweise — nachgemessen: die Mutation auf !== false ueberlebt den
     * Gesamtlauf, und zwar zu Recht. Auftauchen kann NULL nur in handgebauten
     * Testschemata; dort landet der Fall im Rueckfall darunter, und genau das
     * hat in ProofReaderTest den Filter lange still uebersprungen.
     *
     * @return list<array{is_eu_citizen: ?bool, employment_type: ?string, is_first_aider: ?bool}>
     */
    private function pflichtQuellen(RecEmployee $employee): array
    {
        $anstellungen = RecEmployee::query()
            ->whereIn('id', $this->scope->forEmployee($employee)['ids'])
            ->get(['is_active', 'is_eu_citizen', 'employment_type', 'is_first_aider']);

        $aktive = $anstellungen->filter(fn (RecEmployee $a) => $a->is_active === true);

        $quellen = $aktive->isNotEmpty() ? $aktive : $anstellungen;

        return $quellen
            ->map(fn (RecEmployee $a) => [
                'is_eu_citizen'   => $a->is_eu_citizen,
                'employment_type' => $a->employment_type,
                'is_first_aider'  => $a->is_first_aider,
            ])
            ->values()
            ->all();
    }

    public function openCount(RecEmployee $employee, ?string $heute = null): int
    {
        return ProofChecklist::offeneAnzahl($this->checklist($employee, $heute));
    }
}
