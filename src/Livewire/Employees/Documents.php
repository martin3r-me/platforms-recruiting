<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Recruiting\Jobs\DokumentHinweiseVersenden;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentEmpfaengerSuche;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Services\DokumentZaehler;
use Platform\Recruiting\Support\DokumentAkteZeilen;
use Platform\Recruiting\Support\DokumentFortschritt;
use Platform\Recruiting\Support\DokumentKategorie;

/**
 * Seite Dokumente (Spec §3.4, §8): die lange Form zum Bereitstellen an eine
 * Gruppe (Suche, Firma, aktiv, Taetigkeit, Veranstaltung, Haken je Person)
 * und der Ueberblick ueber alles, was unterwegs ist — egal, von wo es
 * gestartet wurde. Der Empfaengerwaehler existiert NUR hier.
 *
 * Empfaenger sind OPT-IN: $ausgewaehlt startet leer, HR hakt bewusst an
 * (einzeln oder "alle Treffer"); die Auswahl bleibt ueber Such- und
 * Filterwechsel stehen. Versendet wird nur nach dem Bestaetigungsschritt und
 * nur an die serverseitig gegen das Team gepruefte Auswahl.
 */
class Documents extends Component
{
    use WithFileUploads;

    public $datei = null;
    public string $titel = '';
    public string $kategorie = 'other';
    public string $aktion = 'none';

    public string $suche = '';
    public string $firma = '';
    public string $aktiv = 'active';
    public string $taetigkeit = '';
    public ?int $eventId = null;
    /** @var list<int> ids, die HR bewusst angehakt hat (ungeprueft — Browser-Eingabe) */
    public array $ausgewaehlt = [];
    /** Bestaetigungsfenster offen: erst dort darf bereitstellen() wirklich senden. */
    public bool $bestaetigungOffen = false;

    public string $zeige = 'offen';
    public ?int $aufgeklappt = null;
    public ?string $flash = null;
    public ?string $flashError = null;

    private ?array $grenzeCache = null;

    private function teamId(): int
    {
        return (int) auth()->user()->currentTeam->id;
    }

    public function updatedKategorie(): void
    {
        $this->aktion = DokumentKategorie::exists($this->kategorie) ? DokumentKategorie::defaultAktion($this->kategorie) : 'none';
    }

    public function updatedDatei(): void
    {
        if ($this->titel === '' && $this->datei) {
            $this->titel = (string) pathinfo($this->datei->getClientOriginalName(), PATHINFO_FILENAME);
        }
    }

    /** Filterwechsel rechnen nur die Trefferliste neu — die Auswahl bleibt (Opt-in, Haken ueber Suchen hinweg). */
    public function updated(string $name): void
    {
        if (in_array($name, ['suche', 'firma', 'aktiv', 'taetigkeit', 'eventId'], true)) {
            unset($this->kandidaten, $this->abgeschnitten);
            $this->grenzeCache = null;
        }
        if ($name === 'ausgewaehlt' || str_starts_with($name, 'ausgewaehlt.')) {
            $this->auswahlSetzen($this->ausgewaehlt);
        }
    }

    /** Haken an einer Person setzen oder entfernen. */
    public function toggle(int $id): void
    {
        $this->auswahlSetzen(in_array($id, $this->auswahl(), true)
            ? array_diff($this->auswahl(), [$id])
            : [...$this->auswahl(), $id]);
    }

    /** "Alle Treffer auswaehlen" — gesperrt, solange die Liste abgeschnitten ist. */
    public function alleTrefferAn(): void
    {
        $g = $this->kandidatenMitGrenze();
        if ($g['abgeschnitten']) {
            $this->flashError = 'Mehr als ' . DokumentEmpfaengerSuche::LIMIT . ' Treffer — bitte Filter eingrenzen (z. B. Firma, Tätigkeit oder Veranstaltung).';

            return;
        }
        $this->auswahlSetzen([...$this->auswahl(), ...array_column($g['treffer'], 'id')]);
    }

    /** Alle sichtbaren Treffer abwaehlen; Auswahl aus frueheren Suchen bleibt. */
    public function alleTrefferAb(): void
    {
        $this->auswahlSetzen(array_diff($this->auswahl(), array_column($this->kandidatenMitGrenze()['treffer'], 'id')));
    }

    public function entfernen(int $id): void
    {
        $this->auswahlSetzen(array_diff($this->auswahl(), [$id]));
    }

    public function auswahlLeeren(): void
    {
        $this->auswahlSetzen([]);
    }

    /** @return list<int> */
    private function auswahl(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->ausgewaehlt), fn (int $id) => $id > 0)));
    }

    /** @param iterable<int|string> $ids */
    private function auswahlSetzen(iterable $ids): void
    {
        $liste = [];
        foreach ($ids as $id) {
            $liste[] = (int) $id;
        }
        $this->ausgewaehlt = array_values(array_unique(array_filter($liste, fn (int $id) => $id > 0)));
        $this->bestaetigungOffen = false;
        unset($this->ausgewaehltZeilen);
    }

    /**
     * Die angehakten Personen, frisch und team-geprueft (fremde/unbekannte ids
     * fallen weg). Anzeige UND Versand gehen hierueber.
     *
     * @return list<array{id:int, name:string, personnel_number:?string, company:?string, person_key:?string, hat_portal:bool}>
     */
    #[Computed]
    public function ausgewaehltZeilen(): array
    {
        return $this->gepruefteAuswahl();
    }

    /** @return list<array{id:int, name:string, personnel_number:?string, company:?string, person_key:?string, hat_portal:bool}> */
    private function gepruefteAuswahl(): array
    {
        return DokumentEmpfaengerSuche::zeilenFuerIds($this->teamId(), $this->auswahl());
    }

    /** @return list<array{id:int, name:string, personnel_number:?string, company:?string, person_key:?string, hat_portal:bool}> */
    #[Computed]
    public function kandidaten(): array
    {
        return $this->kandidatenMitGrenze()['treffer'];
    }

    #[Computed]
    public function abgeschnitten(): bool
    {
        return $this->kandidatenMitGrenze()['abgeschnitten'];
    }

    /** @return array{treffer: list<array>, abgeschnitten: bool} pro Request nur einmal gerechnet */
    private function kandidatenMitGrenze(): array
    {
        return $this->grenzeCache ??= DokumentEmpfaengerSuche::findeMitGrenze($this->teamId(), [
            'suche' => $this->suche, 'firma' => $this->firma, 'aktiv' => $this->aktiv,
            'taetigkeit' => $this->taetigkeit, 'event_id' => $this->eventId,
        ]);
    }

    /** @return list<int> die team-geprueften ids der Auswahl — genau die bekommen das Dokument */
    public function gewaehlteIds(): array
    {
        return array_column($this->gepruefteAuswahl(), 'id');
    }

    /** @return list<string> */
    #[Computed]
    public function taetigkeitOptionen(): array
    {
        return DokumentEmpfaengerSuche::taetigkeiten($this->teamId());
    }

    /** @return list<array{id:int, label:string}> kommende Veranstaltungen, naechste zuerst */
    #[Computed]
    public function eventOptionen(): array
    {
        return RecDispoEvent::query()
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()->toDateString()))
            ->orderBy('starts_on')
            ->limit(100)
            ->get(['id', 'name', 'einsatz_ref', 'starts_on'])
            ->map(fn (RecDispoEvent $e) => [
                'id'    => (int) $e->id,
                'label' => trim(($e->starts_on?->format('d.m.Y') ?? '') . ' ' . ($e->name ?: $e->einsatz_ref)),
            ])
            ->all();
    }

    /** Schritt vor dem Versand: prueft das Noetigste und oeffnet die Rueckfrage. */
    public function bereitstellenPruefen(): void
    {
        $this->flash = null;
        $this->flashError = null;
        $this->bestaetigungOffen = false;
        if (!$this->datei) {
            $this->flashError = 'Bitte eine PDF auswählen.';

            return;
        }
        if (trim($this->titel) === '') {
            $this->flashError = 'Bitte einen Titel angeben.';

            return;
        }
        if ($this->gewaehlteIds() === []) {
            $this->flashError = 'Bitte mindestens einen Empfänger wählen.';

            return;
        }
        $this->bestaetigungOffen = true;
    }

    /** Sendet nur aus dem offenen Bestaetigungsfenster heraus. */
    public function bereitstellen(): void
    {
        if (!$this->bestaetigungOffen) {
            return;
        }
        $this->bestaetigungOffen = false;
        $this->flash = null;
        $this->flashError = null;
        if (!$this->datei) {
            $this->flashError = 'Bitte eine PDF auswählen.';

            return;
        }
        $ids = $this->gewaehlteIds();
        if ($ids === []) {
            $this->flashError = 'Bitte mindestens einen Empfänger wählen.';

            return;
        }

        try {
            $e = app(DokumentService::class)->bereitstellen(
                $this->teamId(),
                ['title' => $this->titel, 'category' => $this->kategorie, 'action' => $this->aktion],
                (string) file_get_contents($this->datei->getRealPath()),
                (string) $this->datei->getClientOriginalName(),
                $ids,
                $this->eventId,
                auth()->id(),
            );
        } catch (\InvalidArgumentException $ex) {
            $this->flashError = $ex->getMessage();

            return;
        }

        DokumentHinweiseVersenden::starten($e['dokument']);

        $this->datei = null;
        $this->titel = '';
        $this->kategorie = 'other';
        $this->aktion = 'none';
        $this->auswahlSetzen([]);
        $this->aufgeklappt = (int) $e['dokument']->id;
        unset($this->dokumente, $this->kandidaten, $this->abgeschnitten);
        $this->grenzeCache = null;
        $this->flash = $e['versand_noetig']
            ? "An {$e['empfaenger']} Personen bereitgestellt, Versand läuft im Hintergrund."
            : "An {$e['empfaenger']} Personen abgelegt.";
    }

    public function aufklappen(int $documentId): void
    {
        $this->aufgeklappt = $this->aufgeklappt === $documentId ? null : $documentId;
    }

    public function erneutSenden(int $recipientId): void
    {
        $z = RecDocumentRecipient::query()->where('team_id', $this->teamId())->find($recipientId);
        if ($z === null) {
            return;
        }
        $status = app(DokumentService::class)->erneutSenden($z);
        $this->flash = $status === 'sent' ? 'WhatsApp erneut gesendet.' : null;
        $this->flashError = $status === 'sent' ? null : 'Nicht gesendet: ' . $status;
        unset($this->dokumente);
    }

    /**
     * "Versand erneut anstossen" (Final-Review Fund 1): stellt den Job noch
     * einmal ein. Idempotent — er versucht nur unversuchte Zeilen, schon
     * Benachrichtigte und Fehlversuche bleiben unberuehrt.
     */
    public function versandAnstossen(int $documentId): void
    {
        $d = RecDocument::query()->where('team_id', $this->teamId())->find($documentId);
        if ($d === null) {
            return;
        }
        DokumentHinweiseVersenden::starten($d);
        $this->flash = 'Versand neu angestoßen, läuft im Hintergrund.';
        $this->flashError = null;
        unset($this->dokumente);
    }

    public function zurueckziehen(int $recipientId): void
    {
        $z = RecDocumentRecipient::query()->where('team_id', $this->teamId())->find($recipientId);
        if ($z === null) {
            return;
        }
        $this->flashError = app(DokumentService::class)->zurueckziehen($z);
        $this->flash = $this->flashError === null ? 'Zurückgezogen.' : null;
        unset($this->dokumente);
    }

    public function dokumentZurueckziehen(int $documentId): void
    {
        $d = RecDocument::query()->where('team_id', $this->teamId())->find($documentId);
        if ($d === null) {
            return;
        }
        $e = app(DokumentService::class)->dokumentZurueckziehen($d);
        $this->flash = "{$e['zurueckgezogen']} Zustellungen zurückgezogen" . ($e['geloescht'] ? ', Dokument entfernt.' : ', Dokument bleibt als Nachweis.');
        unset($this->dokumente);
    }

    /**
     * Ueberblick: Dokumente ohne Zustellungen laden, die Zahlen kommen aus
     * EINER gruppierten Abfrage (DokumentZaehler). Volle Zeilen — ohne
     * signature_data — nur fuer das aufgeklappte Dokument.
     *
     * @return list<array{id:int, uuid:string, title:string, category_label:string, action:string, action_label:string, event:?string, erstellt:string, geloescht:bool, fortschritt:array, erledigt:bool, benachrichtigt:int, ausstehend:int, versand_laeuft:bool, empfaenger:list<array>}>
     */
    #[Computed]
    public function dokumente(): array
    {
        $teamId = $this->teamId();
        $docs = RecDocument::query()->withTrashed()
            ->where('team_id', $teamId)
            ->with('event')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
        $zahlen = DokumentZaehler::fuer($docs->pluck('id')->all());

        $out = [];
        foreach ($docs as $d) {
            $n = $zahlen[(int) $d->id] ?? DokumentZaehler::leer();
            $f = DokumentFortschritt::ausZaehlern($n, (string) $d->action);
            $brauchtVersand = DokumentKategorie::brauchtHandlung((string) $d->action);
            $ausstehend = $brauchtVersand ? $n['ausstehend'] : 0;
            // "laeuft" nur fuer unversuchte Zeilen innerhalb der Versandfrist;
            // aeltere sind liegengeblieben und stoppen das Pollen.
            $versandLaeuft = $brauchtVersand && $n['ausstehend'] - $n['ausstehend_alt'] > 0;
            $erledigt = $f['gesamt'] === 0 || $f['erledigt'] === $f['gesamt'];
            if ($this->zeige === 'offen' && $erledigt) {
                continue;
            }
            $empfaenger = [];
            if ($this->aufgeklappt === (int) $d->id) {
                $zeilen = RecDocumentRecipient::query()
                    ->ohneUnterschriftsbild()
                    ->where('rec_document_id', $d->id)
                    ->orderBy('id')
                    ->get()
                    ->each(fn ($z) => $z->setRelation('document', $d))
                    ->all();
                $namen = DB::table('rec_employees')->whereIn('id', array_map(fn ($z) => $z->rec_employee_id, $zeilen))
                    ->get(['id', 'first_name', 'last_name'])->keyBy('id');
                foreach (DokumentAkteZeilen::fuer($zeilen) as $zeile) {
                    $nm = $namen->get($zeile['employee_id']);
                    $zeile['name'] = $nm ? trim($nm->first_name . ' ' . $nm->last_name) : ('Mitarbeiter #' . $zeile['employee_id']);
                    $empfaenger[] = $zeile;
                }
            }
            $out[] = [
                'id'             => (int) $d->id,
                'uuid'           => (string) $d->uuid,
                'title'          => (string) $d->title,
                'category_label' => DokumentKategorie::label((string) $d->category),
                'action'         => (string) $d->action,
                'action_label'   => DokumentKategorie::aktionLabel((string) $d->action),
                'event'          => $d->event?->name ?: $d->event?->einsatz_ref,
                'erstellt'       => $d->created_at?->format('d.m.Y H:i') ?? '',
                'geloescht'      => $d->deleted_at !== null,
                'fortschritt'    => $f,
                'erledigt'       => $erledigt,
                'benachrichtigt' => $n['benachrichtigt'],
                'ausstehend'     => $ausstehend,
                'versand_laeuft' => $versandLaeuft,
                'empfaenger'     => $empfaenger,
            ];
        }

        return $out;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.documents')
            ->layout('platform::layouts.app');
    }
}
