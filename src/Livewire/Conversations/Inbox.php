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
    public array $oooForm = ['from' => '', 'until' => '', 'back_at' => ''];

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
        $this->resetPage();
    }

    public function toggleHandledView(): void
    {
        $this->showHandled = !$this->showHandled;
        $this->level = 'all';
        $this->resetPage();
    }

    public function loadMore(): void
    {
        $this->perPage += 50;
        $this->forgetSnapshot();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedOwner(): void
    {
        $this->resetPage();
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

    /** Laedt einen Thread NUR im Team-Kontext — nie eine fremde ID. */
    protected function threadForTeam(int $threadId): ?CommsWhatsAppThread
    {
        return CommsWhatsAppThread::query()
            ->whereKey($threadId)
            ->where('team_id', $this->teamId())
            ->first();
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
            ->with(['phase', 'position'])
            ->find($row->subjectId);
        if ($applicant === null) {
            return [];
        }

        $chips = [];
        if ($applicant->phase) {
            $chips[] = ['label' => 'Phase', 'value' => (string) $applicant->phase->name, 'url' => null];
        }
        if ($applicant->position) {
            // Spalte heisst 'title', nicht 'name' (src/Models/RecPosition.php,
            // $fillable) — mit 'name' bliebe der Chip dauerhaft leer, ohne
            // dass es kracht (Eloquent liefert fuer ein unbekanntes Attribut
            // still null).
            $chips[] = ['label' => 'Stelle', 'value' => (string) $applicant->position->title, 'url' => null];
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

    #[Computed]
    public function windowOpen(): bool
    {
        $row = $this->selectedRow;

        return $row !== null && $row->escalation->windowOpen;
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

    /** Stempel wieder loeschen — scharf auf team_id, damit keine fremde Zeile trifft. */
    public function unmarkHandled(int $threadId): void
    {
        \Platform\Recruiting\Models\RecConversationHandled::query()
            ->where('team_id', $this->teamId())
            ->where('comms_whatsapp_thread_id', $threadId)
            ->delete();

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

    /** Sammel-Erledigen: jede ID einzeln ueber markHandled(), also einzeln teamgeprueft. */
    public function markSelectedHandled(): void
    {
        foreach ($this->selected as $threadId) {
            $this->markHandled((int) $threadId);
        }
        $this->selected = [];
        $this->selectMode = false;
    }

    /**
     * Sammelversand "Wir melden uns" an die markierten Chats. Empfaenger
     * kommen NUR aus $this->rows (bereits teamgefiltert durch snapshot()),
     * nie direkt aus $this->selected — so kann eine praeparierte Thread-ID
     * ohne passende Zeile in der eigenen Liste nichts auslösen.
     */
    public function sendHoldingToSelected(): void
    {
        $ids = array_map('intval', $this->selected);
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

        $result = app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)
            ->sendToMany($this->teamId(), $recipients);

        if ($result['error'] !== null) {
            session()->flash('error', $result['error']);
        } else {
            session()->flash('message', '„Wir melden uns" an ' . $result['sent'] . ' Kontakt(e) gesendet.');
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

    public function render()
    {
        return view('recruiting::livewire.conversations.inbox')
            ->layout('platform::layouts.app');
    }
}
