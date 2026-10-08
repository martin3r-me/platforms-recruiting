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
use Platform\Recruiting\Support\DokumentAkteZeilen;
use Platform\Recruiting\Support\DokumentFortschritt;
use Platform\Recruiting\Support\DokumentKategorie;

/**
 * Seite Dokumente (Spec §3.4, §8): die lange Form zum Bereitstellen an eine
 * Gruppe (Suche, Firma, aktiv, Taetigkeit, Veranstaltung, Haken je Person)
 * und der Ueberblick ueber alles, was unterwegs ist — egal, von wo es
 * gestartet wurde. Der Empfaengerwaehler existiert NUR hier.
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
    /** @var list<int> ids, die HR abgewaehlt hat */
    public array $abgewaehlt = [];
    public bool $waehlerOffen = false;

    public string $zeige = 'offen';
    public ?int $aufgeklappt = null;
    public ?string $flash = null;
    public ?string $flashError = null;

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

    /** Jeder Filterwechsel setzt die Abwahl zurueck — sonst bleiben Haken an Personen haengen, die nicht mehr in der Liste sind. */
    public function updated(string $name): void
    {
        if (in_array($name, ['suche', 'firma', 'aktiv', 'taetigkeit', 'eventId'], true)) {
            $this->abgewaehlt = [];
            unset($this->kandidaten);
        }
    }

    public function toggle(int $id): void
    {
        $this->abgewaehlt = in_array($id, $this->abgewaehlt, true)
            ? array_values(array_diff($this->abgewaehlt, [$id]))
            : [...$this->abgewaehlt, $id];
    }

    /** @return list<array{id:int, name:string, personnel_number:?string, company:?string, person_key:?string, hat_portal:bool}> */
    #[Computed]
    public function kandidaten(): array
    {
        return DokumentEmpfaengerSuche::finde($this->teamId(), [
            'suche' => $this->suche, 'firma' => $this->firma, 'aktiv' => $this->aktiv,
            'taetigkeit' => $this->taetigkeit, 'event_id' => $this->eventId,
        ]);
    }

    /** @return list<int> */
    public function gewaehlteIds(): array
    {
        return array_values(array_filter(
            array_column($this->kandidaten, 'id'),
            fn (int $id) => !in_array($id, $this->abgewaehlt, true)
        ));
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

    public function bereitstellen(): void
    {
        $this->flash = null;
        $this->flashError = null;
        if (!$this->datei) {
            $this->flashError = 'Bitte eine PDF auswählen.';

            return;
        }
        $ids = $this->gewaehlteIds();

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
        $this->abgewaehlt = [];
        $this->waehlerOffen = false;
        $this->aufgeklappt = (int) $e['dokument']->id;
        unset($this->dokumente, $this->kandidaten);
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
     * @return list<array{id:int, uuid:string, title:string, category_label:string, action:string, action_label:string, event:?string, erstellt:string, geloescht:bool, fortschritt:array, erledigt:bool, benachrichtigt:int, ausstehend:int, empfaenger:list<array>}>
     */
    #[Computed]
    public function dokumente(): array
    {
        $teamId = $this->teamId();
        $docs = RecDocument::query()->withTrashed()
            ->where('team_id', $teamId)
            ->with(['recipients', 'event'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $out = [];
        foreach ($docs as $d) {
            $zeiten = $d->recipients->map(fn (RecDocumentRecipient $z) => $z->zeitstempel())->all();
            $f = DokumentFortschritt::fuer($zeiten, (string) $d->action);
            $brauchtVersand = DokumentKategorie::brauchtHandlung((string) $d->action);
            $benachrichtigt = $d->recipients->whereNotNull('notified_at')->count();
            $ausstehend = $brauchtVersand
                ? $d->recipients->filter(fn ($z) => $z->withdrawn_at === null && $z->notified_at === null && $z->notify_error === null)->count()
                : 0;
            $erledigt = $f['gesamt'] === 0 || $f['erledigt'] === $f['gesamt'];
            if ($this->zeige === 'offen' && $erledigt) {
                continue;
            }
            // $ausstehend wird oben berechnet und unten mitgegeben; die Zeile bleibt
            // auch bei "nur offene" sichtbar, solange der Versand laeuft.
            $empfaenger = [];
            if ($this->aufgeklappt === (int) $d->id) {
                $zeilen = $d->recipients->each(fn ($z) => $z->setRelation('document', $d))->sortBy('id')->values()->all();
                $namen = DB::table('rec_employees')->whereIn('id', array_map(fn ($z) => $z->rec_employee_id, $zeilen))
                    ->get(['id', 'first_name', 'last_name'])->keyBy('id');
                foreach (DokumentAkteZeilen::fuer($zeilen) as $zeile) {
                    $n = $namen->get($zeile['employee_id']);
                    $zeile['name'] = $n ? trim($n->first_name . ' ' . $n->last_name) : ('Mitarbeiter #' . $zeile['employee_id']);
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
                'benachrichtigt' => $benachrichtigt,
                'ausstehend'     => $ausstehend,
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
