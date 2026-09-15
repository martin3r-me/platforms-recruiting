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

    public function render()
    {
        return view('recruiting::livewire.conversations.inbox')
            ->layout('platform::layouts.app');
    }
}
