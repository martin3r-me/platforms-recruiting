<?php

namespace Platform\Recruiting\Livewire\Conversations;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;

/**
 * Neue Kommunikation (Vorschau unter /recruiting/conversations-neu).
 * Postfach-Layout mit Ampel; die alte Seite laeuft unveraendert weiter.
 *
 * @see docs/superpowers/specs/2026-09-15-kommunikation-chat-design.md
 */
class Inbox extends Component
{
    public string $level = 'all';
    public string $owner = 'all';
    public string $search = '';
    public bool $showHandled = false;
    public ?int $selectedThreadId = null;
    public int $perPage = 50;
    public bool $selectMode = false;
    public array $selected = [];
    public string $replyText = '';
    public ?string $sendError = null;
    public bool $showOooPanel = false;
    public bool $showOooForm = false;
    public array $oooForm = ['from' => '', 'until' => '', 'back_at' => ''];
    public ?int $linkingThreadId = null;
    public string $linkSearch = '';

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }

    /**
     * EIN Aufruf pro Render: Zaehler, Zeilen, Gesamtzahl und das
     * Rueckfall-Signal fallen gemeinsam aus snapshot(). Getrennte Aufrufe
     * wuerden die Grundmenge zweimal laden — bei ~1000 Threads und einem
     * 20-Sekunden-Poll die doppelte Arbeit pro Tick.
     */
    #[Computed]
    public function snapshot(): array
    {
        return app(InboxQuery::class)->snapshot($this->teamId(), $this->filter(), $this->perPage, 0);
    }

    #[Computed]
    public function counts(): array
    {
        return $this->snapshot['counts'];
    }

    /**
     * true = die Grundmenge lief ueber die engere Altmenge (kein Kanal-Set).
     * Kommt aus demselben Durchlauf — NICHT ueber einen zweiten Aufruf von
     * RecruitingChannelResolver::isConfigured(), der die Aufloesung erneut
     * ausfuehren wuerde.
     */
    #[Computed]
    public function fallback(): bool
    {
        return (bool) $this->snapshot['fallback'];
    }

    private function filter(): InboxFilter
    {
        return new InboxFilter(
            level: $this->level,
            owner: $this->owner,
            search: $this->search,
            handled: $this->showHandled,
            currentUserId: (int) Auth::id(),
        );
    }

    #[Computed]
    public function rows(): array
    {
        return $this->snapshot['rows'];
    }

    #[Computed]
    public function total(): int
    {
        return $this->snapshot['total'];
    }

    #[Computed]
    public function teamUsers(): array
    {
        return Auth::user()->currentTeam->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name])
            ->all();
    }

    public function setLevel(string $level): void
    {
        // Zweiter Klick auf dieselbe Pille loest den Filter wieder.
        $this->level = ($this->level === $level) ? 'all' : $level;
        $this->showHandled = false;
        $this->discardSelectionOnFilterChange();
        $this->resetPage();
    }

    public function toggleHandledView(): void
    {
        $this->showHandled = !$this->showHandled;
        $this->level = 'all';
        $this->discardSelectionOnFilterChange();
        $this->resetPage();
    }

    public function loadMore(): void
    {
        $this->perPage += 50;
        $this->forgetSnapshot();
    }

    /**
     * Einziger updated()-Haken der Komponente — deckt sowohl das Ooo-Verhalten
     * (Bis-Datum -> Wieder-da vorbefuellen) als auch das Filter-Aufraeumen ab,
     * das vorher in updatedSearch()/updatedOwner() (Task 5) lag. Beides in
     * separaten Haken UND hier zu haben, wuerde Livewire fuer 'search'/'owner'
     * zweimal auslösen (den spezifischen updatedX()-Haken UND diesen
     * generischen) — kein Beinbruch (siehe Docblock von
     * discardSelectionOnFilterChange()), aber unklar. Deshalb EIN Weg: die
     * beiden alten Haken entfallen, ihr Verhalten steht jetzt hier.
     */
    public function updated($property): void
    {
        if ($property === 'oooForm.until' && $this->oooForm['until'] !== '' && $this->oooForm['back_at'] === '') {
            $this->oooForm['back_at'] = \Carbon\Carbon::parse($this->oooForm['until'])->addDay()->format('Y-m-d');
        }
        if ($property === 'search' || $property === 'owner') {
            $this->discardSelectionOnFilterChange();
            $this->resetPage();
        }
    }

    /**
     * Fix-Runde 1, Befund 1: sendHoldingToSelected() baut seine Empfaenger
     * NUR aus dem aktuellen $this->rows. Ohne dieses Abraeumen ueberlebt
     * $selected einen Filterwechsel unveraendert — "ein paar Chats
     * markieren, Filter wechseln, Wir melden uns klicken" liesse dann nur
     * die zufaellige Schnittmenge mit den NEUEN Zeilen durchgehen, im
     * schlimmsten Fall keine einzige, ohne dass das sichtbar waere.
     * $selectMode bleibt bewusst an: die Aktionsleiste zeigt "0 markiert"
     * und macht den Verlust sichtbar, statt ihn kommentarlos zu verstecken.
     */
    private function discardSelectionOnFilterChange(): void
    {
        $this->selected = [];
    }

    private function resetPage(): void
    {
        $this->perPage = 50;
        $this->forgetSnapshot();
    }

    /**
     * Raeumt nur die aus snapshot() abgeleiteten Computed-Werte ab, ohne
     * perPage anzufassen. select() nutzt das: ein geoeffneter Chat darf die
     * per "mehr laden" erweiterte Seite nicht wieder auf 50 zuruecksetzen.
     * Filter-/Suchwechsel gehen weiterhin ueber resetPage(), das zusaetzlich
     * perPage zuruecksetzt.
     */
    /**
     * selectedThread() bleibt hier bewusst aussen vor: sie haengt einzig an
     * $selectedThreadId (kein Ableger von snapshot()) und wird bei jeder
     * Aenderung von $selectedThreadId (select(), back()) ohnehin ueber den
     * naechsten Request neu ausgewertet — ein zusaetzliches unset() wuerde
     * hier nichts abraeumen, was nicht schon durch die geaenderte Property
     * veraltet waere.
     */
    private function forgetSnapshot(): void
    {
        unset(
            $this->snapshot,
            $this->rows,
            $this->total,
            $this->counts,
            $this->fallback,
            $this->selectedRow,
            $this->messages,
            $this->contextChips,
        );
    }

    /**
     * Laedt einen Thread NUR im Team-Kontext — nie eine fremde ID.
     *
     * Fix (Abschluss-Durchsicht, Befund 5, IMPORTANT): bisher pruefte diese
     * Methode NUR team_id. Ein praeparierter Aufruf (z.B. markHandled(),
     * select(), linkToApplicant()) mit der ID eines Dispo-Threads DESSELBEN
     * Teams rendert damit einen Dispo-Chat im Recruiting-Postfach, samt
     * Antwortfeld und Erledigt-Knopf. Kein Mandanten-Leck (gleiches Team),
     * aber fachlich falsch — der Kanal war das ganze Feature ueber die
     * Grundmenge (siehe RecruitingChannelResolver-Docblock), hier fehlte
     * genau diese Einschraenkung.
     *
     * NUR einschraenken, wenn das Kanal-Set nicht leer ist — sonst wuerde ein
     * Team OHNE konfiguriertes WABA-Konto (Rueckfall-Modus, siehe snapshot())
     * gar keinen Thread mehr oeffnen koennen, weil dann jede ID durch ein
     * leeres whereIn() faellt.
     */
    protected function threadForTeam(int $threadId): ?CommsWhatsAppThread
    {
        $query = CommsWhatsAppThread::query()
            ->whereKey($threadId)
            ->where('team_id', $this->teamId());

        $channelIds = \Platform\Recruiting\Services\Comms\RecruitingChannelResolver::channelIds($this->teamId());
        if ($channelIds !== []) {
            $query->whereIn('comms_channel_id', $channelIds);
        }

        return $query->first();
    }

    #[Computed]
    public function selectedThread(): ?CommsWhatsAppThread
    {
        return $this->selectedThreadId === null ? null : $this->threadForTeam($this->selectedThreadId);
    }

    /** Die Listenzeile zum offenen Chat (Titel, Ampel, Owner, Link). */
    #[Computed]
    public function selectedRow(): ?\Platform\Recruiting\Services\Comms\InboxRow
    {
        foreach ($this->rows as $row) {
            if ($row->threadId === $this->selectedThreadId) {
                return $row;
            }
        }

        // Der Chat kann durch einen Filterwechsel aus der Liste gefallen sein —
        // dann einzeln nachladen, statt den Verlauf zu schliessen. Bewusst
        // NICHT ueber page()/snapshot(): das wuerde die volle Grundmenge
        // (scored(), alle Threads des Kanals) UND die Bewerber-Volltabelle
        // (allowedSubjectIds(), alle Bewerber samt CRM-Kontakt) neu laden,
        // nur um eine einzige Zeile ueber einen Telefonnummer-Suchtreffer zu
        // finden — bei jedem 20s-Poll erneut, solange der Chat aus dem
        // Filter draussen bleibt. rowForThread() loest genau diese eine
        // Zeile auf, ohne beides.
        $thread = $this->selectedThread;
        if ($thread === null) {
            return null;
        }

        return app(InboxQuery::class)->rowForThread($thread, $this->teamId());
    }

    #[Computed]
    public function messages(): array
    {
        $thread = $this->selectedThread;
        if ($thread === null) {
            return [];
        }

        return app(\Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory::class)
            ->messages($thread, []);
    }

    /** @return list<array{label: string, value: string, url: ?string}> */
    #[Computed]
    public function contextChips(): array
    {
        $row = $this->selectedRow;
        if ($row === null || $row->subjectType !== 'applicant' || $row->subjectId === null) {
            return [];
        }

        $applicant = \Platform\Recruiting\Models\RecApplicant::query()
            ->with(['phase', 'position', 'postings.position'])
            ->find($row->subjectId);
        if ($applicant === null) {
            return [];
        }

        $chips = [];
        if ($applicant->phase) {
            $chips[] = ['label' => 'Phase', 'value' => (string) $applicant->phase->name, 'url' => null];
        }

        // Fix (Abschluss-Durchsicht, Befund 6): applicant->position (rec_position_id)
        // ist bei Altbestand vor der Einfuehrung dieses Felds leer. Ersatzweise die
        // erste Stelle aus positions() (ueber die verknuepften Anzeigen/postings),
        // wie im Entwurf vorgesehen — sonst bleibt der Stellen-Chip bei genau den
        // Altfaellen leer, die ihn am noetigsten haetten.
        $position = $applicant->position ?: $applicant->positions()->first();
        if ($position) {
            // Spalte heisst 'title', nicht 'name' (src/Models/RecPosition.php,
            // $fillable) — mit 'name' bliebe der Chip dauerhaft leer, ohne
            // dass es kracht (Eloquent liefert fuer ein unbekanntes Attribut
            // still null).
            $chips[] = ['label' => 'Stelle', 'value' => (string) $position->title, 'url' => null];
        }

        // Filter (nur kuenftige Termine) UND Sortierung laufen komplett in
        // der DB: whereHas() auf die Termin-Relation statt alle Buchungen zu
        // laden und in PHP zu filtern; orderBy() ueber eine korrelierte
        // Subquery statt einer Collection-Sortierung nach dem Laden. first()
        // holt genau die eine benoetigte Zeile.
        $booking = $applicant->interviewBookings()
            ->whereIn('status', ['registered', 'confirmed'])
            ->whereHas('interview', fn ($q) => $q->where('starts_at', '>=', now()))
            ->with('interview')
            ->orderBy(
                \Platform\Recruiting\Models\RecInterview::query()
                    ->select('starts_at')
                    ->whereColumn('id', 'rec_interview_bookings.rec_interview_id')
            )
            ->first();

        if ($booking) {
            $chips[] = [
                'label' => 'Termin',
                'value' => $booking->interview->starts_at->format('d.m.Y H:i'),
                'url' => null,
            ];
        }

        return $chips;
    }

    /**
     * CRITICAL-Fix (Abschluss-Durchsicht, Befund 1): windowOpen() las bisher
     * $row->escalation->windowOpen. ConversationEscalation ist ein
     * ESKALATIONS-Modell (isUnanswered/Level), kein Fenster-Modell: sobald
     * ueberhaupt einmal geantwortet wurde, ist isUnanswered false und
     * compute() liefert LEVEL_NONE mit windowOpen=false — unabhaengig vom
     * echten 24h-Service-Window von Meta. Folge: die ERSTE Antwort einer
     * Konversation liess das Eingabefeld sofort verschwinden und die Seite
     * behauptete "Fenster zu", obwohl Meta noch bis zu 24h ab dem letzten
     * EINGANG offen ist — jede Konversation waere eine Ein-Antwort-
     * Konversation gewesen.
     *
     * Fix: das Fenster selbst pruefen, nicht die Eskalation erben —
     * DispoTimeCalculator::isReplyWindowOpen() (rein datumsbasiert, siehe
     * deren Docblock) auf last_inbound_at DES THREADS. Die Dispo-Klasse wird
     * nur AUFGERUFEN, nicht veraendert (Tabu-Liste).
     */
    #[Computed]
    public function windowOpen(): bool
    {
        return $this->computeWindowOpen($this->selectedThread);
    }

    /**
     * Reine Entscheidung, ausgelagert aus windowOpen(): $this->selectedThread
     * ist eine #[Computed]-Eigenschaft und ihr Zugriff laeuft ueber Livewires
     * __get()-Hook (SupportComputedProperties), der einen gebooteten
     * Livewire-Mechanismus braucht — in diesem Modul (Capsule-Tests ohne
     * vollen App-Boot, siehe phpunit.xml-Kommentar) nicht ohne Weiteres
     * testbar. Diese Methode nimmt den Thread stattdessen als Parameter
     * entgegen und ist damit ein normaler Methodenaufruf, kein
     * Property-Zugriff — direkt mit einem echten CommsWhatsAppThread aus
     * der Datenbank aufrufbar, ohne Livewire zu booten. Genau in dieser
     * Luecke blieb der Fehler aus Befund 1 unentdeckt.
     *
     * $now optional (Konvention des Moduls, siehe ArchiveOldConversations::
     * planFor()/ConversationEscalation::compute()): windowOpen() ruft ohne
     * Override auf (echtes "jetzt"), Tests koennen ein festes Datum
     * hineingeben, ohne von der echten Systemzeit abzuhaengen.
     */
    public function computeWindowOpen(?CommsWhatsAppThread $thread, ?\DateTimeInterface $now = null): bool
    {
        if ($thread === null || $thread->last_inbound_at === null) {
            return false;
        }

        return \Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator::isReplyWindowOpen(
            $thread->last_inbound_at,
            $now ?? now(),
        );
    }

    /**
     * Vorlagen fuer die Knopfleiste bei geschlossenem Fenster — NUR ohne
     * Platzhalter im Textkoerper: fuer die anderen fehlen hier die Parameter,
     * und ein leer gefuellter Parameter ist schlimmer als ein fehlender Knopf.
     *
     * Fix-Runde 1, Befund 3: Meta erlaubt {{n}} auch in einer HEADER-Komponente
     * vom Typ TEXT, nicht nur in BODY — eine Vorlage mit Header-Platzhalter
     * scheitert beim Senden genauso garantiert. Der Body-Check laeuft ueber
     * WhatsAppTemplateBodyVariables::names() (Schwesterklasse-Konvention,
     * siehe deren Docblock) statt einer dritten eigenen str_contains-Fassung;
     * fuer HEADER gibt es im Modul keine solche geteilte Klasse, deshalb
     * direkt hier geprueft.
     *
     * @return list<array{id: int, label: string}>
     */
    #[Computed]
    public function chatTemplates(): array
    {
        $accountId = \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam($this->teamId())
            ->getSetting('auto_pilot_wa_account_id');

        $query = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->where('status', 'APPROVED');
        if ($accountId) {
            $query->where('whatsapp_account_id', (int) $accountId);
        }

        return $query->orderBy('name')->get()
            ->filter(function ($template) {
                $components = (array) ($template->components ?? []);

                if (\Platform\Recruiting\Support\WhatsAppTemplateBodyVariables::names($components) !== []) {
                    return false;
                }

                foreach ($components as $component) {
                    if (($component['type'] ?? '') === 'HEADER'
                        && str_contains((string) ($component['text'] ?? ''), '{{')) {
                        return false;
                    }
                }

                return true;
            })
            ->map(fn ($template) => ['id' => (int) $template->id, 'label' => (string) $template->name])
            ->values()
            ->all();
    }

    /** Einzelnen Chat abhaken. Nur ueber threadForTeam() — nie eine fremde ID. */
    public function markHandled(int $threadId): void
    {
        $thread = $this->threadForTeam($threadId);
        if ($thread === null) {
            return;
        }

        \Platform\Recruiting\Models\RecConversationHandled::updateOrCreate(
            ['comms_whatsapp_thread_id' => $threadId],
            [
                'team_id' => $this->teamId(),
                'handled_at' => now(),
                'handled_by_user_id' => (int) Auth::id(),
                'handled_reason' => \Platform\Recruiting\Models\RecConversationHandled::REASON_MANUAL,
            ],
        );

        if ($this->selectedThreadId === $threadId) {
            $this->selectedThreadId = null;
        }
        $this->forgetSnapshot();
        $this->dispatch('sidebar-refresh');
    }

    /**
     * Stempel wieder loeschen — scharf auf team_id, damit keine fremde Zeile
     * trifft. Fix-Runde 1, Kleinigkeit: spiegelbildlich zu markHandled()
     * schliesst auch dieser Weg den gerade offenen Chat, wenn er selbst
     * betroffen ist — sonst zeigt der Kopf weiter "zurückholen", weil dieser
     * Knopf am Listenfilter ($showHandled) haengt, nicht am Zustand des
     * Threads: nach dem Zurueckholen passt der Thread nicht mehr zum
     * Erledigt-Filter, unter dem "zurückholen" ueberhaupt erst sichtbar war.
     */
    public function unmarkHandled(int $threadId): void
    {
        \Platform\Recruiting\Models\RecConversationHandled::query()
            ->where('team_id', $this->teamId())
            ->where('comms_whatsapp_thread_id', $threadId)
            ->delete();

        if ($this->selectedThreadId === $threadId) {
            $this->selectedThreadId = null;
        }
        $this->forgetSnapshot();
        $this->dispatch('sidebar-refresh');
    }

    public function toggleSelectMode(): void
    {
        $this->selectMode = !$this->selectMode;
        $this->selected = [];
    }

    public function selectAllVisible(): void
    {
        $this->selected = array_map(fn ($row) => (string) $row->threadId, $this->rows);
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * Fix-Runde 2, Befund B1 (Teilfall): $rows kann zwischen Markieren und
     * Klick schrumpfen, auch OHNE dass jemand einen Filter wechselt — ein
     * Kollege hakt denselben Chat parallel ab, oder eine Eskalationsstufe
     * kippt mit der Zeit. Weicht die Zahl der tatsaechlich wirksamen IDs von
     * der Zahl der markierten IDs ab, muss das IMMER in der Rueckmeldung
     * auftauchen, nicht nur beim Totalausfall. Der Text macht klar: die
     * uebersprungenen Chats sind nicht verschwunden, nur nicht mehr in der
     * aktuellen Ansicht wirksam.
     */
    private function verlorenHinweis(int $anzahl): string
    {
        if ($anzahl <= 0) {
            return '';
        }

        return $anzahl === 1
            ? ' 1 markierter Chat war dabei nicht mehr wirksam (nicht verschwunden, nur nicht mehr in der aktuellen Ansicht) — bitte prüfen und ggf. erneut markieren.'
            : " {$anzahl} markierte Chats waren dabei nicht mehr wirksam (nicht verschwunden, nur nicht mehr in der aktuellen Ansicht) — bitte prüfen und ggf. erneut markieren.";
    }

    /**
     * Fix-Runde 3, Befund 3: eigener, ehrlicher Text fuer
     * markSelectedHandled() — anders als bei sendHoldingToSelected() faellt
     * eine ID hier nicht raus, weil sie aus der aktuellen ANSICHT gerutscht
     * ist, sondern weil ConversationBulkHandler sie beim whereIn gegen das
     * eigene Team NICHT gefunden hat (falsches Team oder gar kein Thread
     * mehr). "nicht mehr in der aktuellen Ansicht" / "erneut markieren"
     * waeren hier irrefuehrend — ein erneutes Markieren aendert nichts.
     */
    private function nichtGestempeltHinweis(int $anzahl): string
    {
        if ($anzahl <= 0) {
            return '';
        }

        return $anzahl === 1
            ? ' 1 markierte ID gehörte nicht zum eigenen Team oder existiert nicht mehr und wurde übersprungen.'
            : " {$anzahl} markierte IDs gehörten nicht zum eigenen Team oder existieren nicht mehr und wurden übersprungen.";
    }

    /**
     * Sammel-Erledigen. Fix-Runde 1, Befund 2: laeuft NICHT mehr als
     * Schleife ueber markHandled() (das waeren bei 200 Chats ~600 Queries:
     * threadForTeam() + zwei fuer updateOrCreate() je ID) — ConversationBulkHandler
     * prueft die Team-Zugehoerigkeit EINMAL per whereIn und schreibt die
     * Stempel EINMAL per upsert(). Die Team-Pruefung faellt dabei nicht weg,
     * sie wandert nur aus der Schleife in den Handler.
     *
     * Fix-Runde 2: leere Auswahl meldet sich jetzt genau wie
     * sendHoldingToSelected() ("Bitte zuerst Chats markieren."), statt den
     * Auswahlmodus wortlos zu beenden (Asymmetrie aus dem Review). Weicht
     * die Rueckgabe des Handlers (tatsaechlich gestempelte IDs) von der
     * Markierung ab, wird das ebenfalls gemeldet statt geschluckt — mit
     * nichtGestempeltHinweis() (Fix-Runde 3), NICHT mit verlorenHinweis():
     * eine ID faellt hier nicht aus der Ansicht, sondern aus der
     * Team-Pruefung des Handlers heraus, das ist ein anderer Grund.
     *
     * Fix-Runde 3, Befund 1: $ids wird VOR dem Zaehlen und Weiterreichen
     * dedupliziert — $selected ist eine ungeschuetzte Livewire-Eigenschaft
     * (von aussen setzbar) und kann dieselbe ID mehrfach enthalten. Ohne
     * Dedup faellt count($ids) hoeher aus als die Zahl eindeutiger IDs, die
     * der Handler stempelt — eine falsche "war nicht mehr wirksam"-Meldung
     * trotz vollem Erfolg waere die Folge.
     */
    public function markSelectedHandled(): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->selected)));
        if ($ids === []) {
            session()->flash('error', 'Bitte zuerst Chats markieren.');
            $this->selectMode = false;
            return;
        }

        $handledIds = app(\Platform\Recruiting\Services\Comms\ConversationBulkHandler::class)
            ->markManyHandled($this->teamId(), $ids, Auth::id() !== null ? (int) Auth::id() : null);

        if ($this->selectedThreadId !== null && in_array($this->selectedThreadId, $handledIds, true)) {
            $this->selectedThreadId = null;
        }

        $nichtGestempelt = count($ids) - count($handledIds);
        if ($handledIds === []) {
            session()->flash('error', 'Keiner der markierten Chats konnte abgehakt werden.' . $this->nichtGestempeltHinweis($nichtGestempelt));
        } elseif ($nichtGestempelt > 0) {
            session()->flash('message', trim(count($handledIds) . ' Chat(s) als erledigt markiert.' . $this->nichtGestempeltHinweis($nichtGestempelt)));
        }

        $this->selected = [];
        $this->selectMode = false;
        $this->forgetSnapshot();
        $this->dispatch('sidebar-refresh');
    }

    /**
     * Sammelversand "Wir melden uns" an die markierten Chats. Empfaenger
     * kommen NUR aus $this->rows (bereits teamgefiltert durch snapshot()),
     * nie direkt aus $this->selected — so kann eine praeparierte Thread-ID
     * ohne passende Zeile in der eigenen Liste nichts auslösen.
     *
     * Fix-Runde 1, Befund 1: die Auswahl wird bei jedem Filterwechsel schon
     * ueber discardSelectionOnFilterChange() geleert — trotzdem bleibt hier
     * eine zweite Sperre stehen, falls $recipients aus einem anderen Grund
     * leerlaeuft (z.B. eine Zeile fiel zwischen Markieren und Klick aus
     * $this->rows heraus). Ein Versand an null Empfaenger wird NIE als
     * Erfolg gemeldet.
     *
     * Fix-Runde 2, Befund B1 (Teilfall): auch wenn NICHT alle, aber nur ein
     * Teil der markierten IDs aus $rows herausgefallen ist, wird das jetzt
     * ueber verlorenHinweis() an die Erfolgs-/Fehlermeldung angehaengt —
     * vorher deckten die Sperren nur den Fall ab, dass GAR NICHTS mehr
     * uebrig blieb.
     *
     * Fix-Runde 3, Befund 1: $ids wird dedupliziert, bevor gezaehlt und
     * weitergereicht wird — siehe Begruendung an markSelectedHandled().
     */
    public function sendHoldingToSelected(): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->selected)));
        if ($ids === []) {
            session()->flash('error', 'Bitte zuerst Chats markieren.');
            return;
        }

        $recipients = [];
        foreach ($this->rows as $row) {
            if (in_array($row->threadId, $ids, true)) {
                $recipients[] = ['phone' => $row->phone, 'first_name' => $row->firstName];
            }
        }

        $verloren = count($ids) - count($recipients);

        if ($recipients === []) {
            // Fix-Runde 3, Befund 2: KEIN verlorenHinweis() hier anhaengen —
            // dieser Satz sagt bereits vollstaendig, dass die gesamte
            // Auswahl weg ist ($verloren === count($ids) in diesem Zweig);
            // der Hinweis wuerde "bitte erneut markieren" ein zweites Mal
            // sagen.
            session()->flash('error', 'Die Auswahl passt zu keiner sichtbaren Zeile mehr — bitte erneut markieren.');
            $this->selected = [];
            $this->selectMode = false;
            return;
        }

        $result = app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)
            ->sendToMany($this->teamId(), $recipients);

        if ($result['error'] !== null) {
            session()->flash('error', $result['error']);
        } elseif ($result['sent'] === 0) {
            // sendToMany() liefert bei sent=0 UND error=null zwei Faelle mit
            // demselben aeusseren Signal (gleiche Falle wie in
            // sendHoldingTemplate() weiter unten, Fix-Runde 1 zu Task 8):
            // failed zaehlt echte Fehlschlaege, skipped fehlende Pflichtangaben.
            // "an 0 Kontakt(e) gesendet" darf hier NIE als Erfolg erscheinen.
            session()->flash('error', ($result['failed'] > 0
                ? 'Versand fehlgeschlagen.'
                : 'Versand übersprungen — Nummer oder Pflichtangabe fehlt.') . $this->verlorenHinweis($verloren));
        } else {
            session()->flash('message', '„Wir melden uns" an ' . $result['sent'] . ' Kontakt(e) gesendet.' . $this->verlorenHinweis($verloren));
        }

        $this->selected = [];
        $this->selectMode = false;
        $this->forgetSnapshot();
    }

    public function select(int $threadId): void
    {
        $this->selectedThreadId = $threadId;
        $this->replyText = '';
        $this->sendError = null;
        $this->threadForTeam($threadId)?->markAsRead();
        $this->forgetSnapshot();
        $this->dispatch('sidebar-refresh');
    }

    public function back(): void
    {
        $this->selectedThreadId = null;
        $this->forgetSnapshot();
    }

    public function sendReply(): void
    {
        $this->sendError = null;

        $thread = $this->selectedThread;
        if ($thread === null) {
            $this->sendError = 'Kein Chat ausgewählt.';
            return;
        }

        $result = app(\Platform\Recruiting\Services\Zas\Dispo\DispoReplySender::class)
            ->send($thread, $this->replyText, Auth::user());

        if (!$result['ok']) {
            // Der geteilte Sender formuliert den Fenster-Fehler fuer die Dispo
            // ("... ueber die Veranstaltung"). Hier gilt eine andere Regel,
            // deshalb wird genau dieser Fall umformuliert.
            $this->sendError = str_contains((string) $result['error'], '24h-Fenster')
                ? 'Das 24-Stunden-Fenster ist zu — bitte eine Vorlage senden.'
                : $result['error'];
            return; // replyText bleibt stehen
        }

        $this->replyText = '';
        $this->forgetSnapshot();
        $this->dispatch('reply-sent');
    }

    /** Vorlagen-Versand bei geschlossenem 24h-Fenster (Knopfleiste in chatTemplates()). */
    public function sendTemplate(int $templateId): void
    {
        $this->sendError = null;

        $thread = $this->selectedThread;
        $row = $this->selectedRow;
        if ($thread === null || $row === null) {
            $this->sendError = 'Kein Chat ausgewählt.';
            return;
        }

        $result = app(\Platform\Recruiting\Services\Comms\ApplicantTemplateSender::class)->send(
            $thread,
            $templateId,
            $row->subjectType === 'applicant' ? $row->subjectId : null,
            Auth::user(),
        );

        if (!$result['ok']) {
            $this->sendError = $result['error'];
            return;
        }

        // forgetSnapshot() statt resetPage(): eine per "mehr laden" erweiterte
        // Liste soll nach dem Versand nicht wieder auf 50 einschnappen.
        $this->forgetSnapshot();
    }

    /** „Wir melden uns" — laeuft ueber HoldingTemplateSender, der seine Anrede selbst setzt. */
    public function sendHoldingTemplate(): void
    {
        $this->sendError = null;

        $row = $this->selectedRow;
        if ($row === null) {
            $this->sendError = 'Kein Chat ausgewählt.';
            return;
        }

        $result = app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)->sendToMany(
            $this->teamId(),
            [['phone' => $row->phone, 'first_name' => $row->firstName]],
        );

        if ($result['error'] !== null) {
            $this->sendError = $result['error'];
            return;
        }

        if ($result['sent'] === 0) {
            // sendToMany() liefert bei sent=0 UND error=null zwei Faelle mit
            // demselben aeusseren Signal (Fix-Runde 1, Befund 4): failed
            // zaehlt den echten Fehlschlag beim Versand, skipped den Vorab-
            // Abbruch (z.B. fehlende Nummer). Nur EIN Empfaenger geht hier
            // rein, also schliessen sich beide Zaehler gegenseitig aus.
            $this->sendError = $result['failed'] > 0
                ? 'Versand fehlgeschlagen.'
                : 'Versand übersprungen — Nummer oder Pflichtangabe fehlt.';
            return;
        }

        $this->forgetSnapshot();
    }

    public function openLinkPanel(int $threadId): void
    {
        $this->linkingThreadId = $threadId;
        $this->linkSearch = '';
    }

    public function closeLinkPanel(): void
    {
        $this->linkingThreadId = null;
        $this->linkSearch = '';
    }

    /**
     * Kandidaten fuers Zuordnen-Panel — auf das aktuelle Team eingeschraenkt
     * ueber team_id, damit hier keine fremde Bewerber-ID auftauchen kann.
     *
     * @return list<array{id: int, label: string}>
     */
    #[Computed]
    public function linkCandidates(): array
    {
        $needle = trim($this->linkSearch);
        if (mb_strlen($needle) < 2) {
            return [];
        }

        return \Platform\Recruiting\Models\RecApplicant::query()
            ->with(['crmContactLinks.contact'])
            ->where('team_id', $this->teamId())
            ->get()
            ->map(function ($applicant) {
                $contact = $applicant->crmContactLinks->first()?->contact;

                return [
                    'id' => (int) $applicant->id,
                    'label' => trim(($contact?->full_name ?: 'Bewerber') . ' #' . $applicant->id),
                ];
            })
            ->filter(fn ($row) => str_contains(mb_strtolower($row['label']), mb_strtolower($needle)))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * EIN Mechanismus fuers Verknuepfen — ApplicantThreadLinker befoerdert den
     * Bewerber auch dann, wenn der Thread noch am blossen CrmContact haengt.
     * Die Logik hier zu wiederholen ist exakt die Bugklasse aus Fall 2474.
     * threadForTeam() sorgt dafuer, dass nie eine fremde Thread-ID zugeordnet
     * werden kann.
     *
     * Fix-Runde 1, Befund 1 (CRITICAL): $applicantId kommt vom Client —
     * Livewire-Methoden sind mit beliebigen Parametern aufrufbar, nicht nur
     * mit dem, was im Panel gerendert wurde. RecApplicant hat keinen
     * automatischen Team-Scope und addContext() im CRM prueft die ID
     * ueberhaupt nicht — ohne diese Pruefung liesse sich ein eigener Chat an
     * einen Bewerber eines FREMDEN Teams haengen. Deshalb wird der Bewerber
     * hier genauso team-gebunden geladen wie der Thread ueber
     * threadForTeam(): ueber scopeForTeam().
     *
     * Fix-Runde 1, Befund 2+3 (CRITICAL/IMPORTANT): der aktuelle Zustand des
     * Threads kommt aus derselben Quelle wie die Anzeige (InboxQuery::
     * rowForThread()) — kein zweites, abweichendes Regelwerk. Ein Thread mit
     * echtem Fremd-Kontext (contextLabel gesetzt, z.B. hcm_onboarding) oder
     * einer bereits bestehenden Zuordnung (subjectType != 'unassigned', ob
     * Bewerber oder Mitarbeiter) wird hier NICHT verknuepft — auch wenn diese
     * Methode direkt aufgerufen wird, ohne dass der Knopf je sichtbar war.
     * Ohne diese Sperre wuerde addContext() bei bereits verknuepften Threads
     * lediglich eine zweite Pivot-Zeile anlegen (die Legacy-Spalten bleiben
     * wegen "first context wins" unveraendert) — die Oberflaeche meldete
     * trotzdem Erfolg, obwohl sich sichtbar nichts geaendert hat.
     */
    public function linkToApplicant(int $applicantId): void
    {
        $thread = $this->linkingThreadId ? $this->threadForTeam($this->linkingThreadId) : null;
        if ($thread === null) {
            $this->closeLinkPanel();
            return;
        }

        $applicant = \Platform\Recruiting\Models\RecApplicant::query()
            ->forTeam($this->teamId())
            ->whereKey($applicantId)
            ->first();
        if ($applicant === null) {
            $this->closeLinkPanel();
            session()->flash('error', 'Zuordnung nicht möglich — Bewerber wurde nicht gefunden.');
            return;
        }

        $row = app(InboxQuery::class)->rowForThread($thread, $this->teamId());
        if ($row !== null && $row->contextLabel !== null) {
            $this->closeLinkPanel();
            session()->flash('error', 'Dieser Chat gehört zu einem anderen Fachprozess und kann hier nicht zugeordnet werden.');
            return;
        }
        if ($row === null || $row->subjectType !== 'unassigned') {
            $this->closeLinkPanel();
            session()->flash('error', 'Dieser Chat ist bereits zugeordnet.');
            return;
        }

        \Platform\Recruiting\Services\Comms\ApplicantThreadLinker::link($thread, $applicantId, 'inbox_manual');

        $this->closeLinkPanel();
        $this->forgetSnapshot();
        session()->flash('message', 'Chat dem Bewerber zugeordnet.');
    }

    private function oooSettings(): \Platform\Recruiting\Models\RecApplicantSettings
    {
        return \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam($this->teamId());
    }

    /** Heutiges Datum in der Team-Timezone — einzige "heute"-Quelle der Komponente. */
    private function teamToday(): string
    {
        return \Platform\Recruiting\Services\Comms\TeamClock::today($this->oooSettings()->getSetting('comms_timezone'));
    }

    /** off | pending | active — alleinige Quelle: OooMode (nie das rohe Flag). */
    #[Computed]
    public function oooState(): string
    {
        $s = $this->oooSettings();

        return \Platform\Recruiting\Services\Comms\OooMode::state(
            (bool) $s->getSetting('comms_ooo_enabled', false),
            $s->getSetting('comms_ooo_from'),
            $s->getSetting('comms_ooo_back_at'),
            $this->teamToday(),
        );
    }

    /** Anzeige-Daten fuer Banner (d.m.Y) + Template-Konfig-Status. */
    #[Computed]
    public function oooView(): array
    {
        $s = $this->oooSettings();
        $fmt = static fn (?string $ymd): ?string => $ymd ? \Carbon\Carbon::parse($ymd)->format('d.m.Y') : null;

        return [
            'from' => $fmt($s->getSetting('comms_ooo_from')),
            'back_at' => $fmt($s->getSetting('comms_ooo_back_at')),
            'template_configured' => app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)
                ->configuredTemplateName($this->teamId(), \Platform\Recruiting\Services\Comms\OooAutoReplyHandler::SETTINGS_KEY) !== null,
        ];
    }

    public function openOooForm(): void
    {
        if (!$this->oooView['template_configured']) {
            session()->flash('error', 'Kein Abwesenheits-Template konfiguriert (Einstellungen → Kommunikation).');
            return;
        }
        $this->oooForm = ['from' => $this->teamToday(), 'until' => '', 'back_at' => ''];
        $this->showOooForm = true;
    }

    public function activateOoo(): void
    {
        if (!$this->oooView['template_configured']) {
            session()->flash('error', 'Kein Abwesenheits-Template konfiguriert (Einstellungen → Kommunikation).');
            return;
        }

        $from = $this->oooForm['from'];
        $until = $this->oooForm['until'];
        $backAt = $this->oooForm['back_at'];

        if ($from === '' || $until === '' || $backAt === '') {
            session()->flash('error', 'Bitte alle drei Daten angeben.');
            return;
        }
        // Y-m-d: String-Vergleich == chronologischer Vergleich
        if (!($from <= $until && $until < $backAt)) {
            session()->flash('error', 'Es muss gelten: von ≤ bis < wieder da.');
            return;
        }
        if ($backAt <= $this->teamToday()) {
            session()->flash('error', 'Das Wieder-da-Datum muss in der Zukunft liegen.');
            return;
        }

        $s = $this->oooSettings();
        $s->setSetting('comms_ooo_from', $from);
        $s->setSetting('comms_ooo_until', $until);
        $s->setSetting('comms_ooo_back_at', $backAt);
        $s->setSetting('comms_ooo_enabled', true);
        $s->save();

        $this->showOooForm = false;
        unset($this->oooState, $this->oooView);
        session()->flash('message', 'Abwesenheitsmodus gespeichert.');
    }

    public function deactivateOoo(): void
    {
        $s = $this->oooSettings();
        $s->setSetting('comms_ooo_enabled', false);
        $s->save();
        unset($this->oooState, $this->oooView);
        session()->flash('message', 'Abwesenheitsmodus deaktiviert.');
    }

    public function render()
    {
        return view('recruiting::livewire.conversations.inbox')
            ->layout('platform::layouts.app');
    }
}
