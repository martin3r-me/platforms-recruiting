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
use Platform\Recruiting\Support\DokumentVerlauf;
use Platform\Recruiting\Support\InfoText;

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
 *
 * Umschalter PDF | Info (Spec 2026-10-16 §3.2): eine Info ist Text
 * (Markdown aus dem Editor) statt PDF, Kategorie fest info, keine Aktion.
 * Sie darf ohne Empfaenger raus, wenn der Haken "automatisch an neue
 * Mitarbeiter" sitzt. Der PDF-Weg bleibt davon unberuehrt.
 */
class Documents extends Component
{
    use WithFileUploads;

    public $datei = null;
    public string $titel = '';
    public string $kategorie = 'other';
    public string $aktion = 'none';

    /** 'pdf' | 'info' — Spec 2026-10-16 §3.2 */
    public string $art = 'pdf';
    /** Markdown aus dem Editor (bzw. dem Rueckfall-Textfeld) */
    public string $infoMarkdown = '';
    /** Haken "automatisch an neue Mitarbeiter (beim ersten Oeffnen des Portals)" */
    public bool $autoNeue = false;
    public bool $vorschauOffen = false;
    /** Haken "WhatsApp-Hinweis senden" (PDF und Info), vorausgewaehlt an — aus = notify_mode none. */
    public bool $whatsappHinweis = true;

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

    /** Umschalten verwirft eine offene Rueckfrage — sie galt dem anderen Weg. */
    public function updatedArt(): void
    {
        if (!in_array($this->art, ['pdf', 'info'], true)) {
            $this->art = 'pdf';
        }
        $this->flashError = null;
        $this->bestaetigungOffen = false;
    }

    /** Vorschau = derselbe Renderer wie im Portal (§5.3): HR sieht, was der Mitarbeiter sieht. */
    #[Computed]
    public function vorschauHtml(): string
    {
        return InfoText::html($this->infoMarkdown);
    }

    private function istInfo(): bool
    {
        return $this->art === 'info';
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
        if ($this->istInfo()) {
            $this->flashError = $this->infoFehler();
            $this->bestaetigungOffen = $this->flashError === null;

            return;
        }
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
        if ($this->istInfo()) {
            $this->infoBereitstellen();

            return;
        }
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
                ['title' => $this->titel, 'category' => $this->kategorie, 'action' => $this->aktion, 'notify_mode' => $this->notifyMode(), 'einstieg' => 'dokumente'],
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

        $ohneWhatsApp = !$this->whatsappHinweis && DokumentKategorie::brauchtHandlung($this->aktion);
        $this->datei = null;
        $this->titel = '';
        $this->kategorie = 'other';
        $this->aktion = 'none';
        $this->whatsappHinweis = true;
        $this->auswahlSetzen([]);
        $this->aufgeklappt = (int) $e['dokument']->id;
        unset($this->dokumente, $this->kandidaten, $this->abgeschnitten);
        $this->grenzeCache = null;
        $this->flash = match (true) {
            $e['versand_noetig'] => "An {$e['empfaenger']} Personen bereitgestellt, Versand läuft im Hintergrund.",
            $ohneWhatsApp        => "An {$e['empfaenger']} Personen bereitgestellt, ohne WhatsApp.",
            default              => "An {$e['empfaenger']} Personen abgelegt.",
        };
    }

    private function notifyMode(): string
    {
        return $this->whatsappHinweis ? DokumentService::NOTIFY_WHATSAPP : DokumentService::NOTIFY_NONE;
    }

    /** Pflichtpruefung der Info vor der Rueckfrage; null = alles da. Die Datei des PDF-Wegs zaehlt hier nicht. */
    private function infoFehler(): ?string
    {
        if (trim($this->titel) === '') {
            return 'Bitte einen Titel angeben.';
        }
        if (trim($this->infoMarkdown) === '') {
            return 'Bitte einen Text schreiben.';
        }
        if ($this->gewaehlteIds() === [] && !$this->autoNeue) {
            return 'Bitte mindestens einen Empfänger wählen oder „automatisch an neue Mitarbeiter“ anhaken.';
        }

        return null;
    }

    /**
     * Info-Weg von bereitstellen(): Text statt PDF. Mit Empfaengern geht die
     * Portal-WhatsApp wie bei einer PDF ueber den Job (Spec §1.3, §7); nur
     * Automatik = kein Job. Danach Formular leeren und den Editor im Browser
     * per Event mitleeren (er steht unter wire:ignore).
     */
    private function infoBereitstellen(): void
    {
        $this->flashError = $this->infoFehler();
        if ($this->flashError !== null) {
            return;
        }
        $titel = trim($this->titel);
        // Vor dem Leeren der Auswahl: wer im alten Portal ist, bekommt keine WhatsApp (Sender: altes_portal).
        $ohnePortal = count(array_filter($this->gepruefteAuswahl(), fn ($k) => !$k['hat_portal']));

        try {
            $e = app(DokumentService::class)->bereitstellenText(
                $this->teamId(),
                ['title' => $titel, 'auto_neue_mitarbeiter' => $this->autoNeue, 'notify_mode' => $this->notifyMode(), 'einstieg' => 'dokumente'],
                $this->infoMarkdown,
                $this->gewaehlteIds(),
                auth()->id(),
            );
        } catch (\InvalidArgumentException $ex) {
            $this->flashError = $ex->getMessage();

            return;
        }

        if ($e['versand_noetig']) {
            DokumentHinweiseVersenden::starten($e['dokument']);
        }

        $auto = $this->autoNeue;
        $ohneWhatsApp = !$this->whatsappHinweis;
        $this->titel = '';
        $this->infoMarkdown = '';
        $this->autoNeue = false;
        $this->whatsappHinweis = true;
        $this->vorschauOffen = false;
        $this->auswahlSetzen([]);
        $this->aufgeklappt = (int) $e['dokument']->id;
        unset($this->dokumente, $this->kandidaten, $this->abgeschnitten, $this->vorschauHtml);
        $this->grenzeCache = null;
        $this->dispatch('info-bereitgestellt');

        $n = $e['empfaenger'];
        if ($n === 0) {
            $this->flash = "Info „{$titel}“ wird automatisch an neue Mitarbeiter zugestellt (beim ersten Öffnen des Portals).";
        } elseif ($ohneWhatsApp) {
            $this->flash = "Info „{$titel}“ an {$n} Empfänger bereitgestellt, ohne WhatsApp"
                . ($auto ? '; zusätzlich automatisch an neue Mitarbeiter.' : '.');
        } elseif ($ohnePortal === 0) {
            $this->flash = "Info „{$titel}“ bereitgestellt: WhatsApp geht an {$n} Empfänger"
                . ($auto ? '; zusätzlich automatisch an neue Mitarbeiter.' : '.');
        } else {
            // Zwei Aussagen statt "geht an 2, davon 1 ohne" (Review Task 5, M2):
            // die WhatsApp-Zahl ist nur die im neuen Portal.
            $mitWhatsApp = max(0, $n - $ohnePortal);
            $this->flash = "Info „{$titel}“ an {$n} Empfänger bereitgestellt: WhatsApp geht an {$mitWhatsApp} Empfänger; "
                . "{$ohnePortal} im alten Portal: keine Nachricht, sichtbar nach der Umstellung"
                . ($auto ? '; zusätzlich automatisch an neue Mitarbeiter.' : '.');
        }
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

    /**
     * "WhatsApp jetzt senden" an einem Dokument "ohne WhatsApp": bewusste
     * HR-Handlung, dreht notify_mode auf whatsapp und startet denselben Job
     * wie das Bereitstellen (er schreibt nur unversuchte Zeilen an).
     */
    public function whatsappJetztSenden(int $documentId): void
    {
        $d = RecDocument::query()->where('team_id', $this->teamId())->find($documentId);
        if ($d === null) {
            return;
        }
        if (!app(DokumentService::class)->whatsappJetzt($d, auth()->id())) {
            $this->flash = null;
            $this->flashError = "„{$d->title}“: keine WhatsApp nötig oder schon unterwegs.";
            unset($this->dokumente);

            return;
        }
        DokumentHinweiseVersenden::starten($d->fresh());
        $this->flash = "WhatsApp für „{$d->title}“ wird jetzt verschickt, läuft im Hintergrund.";
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
     * Nur die Automatik einer Info stoppen (Spec 2026-10-16 §6, Review Task 5
     * M3): "Zurueckziehen" nimmt die Info allen weg und loescht sie — wer nur
     * keine weiteren Neuen beliefern will, braucht den sanfteren Knopf.
     */
    public function automatikBeenden(int $documentId): void
    {
        $d = RecDocument::query()->where('team_id', $this->teamId())->find($documentId);
        if ($d === null) {
            return;
        }
        app(DokumentService::class)->automatikBeenden($d);
        $this->flash = "Automatik für „{$d->title}“ beendet — wer die Info schon hat, behält sie.";
        $this->flashError = null;
        unset($this->dokumente);
    }

    /**
     * Ueberblick: Dokumente ohne Zustellungen laden, die Zahlen kommen aus
     * EINER gruppierten Abfrage (DokumentZaehler). Volle Zeilen — ohne
     * signature_data — nur fuer das aufgeklappte Dokument.
     *
     * @return list<array{id:int, uuid:string, title:string, category_label:string, action:string, is_info:bool, auto:bool, published:?string, automatisch:int, braucht_hinweis:bool, ohne_whatsapp:bool, kann_whatsapp_jetzt:bool, action_label:string, event:?string, erstellt:string, geloescht:bool, fortschritt:array, erledigt:bool, zurueckziehbar:bool, benachrichtigt:int, ausstehend:int, versand_laeuft:bool, empfaenger:list<array>, verlauf:list<array>}>
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
            // "ohne WhatsApp": kein Fortschritt "benachrichtigt", kein "Versand erneut anstossen",
            // stattdessen Abzeichen + Knopf "WhatsApp jetzt senden".
            $brauchtVersand = DokumentService::hinweisFaellig((string) $d->category, (string) $d->action, $d->notify_mode);
            $ohneWhatsApp = $d->notify_mode === DokumentService::NOTIFY_NONE;
            $ausstehend = $brauchtVersand ? $n['ausstehend'] : 0;
            // "laeuft" nur fuer unversuchte Zeilen innerhalb der Versandfrist;
            // aeltere sind liegengeblieben und stoppen das Pollen.
            $versandLaeuft = $brauchtVersand && $n['ausstehend'] - $n['ausstehend_alt'] > 0;
            $istInfo = DokumentKategorie::istText((string) $d->category);
            // Info mit Automatik-Haken arbeitet noch (wartet auf Neue): nie
            // "erledigt", sonst verschwaende sie unter "Nur offene" samt Knopf
            // zum Stoppen — auch mit 0 Empfaengern (Review Task 5, M3).
            $auto = $istInfo && (bool) $d->auto_neue_mitarbeiter && $d->deleted_at === null;
            $erledigt = !$auto && ($f['gesamt'] === 0 || $f['erledigt'] === $f['gesamt']);
            if ($this->zeige === 'offen' && $erledigt) {
                continue;
            }
            $empfaenger = [];
            $verlauf = [];
            if ($this->aufgeklappt === (int) $d->id) {
                // Nachweis "wer hat was wann getan" — nur fuer das aufgeklappte Dokument geladen.
                $verlauf = DokumentVerlauf::fuer((int) $d->id);
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
                'is_info'        => $istInfo,
                'auto'           => $auto,
                'published'      => $d->published_at?->format('d.m.Y'),
                'automatisch'    => $n['automatisch'],
                'braucht_hinweis' => $brauchtVersand,
                'ohne_whatsapp'  => $ohneWhatsApp,
                'kann_whatsapp_jetzt' => $ohneWhatsApp && $d->deleted_at === null
                    && DokumentKategorie::brauchtHinweis((string) $d->category, (string) $d->action),
                'action_label'   => DokumentKategorie::aktionLabel((string) $d->action),
                'event'          => $d->event?->name ?: $d->event?->einsatz_ref,
                'erstellt'       => $d->created_at?->format('d.m.Y H:i') ?? '',
                'geloescht'      => $d->deleted_at !== null,
                'fortschritt'    => $f,
                'erledigt'       => $erledigt,
                // Infos sind unveraenderlich ("geaenderte Info = neue Info"): eine
                // falsche muss auch dann weg koennen, wenn alle sie gesehen haben
                // (Schlussreview I-1). PDFs: wie bisher nur solange offen.
                'zurueckziehbar' => $d->deleted_at === null && (!$erledigt || $istInfo),
                'benachrichtigt' => $n['benachrichtigt'],
                'ausstehend'     => $ausstehend,
                'versand_laeuft' => $versandLaeuft,
                'empfaenger'     => $empfaenger,
                'verlauf'        => $verlauf,
            ];
        }

        return $out;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.documents', [
            'lohnOffen' => PayrollChanges::offeneAnzahl((int) auth()->user()->currentTeam->id),
        ])
            ->layout('platform::layouts.app');
    }
}
