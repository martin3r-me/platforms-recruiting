<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Recruiting\Livewire\HrDesk\Mitarbeiter;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Support\HrAktionErgebnis;
use Platform\Recruiting\Support\SperrGruende;

/**
 * Konto-Kasten in der Mitarbeiterakte (Spec HR-Bereich §4). Eigene
 * Kind-Komponente, damit Show.php nicht waechst. Die Knoepfe liegen NUR hier
 * (der Pilot-Ueberblick verlinkt hierher). Liest nur HrMitarbeiterListen,
 * schreibt ausschliesslich ueber HrKontoAktionen — die prueft bei JEDER
 * Aktion erneut Team und Zustand.
 *
 * SICHERHEIT (Spec §9.2): Akten-Kennung und Modal-Kontext sind #[Locked] und
 * werden nur serverseitig gesetzt (oeffne()); erlaubte Aktionen: die
 * geschlossene Liste AKTIONEN.
 *
 * EINMAL-ANZEIGE (Spec §4.3): Link und Code stehen in PRIVATEN Feldern. Eine
 * oeffentliche Eigenschaft — auch #[Locked] — wuerde in den wire:snapshot
 * dehydriert und laege damit im Quelltext der Seite, bis das Modal zu ist
 * (Lehre aus KontoAnlegen). Private Felder leben genau fuer die eine Antwort:
 * render() gibt sie an die View, der Schnappschuss kennt sie nicht, der
 * naechste Request (Schliessen, Neuladen) hat sie nicht mehr. Nie im Log,
 * nie im Vermerk, nie in der Meldung, nie in der URL.
 */
class KontoKasten extends Component
{
    /** Geschlossene Liste: Aktion => Modal-Titel. */
    public const AKTIONEN = [
        'einladung_neu'          => 'Einladung neu',
        'konto_zuruecksetzen'    => 'Konto zurücksetzen',
        'portal_entsperren'      => 'Portal entsperren',
        'konto_entsperren'       => 'Konto entsperren',
        'bremse_loesen'          => 'Anmelde-Bremse lösen',
        'notfallsperre_aufheben' => 'Notfallsperre aufheben',
    ];

    /** Bestaetigungs-Warnung beim Zuruecksetzen (Wirkung siehe KontoWriter::setzeKontoZurueckDurchHr). */
    public const WARNUNG_ZURUECKSETZEN = 'Das Passwort wird gelöscht, laufende Anmeldungen im Portal enden sofort. '
        . 'Ein offener Nummernwechsel-Antrag wird dabei entfernt. '
        . 'Der Mensch registriert sich neu — mit dem neuen Code und seinem Geburtsdatum.';

    /** Der rote Kasten "MA-Portal-Zugang gesperrt" in show.blade.php oeffnet dieses Modal. */
    public const EVENT_PORTAL_ENTSPERREN = 'konto-kasten-portal-entsperren';

    #[Locked] public int $employeeId;

    public bool $modalOffen = false;
    #[Locked] public string $aktion = '';
    #[Locked] public ?int $aktionEmployeeId = null;
    #[Locked] public string $aktionWarnung = '';
    #[Locked] public string $modalFehler = '';
    public string $notiz = '';

    #[Locked] public string $meldung = '';
    #[Locked] public string $meldungStufe = '';

    /** Steuert nur das Modal; der Inhalt steht in den privaten Feldern darunter. */
    public bool $einladungOffen = false;
    private ?string $einladungsLink = null;
    private ?string $einladungsCode = null;

    public function mount(int $employeeId): void
    {
        $this->employeeId = $employeeId;
    }

    #[Computed]
    public function kasten(): ?array
    {
        return app(HrMitarbeiterListen::class)->kasten($this->teamId(), $this->employeeId);
    }

    #[On(self::EVENT_PORTAL_ENTSPERREN)]
    public function oeffnePortalEntsperren(): void
    {
        $this->oeffne('portal_entsperren');
    }

    /** Der Parameter kommt aus dem Browser und ist Eingabe: mixed + geschlossene Liste. */
    public function oeffne(mixed $aktion): void
    {
        $this->schliesse();
        if (!is_string($aktion) || !array_key_exists($aktion, self::AKTIONEN)) {
            return;
        }
        $kasten = $this->kasten();
        if ($kasten === null) {
            $this->meldung = HrKontoAktionen::NICHT_GEFUNDEN;
            $this->meldungStufe = HrAktionErgebnis::FEHLER;

            return;
        }
        if ($aktion === 'portal_entsperren') {
            // Die gesperrte Akte aus dem Grund (kann die Schwester-Akte sein), serverseitig gesetzt.
            $grund = collect($kasten['gruende'])->firstWhere('code', SperrGruende::DISPO);
            $this->aktionEmployeeId = (int) ($grund['employee_id'] ?? $this->employeeId);
        }
        if ($aktion === 'konto_zuruecksetzen') {
            $this->aktionWarnung = self::WARNUNG_ZURUECKSETZEN;
        }
        $this->aktion = $aktion;
        $this->modalOffen = true;
    }

    public function bestaetige(): void
    {
        if (!$this->modalOffen || !array_key_exists($this->aktion, self::AKTIONEN)) {
            return;
        }
        $teamId = $this->teamId();
        $a = app(HrKontoAktionen::class);
        // Nur die Personen-Zeile der Akte im eigenen Team; HrKontoAktionen prueft erneut.
        $personId = (int) (DB::table('rec_employees')->where('team_id', $teamId)->where('id', $this->employeeId)->value('rec_person_id') ?? 0);

        $e = match ($this->aktion) {
            'einladung_neu'          => $a->ladeNeuEin($this->employeeId, $this->notiz),
            'konto_zuruecksetzen'    => $a->setzeKontoZurueck($this->employeeId, $this->notiz),
            'portal_entsperren'      => $a->entsperrePortal((int) ($this->aktionEmployeeId ?? $this->employeeId), $this->notiz),
            'konto_entsperren'       => $a->entsperreKonto($personId, $this->notiz),
            'bremse_loesen'          => $a->loeseBremse($personId, $this->notiz),
            'notfallsperre_aufheben' => $a->hebeNotfallSperreAuf($personId, $this->notiz),
        };

        if ($e->stufe === HrAktionErgebnis::FEHLER) {
            $this->modalFehler = $e->meldung;

            return;
        }

        $this->schliesse();
        $this->meldung = $e->meldung;
        $this->meldungStufe = $e->stufe;
        if ($e->einladungsCode !== null) {
            $this->einladungsLink = $e->einladungsLink;
            $this->einladungsCode = $e->einladungsCode;
            $this->einladungOffen = true;
        }
        unset($this->kasten);
        // Abzeichen und Reiter-Zahlen des HR-Schreibtischs sind zwischengespeichert.
        Mitarbeiter::zaehlerVergessen($teamId);
        $this->dispatch(Mitarbeiter::EVENT_GEAENDERT);
    }

    public function schliesse(): void
    {
        $this->modalOffen = false;
        $this->aktion = '';
        $this->aktionEmployeeId = null;
        $this->aktionWarnung = '';
        $this->modalFehler = '';
        $this->notiz = '';
        $this->meldung = '';
        $this->meldungStufe = '';
    }

    public function schliesseEinladung(): void
    {
        $this->einladungOffen = false;
        $this->einladungsLink = null;
        $this->einladungsCode = null;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.konto-kasten', [
            'einladungsLink' => $this->einladungsLink,
            'einladungsCode' => $this->einladungsCode,
        ]);
    }

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }
}
