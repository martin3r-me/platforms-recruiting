<?php

namespace Platform\Recruiting\Livewire\HrDesk;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Support\HrAktionErgebnis;
use Platform\Recruiting\Support\KontoStatus;
use Platform\Recruiting\Support\NummernwechselTafel;

/**
 * Reiter "Mitarbeiter" am HR-Schreibtisch (Spec HR-Bereich 2026-10-12 §3).
 * Eigene Kind-Komponente, weil HrDesk\Index im Modul nicht instanziierbar
 * ist (Muster Dispo\HrDeclines). Liest nur HrMitarbeiterListen, schreibt nur
 * ueber HrKontoAktionen.
 *
 * SICHERHEIT (Spec §9.2): der Kontext eines offenen Modals ist #[Locked] und
 * wird nur in oeffne() serverseitig gesetzt; die Parameter von oeffne() sind
 * Eingabe und werden gegen das Team geprueft; bestaetige() prueft erneut
 * (HrKontoAktionen). Erlaubte Aktionen: geschlossene Liste AKTIONEN.
 */
class Mitarbeiter extends Component
{
    public const LISTEN = [
        'antraege' => 'Konto-Anträge',
        'gesperrt' => 'Gesperrt',
        'erinnert' => 'Erinnert, aber keine Reaktion',
        'pilot'    => 'Pilot-Überblick',
    ];

    /** Geschlossene Liste: Aktion => Modal-Titel. */
    public const AKTIONEN = [
        'stoppen'                => 'Antrag stoppen',
        'freigeben'              => 'Antrag freigeben',
        'konto_entsperren'       => 'Konto entsperren',
        'portal_entsperren'      => 'Portal entsperren',
        'bremse_loesen'          => 'Anmelde-Bremse lösen',
        'notfallsperre_aufheben' => 'Notfallsperre aufheben',
        'kontaktiert'            => 'Kontaktiert',
        'erneut_erinnern'        => 'Erneut erinnern',
    ];

    public const SEITE = 50;

    #[Url] public string $liste = 'antraege';
    #[Url] public string $pilotFilter = 'alle';
    public string $suche = '';
    public bool $ruhendeZeigen = false;
    public bool $gestoppteZeigen = false;
    public int $seite = 1;

    public bool $modalOffen = false;
    #[Locked] public string $aktion = '';
    #[Locked] public ?int $aktionPersonId = null;
    #[Locked] public ?int $aktionEmployeeId = null;
    #[Locked] public string $aktionBezug = '';
    #[Locked] public string $erwartetBeantragtAt = '';
    #[Locked] public string $aktionWarnung = '';
    #[Locked] public string $modalFehler = '';
    public string $notiz = '';

    #[Locked] public string $meldung = '';
    #[Locked] public string $meldungStufe = '';

    public function placeholder(): string
    {
        return '<div class="py-8 text-center text-sm text-[var(--ui-muted)]">Mitarbeiter-Listen werden geladen …</div>';
    }

    /** #[Url]-Werte sind Eingabe: auf die geschlossenen Listen zurueckfuehren. */
    public function mount(): void
    {
        $this->zeigeListe($this->liste);
        $this->zeigePilotFilter($this->pilotFilter);
    }

    public function zeigeListe(string $liste): void
    {
        $this->liste = array_key_exists($liste, self::LISTEN) ? $liste : 'antraege';
        $this->seite = 1;
    }

    public function zeigePilotFilter(string $filter): void
    {
        $this->pilotFilter = array_key_exists($filter, KontoStatus::FILTER) ? $filter : 'alle';
        $this->seite = 1;
    }

    public function updatedSuche(): void
    {
        $this->seite = 1;
    }

    public function updatedRuhendeZeigen(): void
    {
        $this->seite = 1;
    }

    public function blaettern(int $seite): void
    {
        $this->seite = max(1, $seite);
    }

    public function seitenZeilen(array $zeilen): array
    {
        // Nach einer Aktion kann die Liste kuerzer sein als die gemerkte Seite.
        $seite = min($this->seite, $this->seitenAnzahl($zeilen));

        return array_slice($zeilen, ($seite - 1) * self::SEITE, self::SEITE);
    }

    public function seitenAnzahl(array $zeilen): int
    {
        return max(1, (int) ceil(count($zeilen) / self::SEITE));
    }

    #[Computed]
    public function antraege(): array
    {
        return $this->listen()->antraege($this->teamId(), $this->suche);
    }

    #[Computed]
    public function gestoppte(): array
    {
        return $this->listen()->gestoppte($this->teamId());
    }

    #[Computed]
    public function gesperrt(): array
    {
        // Die Drossel (Cache) erst, wenn der Unter-Reiter offen ist (Spec §3.2).
        return $this->listen()->gesperrt($this->teamId(), $this->suche, $this->liste === 'gesperrt');
    }

    #[Computed]
    public function erinnert(): array
    {
        return $this->listen()->erinnert($this->teamId(), $this->suche, $this->ruhendeZeigen);
    }

    #[Computed]
    public function pilot(): array
    {
        return $this->listen()->pilot($this->teamId(), $this->suche, $this->pilotFilter);
    }

    /** @return array<string,int> Zahl je Unter-Reiter (ohne Suche). */
    #[Computed]
    public function zahlen(): array
    {
        $teamId = $this->teamId();
        $l = $this->listen();

        return [
            'antraege' => count($l->antraege($teamId)),
            'gesperrt' => count($l->gesperrt($teamId, '', $this->liste === 'gesperrt')),
            'erinnert' => count($l->erinnert($teamId)),
            'pilot'    => $l->pilot($teamId)['zahlen']['alle'],
        ];
    }

    public function oeffne(string $aktion, ?int $personId = null, ?int $employeeId = null, string $bezug = ''): void
    {
        $this->schliesse();
        if (!array_key_exists($aktion, self::AKTIONEN)) {
            return;
        }
        $teamId = $this->teamId();
        // Stoppen braucht nur die Personen-Zeile im Team (Antraege ohne Akte im
        // Team, HrMitarbeiterListen::OHNE_AKTE); alles andere die volle Sichtbarkeit.
        $imTeam = $aktion === 'stoppen'
            ? HrKontoAktionen::antragsPersonImTeam($teamId, $personId)
            : HrKontoAktionen::sichtbar($teamId, $personId, $employeeId);
        if (!$imTeam) {
            $this->meldung = HrKontoAktionen::NICHT_GEFUNDEN;
            $this->meldungStufe = HrAktionErgebnis::FEHLER;

            return;
        }

        if ($aktion === 'freigeben') {
            $antrag = DB::table('rec_persons')->where('id', $personId)->first(['wechsel_beantragt_at', 'wechsel_neue_nummer']);
            if ($antrag === null || $antrag->wechsel_beantragt_at === null) {
                $this->meldung = 'Der Antrag ist nicht mehr offen.';
                $this->meldungStufe = HrAktionErgebnis::HINWEIS;

                return;
            }
            $this->erwartetBeantragtAt = (string) $antrag->wechsel_beantragt_at;
            $this->aktionWarnung = sprintf(
                'Die Nummer wechselt sofort auf …%s. Wer diesen Antrag gestellt hat, kann danach mit „Passwort vergessen“ ein neues Passwort setzen. Nur freigeben, wenn Sie mit der Person gesprochen haben.',
                NummernwechselTafel::kurz((string) $antrag->wechsel_neue_nummer),
            );
        }

        $this->aktion = $aktion;
        $this->aktionPersonId = $personId;
        $this->aktionEmployeeId = $employeeId;
        $this->aktionBezug = $bezug;
        $this->modalOffen = true;
    }

    public function bestaetige(): void
    {
        if (!$this->modalOffen || !array_key_exists($this->aktion, self::AKTIONEN)) {
            return;
        }
        $a = app(HrKontoAktionen::class);
        $pid = (int) $this->aktionPersonId;
        $eid = (int) $this->aktionEmployeeId;

        $e = match ($this->aktion) {
            'stoppen'                => $a->stoppeAntrag($pid, $this->notiz),
            'freigeben'              => $a->gibAntragFrei($pid, $this->erwartetBeantragtAt, $this->notiz),
            'konto_entsperren'       => $a->entsperreKonto($pid, $this->notiz),
            'portal_entsperren'      => $a->entsperrePortal($eid, $this->notiz),
            'bremse_loesen'          => $a->loeseBremse($pid, $this->notiz),
            'notfallsperre_aufheben' => $a->hebeNotfallSperreAuf($pid, $this->notiz),
            'kontaktiert'            => $a->kontaktiert($this->aktionPersonId, $this->aktionEmployeeId, $this->notiz),
            'erneut_erinnern'        => $a->erinnereErneut($this->aktionPersonId, $this->aktionEmployeeId, $this->aktionBezug, $this->notiz),
        };

        if ($e->stufe === HrAktionErgebnis::FEHLER) {
            $this->modalFehler = $e->meldung;

            return;
        }

        $this->schliesse();
        $this->meldung = $e->meldung;
        $this->meldungStufe = $e->stufe;
        unset($this->antraege, $this->gestoppte, $this->gesperrt, $this->erinnert, $this->pilot, $this->zahlen);
    }

    public function schliesse(): void
    {
        $this->modalOffen = false;
        $this->aktion = '';
        $this->aktionPersonId = null;
        $this->aktionEmployeeId = null;
        $this->aktionBezug = '';
        $this->erwartetBeantragtAt = '';
        $this->aktionWarnung = '';
        $this->modalFehler = '';
        $this->notiz = '';
        $this->meldung = '';
        $this->meldungStufe = '';
    }

    public function render()
    {
        return view('recruiting::livewire.hr-desk.mitarbeiter');
    }

    private function listen(): HrMitarbeiterListen
    {
        return app(HrMitarbeiterListen::class);
    }

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }
}
