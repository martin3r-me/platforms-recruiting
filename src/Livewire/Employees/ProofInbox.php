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
 * Nachweis-Upload gilt sofort als erledigt. Es gibt bewusst KEINEN
 * allgemeinen Freigabe-Arbeitsvorrat — genau das soll dieses Projekt
 * abschaffen.
 *
 * Korrektur K3 (24.09.2026): Es gibt HIER KEINE Bestaetigung mit Wirkung
 * (mehr). Der urspruengliche Plan war, dass HR bei Aufenthaltstitel und
 * Arbeitsgenehmigung das Datum bestaetigt, weil daran die harte
 * Einsatzsperre haengt — das war ein Denkfehler: die Sperre gibt es nur fuer
 * BEWERBER (LegalStatusGate), nicht fuer Mitarbeiter, und
 * residence_permit_valid_until/work_permit_valid_until werden nirgends im
 * Modul fuer eine Sperre gelesen. Ein Upload spiegelt sein Datum SOFORT in
 * die Akte und den ZAS-Export; eine Bestaetigung danach haette also nichts
 * mehr zu verhindern. Eine Oberflaeche, die eine Kontrolle verspricht, die es
 * nicht gibt, ist schlechter als keine — deshalb wurde der Bestaetigen-Knopf
 * samt bestaetige()-Aktion entfernt.
 *
 * Zwei Listen, beide reine Anzeige, kein Handlungsbedarf:
 *  - neuEingegangen()        jeder Nachweistyp, neueste zuerst
 *  - wartetAufBestaetigung() Aufenthaltstitel und Arbeitsgenehmigung "zur
 *                            Kenntnis" — HR soll sehen, wenn ein neuer
 *                            Aufenthaltstitel oder eine Arbeitsgenehmigung
 *                            eingegangen ist, muss hier aber nichts tun.
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
     * Aufenthaltstitel und Arbeitsgenehmigung "zur Kenntnis" — REINE Anzeige,
     * kein Handlungsbedarf (Korrektur K3, siehe Klassendoku: es gibt keine
     * Einsatzsperre, die daran haengt). Dringendste zuerst — dringend heisst
     * hier: laeuft am schnellsten ab (valid_until aufsteigend, offene
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
