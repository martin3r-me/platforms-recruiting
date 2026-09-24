<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Support\ProofTypes;

/**
 * HR-Sicht auf Nachweise: was ist eingegangen, was ist offen.
 *
 * Kundenvorgabe 22.09.2026: HR prueft AUSSCHLIESSLICH Lohnrelevantes — ein
 * Nachweis-Upload gilt sofort als erledigt. Die einzige Ausnahme sind
 * Aufenthaltstitel und Arbeitsgenehmigung, an denen die harte Einsatzsperre
 * haengt; nur dort schaut ein Mensch auf das Datum und bestaetigt es
 * (ProofTypes::needsHrConfirmation()). Es gibt bewusst KEINEN allgemeinen
 * Freigabe-Arbeitsvorrat fuer alles andere — genau das soll dieses Projekt
 * abschaffen.
 *
 * Zwei Listen, eine Bestaetigungsstelle:
 *  - neuEingegangen()        reine Anzeige, jeder Nachweistyp, neueste zuerst
 *  - wartetAufBestaetigung() nur die zwei Pflicht-Arten ohne confirmed_at —
 *                            hier und NUR hier sitzt der Bestaetigen-Button
 */
class ProofInbox extends Component
{
    use WithPagination;

    /**
     * Am Umzugstag (recruiting:nachweise-umziehen) duerfte die Zahl der
     * offenen Aufenthaltstitel/Arbeitsgenehmigungen dreistellig sein — ohne
     * Blaetterung stuende das alles auf einer Seite. Die Grenze auf
     * uploaded_via wurde bewusst NICHT gezogen (siehe Klassendoku): fachlich
     * ist jeder ungeprueft laufende Nachweis relevant, egal woher er kommt.
     */
    private const PRO_SEITE = 25;

    public ?string $flash = null;

    /**
     * Die zuletzt eingegangenen Nachweise, neueste zuerst — reine Anzeige,
     * kein Handlungsbedarf. uploaded_via=import ist ABSICHTLICH ausgeschlossen:
     * das ist die einmalige Ruecksicherung der Altspalten (Kommando
     * recruiting:nachweise-umziehen), kein frischer Eingang — sonst wuerde
     * die Inbox am Umzugstag mit ~1.500 "neuen" Eintraegen von heute geflutet.
     * Begrenzung auf 50: eine Inbox zeigt das Neueste, kein Archiv.
     *
     * @return list<array{id:int, employee_id:int, name:string, label:string, valid_until:?string, created_at:string, needs_confirmation:bool, confirmed:bool, confirmed_by:?string, confirmed_at_human:?string}>
     */
    #[Computed]
    public function neuEingegangen(): array
    {
        $teamId = auth()->user()->currentTeam->id;

        return RecEmployeeProof::query()
            ->aktuell()
            ->where('team_id', $teamId)
            ->where('uploaded_via', '!=', 'import')
            ->with(['employee', 'confirmedByUser'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (RecEmployeeProof $p) => $this->row($p))
            ->all();
    }

    /**
     * Die eine Stelle, an der ein Mensch etwas bestaetigen muss: Aufenthaltstitel
     * und Arbeitsgenehmigung ohne confirmed_at. Dringendste zuerst — dringend
     * heisst hier: laeuft am schnellsten ab (valid_until aufsteigend, offene
     * Ablaufdaten zuletzt).
     *
     * Blaettert (PRO_SEITE): am Umzugstag kann diese Liste dreistellig
     * werden, siehe Klassendoku. through() mappt die Zeilen aufs Anzeige-Array,
     * ohne die Paginator-Metadaten (total(), links()) zu verlieren.
     */
    #[Computed]
    public function wartetAufBestaetigung(): LengthAwarePaginator
    {
        $teamId = auth()->user()->currentTeam->id;

        return RecEmployeeProof::query()
            ->aktuell()
            ->where('team_id', $teamId)
            ->wartetAufBestaetigung()
            ->with(['employee', 'confirmedByUser'])
            ->orderByRaw('valid_until IS NULL, valid_until ASC')
            ->paginate(self::PRO_SEITE)
            ->through(fn (RecEmployeeProof $p) => $this->row($p));
    }

    /**
     * Bestaetigt einen Nachweis — setzt confirmed_by_user_id + confirmed_at.
     *
     * Schreibt ueber den Query-Builder an Eloquent vorbei (wie ProofWriter):
     * rec_employee_proofs hat keinen Export-Observer, rec_employees wird hier
     * gar nicht angefasst — kein zas_changed_at, kein Export-Marker.
     *
     * Waechter:
     *  - Team-Scope serverseitig neu geprueft, nicht nur in der Liste gefiltert
     *    (ein manipulierter wire:click darf kein fremdes Mandat treffen).
     *  - Nur die zwei Pflicht-Arten duerfen bestaetigt werden.
     *  - Atomar statt nur idempotent: die Bedingung confirmed_at IS NULL steht
     *    IM Update selbst, nicht nur in einer vorherigen Lese-Pruefung — sonst
     *    kommen zwei gleichzeitige Klicks (zwei HR-Leute auf denselben
     *    abgelaufenen Aufenthaltstitel) beide an der Pruefung vorbei, und der
     *    zweite ueberschreibt confirmed_by_user_id/confirmed_at des ersten.
     *    Muster wie NotifyWaitlistForInterview::notifyEntries() — nur wer die
     *    Zeile mit dem Update "gewinnt" (1 betroffene Zeile), hat bestaetigt.
     */
    public function bestaetige(int $proofId): void
    {
        $teamId = auth()->user()->currentTeam->id;

        $proof = RecEmployeeProof::query()
            ->aktuell()
            ->where('id', $proofId)
            ->where('team_id', $teamId)
            ->first();

        if ($proof === null) {
            $this->flash = 'Nachweis nicht gefunden.';
            return;
        }
        if (!ProofTypes::needsHrConfirmation($proof->proof_type_code)) {
            $this->flash = 'Diese Art braucht keine Bestaetigung.';
            return;
        }

        $betroffen = RecEmployeeProof::query()
            ->where('id', $proof->id)
            ->where('team_id', $teamId)
            ->whereNull('confirmed_at')
            ->update([
                'confirmed_by_user_id' => auth()->id(),
                'confirmed_at'         => now(),
            ]);

        if ($betroffen !== 1) {
            // Jemand war schneller — die erste Bestaetigung bleibt stehen,
            // das ist die Audit-Spur. Kein zweites Schreiben.
            $this->flash = 'War schon bestaetigt.';
            unset($this->wartetAufBestaetigung, $this->neuEingegangen);
            return;
        }

        $this->flash = 'Bestaetigt.';
        unset($this->wartetAufBestaetigung, $this->neuEingegangen);
    }

    /** @return array{id:int, employee_id:int, name:string, label:string, valid_until:?string, created_at:string, needs_confirmation:bool, confirmed:bool, confirmed_by:?string, confirmed_at_human:?string} */
    private function row(RecEmployeeProof $p): array
    {
        $emp = $p->employee;
        $name = $emp
            ? trim(($emp->first_name ?? '') . ' ' . ($emp->last_name ?? '')) ?: ('Mitarbeiter #' . $emp->id)
            : 'Unbekannt';

        return [
            'id'                  => $p->id,
            'employee_id'         => (int) $p->rec_employee_id,
            'name'                => $name,
            'label'               => $p->label(),
            'valid_until'         => $p->valid_until?->toDateString(),
            'created_at'          => $p->created_at?->toIso8601String() ?? '',
            'needs_confirmation'  => ProofTypes::needsHrConfirmation($p->proof_type_code),
            'confirmed'           => $p->confirmed_at !== null,
            // Wozu wir confirmed_by_user_id ueberhaupt speichern: in der
            // Anzeige nennen ("bestaetigt von <Name>"), nicht nur im Feld
            // ablegen und nie wieder anschauen.
            'confirmed_by'        => $p->confirmedByUser?->name,
            'confirmed_at_human'  => $p->confirmed_at?->format('d.m.Y H:i'),
        ];
    }

    public function render()
    {
        return view('recruiting::livewire.employees.proof-inbox')
            ->layout('platform::layouts.app');
    }
}
