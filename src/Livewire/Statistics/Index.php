<?php

namespace Platform\Recruiting\Livewire\Statistics;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\Statistics\CohortAssigner;
use Platform\Recruiting\Services\Statistics\CohortViewModel;
use Platform\Recruiting\Services\Statistics\TargetLight;

/**
 * Statistik-Seite (Spec §3/§4): duenne Livewire-Schale um den CohortAssigner.
 * Die Praezedenz-Kette und die Zuordnungsregel leben NICHT hier, sondern im
 * Assigner — diese Klasse laedt, mappt, delegiert und loest Drill-down-IDs auf.
 *
 * V1 nur per Direkt-URL erreichbar (kein Sidebar-Eintrag, Spec §1 Rollout).
 */
class Index extends Component
{
    public ?string $filterFrom = null;
    public ?string $filterTo = null;

    // P6: geleerte x-ui-input-date liefern '' — auf null normalisieren,
    // damit SQL-when() und Assigner dieselbe Menge sehen
    public function updatedFilterFrom($value): void
    {
        $this->filterFrom = $value ?: null;
        $this->resetDrill();
    }

    public function updatedFilterTo($value): void
    {
        $this->filterTo = $value ?: null;
        $this->resetDrill();
    }

    public ?string $ortFilter = null;
    public ?string $activityFilter = null;

    // Absichtlich UNTYPED (Abweichung vom Brief-Entwurf, der ?int vorsah):
    // ein geleertes <select> sendet '', und Livewire wuerde '' in eine
    // typisierte ?int-Property nicht sauber hydrieren (TypeError bzw. 0).
    // Konvention im Modul ist ebenfalls untyped (vgl. Applicant\Index::$posting_id).
    // Die updated-Hooks unten stellen int|null wieder her.
    public $postingFilter = null;
    public $sourcePlatformFilter = null;

    // P6 gilt auch fuer die Text-Selects: '' ist ein ECHTER Wert, der auf keine
    // Gruppe passt — ohne Normalisierung waere die Tabelle nach dem
    // Zurueckstellen eines Filters komplett leer.
    public function updatedOrtFilter($value): void
    {
        $this->ortFilter = ($value === '' || $value === null) ? null : (string) $value;
        $this->resetDrill();
    }

    public function updatedActivityFilter($value): void
    {
        $this->activityFilter = ($value === '' || $value === null) ? null : (string) $value;
        $this->resetDrill();
    }

    public function updatedPostingFilter($value): void
    {
        $this->postingFilter = ($value === '' || $value === null) ? null : (int) $value;
        $this->resetDrill();
    }

    public function updatedSourcePlatformFilter($value): void
    {
        $this->sourcePlatformFilter = ($value === '' || $value === null) ? null : (int) $value;
        $this->resetDrill();
    }

    /** @var list<int> IDs fuer das Drill-down-Modal */
    public array $drillIds = [];
    public string $drillLabel = '';
    public bool $showDrill = false;

    /**
     * Ein Filterwechsel invalidiert die Drill-Auswahl — sonst zeigt das Modal
     * Personen, die in der neuen Menge gar nicht mehr vorkommen.
     */
    private function resetDrill(): void
    {
        $this->drillIds = [];
        $this->drillLabel = '';
        $this->showDrill = false;
    }

    public function resetFilters(): void
    {
        $this->filterFrom = null;
        $this->filterTo = null;
        $this->ortFilter = null;
        $this->activityFilter = null;
        $this->postingFilter = null;
        $this->sourcePlatformFilter = null;
        $this->resetDrill();
    }

    private function teamId(): int
    {
        return (int) auth()->user()->currentTeam->id;
    }

    #[Computed]
    public function cohort(): array
    {
        $teamId = $this->teamId();

        // Einmal casten, dann ueberall dieselbe Variable: die Property ist
        // untypisiert (Livewire-Hydrierung von ''), und SQL vergleicht lose,
        // der Pivot-Filter unten aber strikt. Mit einem gecrafteten Snapshot
        // ("5" statt 5) waere die Bewerber-Menge gefiltert, die Pivot-Liste
        // aber leer geblieben — jede Zeile waere in "ohne Ausschreibung" gelandet.
        $postingId = $this->postingFilter !== null ? (int) $this->postingFilter : null;

        // P2: Vorfilter spiegeln die PHP-Logik verlustfrei (is_test = Stufe 1,
        // Zeitraum mit NULL-Ausnahme = Stufe 2, Posting-/Quellen-Filter =
        // Mengeneinschraenkung P3) — Rekonziliation unveraendert, aber die
        // Query laedt nie das ganze Team (Query-Budget ist Abnahmekriterium §2).
        // rec_applicants.applied_at ist eine echte DATE-Spalte (Migration
        // 2026_02_09_000005) — '<=' vergleicht also exakt wie der Assigner auf
        // toDateString(), kein Tages-Abschnitt.
        // Falls Q10 grosse Zahlen zeigt: chunkById(500) + assign() pro Chunk
        // fuettern — der Assigner akkumuliert zeilenweise, ist also streamfaehig.
        $applicants = RecApplicant::forTeam($teamId)
            ->where('is_test', false)
            ->when($this->filterFrom || $this->filterTo, fn ($q) => $q->where(fn ($q2) => $q2
                ->whereNull('applied_at')
                ->orWhere(fn ($q3) => $q3
                    ->when($this->filterFrom, fn ($q4) => $q4->where('applied_at', '>=', $this->filterFrom))
                    ->when($this->filterTo, fn ($q4) => $q4->where('applied_at', '<=', $this->filterTo)))))
            // P3: Ausschreibungs-Filter schraenkt die BEWERBER-Menge ein (Spec §4),
            // nicht nur die Pivot-Liste — sonst fuellt sich "ohne Ausschreibung"
            // mit dem gesamten Rest des Teams.
            ->when($postingId, fn ($q) => $q->whereHas('postings',
                fn ($p) => $p->where('rec_postings.id', $postingId)))
            ->when($this->sourcePlatformFilter, fn ($q) => $q->where('source_platform_id', $this->sourcePlatformFilter))
            // OPTIONAL, erst wenn Q10 grosse Zahlen zeigt: Superset-Vorfilter Ort.
            // Schliesst nie eine Zeile aus, die sonst ueberlebt haette — eine Zeile
            // mit konkreter Gruppe "Essen" setzt eine Pivot-Zeile mit
            // position.location = 'Essen' voraus; die Fallback-Werte "ohne Ort"/
            // "ohne Ausschreibung" sind nie gleich einer konkreten Auswahl.
            // ->when($this->ortFilter, fn ($q) => $q->whereHas('postings.position',
            //     fn ($p) => $p->where('location', $this->ortFilter)))
            ->with([
                'postings.position',
                // kein withTrashed(): der Assigner verwirft deleted ohnehin —
                // SoftDeleted gar nicht erst laden
                'interviewBookings' => fn ($q) => $q->with('interview:id,starts_at,location'),
                // P4 verifiziert: rec_contracts.status ist string(30) NOT NULL
                // default 'pending' (Migration 2026_04_15_100000) → '!=' ist
                // NULL-safe. Dashboard zaehlt heute ungefiltert (bumpStatRow:421);
                // der cancelled-Ausschluss hier ist Spec §4 (heute wirkungslos,
                // aber zukunftssicher).
                'contracts' => fn ($q) => $q->where('status', '!=', 'cancelled'),
                'phase:id,name,order,rec_position_id',
            ])
            ->get();

        // Zwei Lookups VOR der Schleife, beide aggregiert ueber die ganze Menge:
        // ZWEI zusaetzliche Queries pro Seitenaufruf (eine fuer das
        // Transition-Log, eine fuer die Ausschreibungs-Stammdaten), nicht eine
        // pro Datensatz. Die Ausschreibungs-Tabelle kostet damit insgesamt vier
        // Queries mehr als vorher — die beiden hier plus zwei in phaseLabels()
        // (Stellen des Orts, dann deren Phasen). Query-Budget ist
        // Abnahmekriterium §2, deshalb steht die Zahl hier und nicht "ein paar".
        $maxLoggedPhaseOrder = $this->maxLoggedPhaseOrder($teamId, $applicants->pluck('id')->all());
        $postingTargets = $this->postingTargets(
            $teamId,
            $applicants->flatMap(fn ($a) => $a->postings->pluck('id'))->unique()->values()->all(),
        );

        $rows = [];
        $bookings = [];
        $pivots = [];
        foreach ($applicants as $a) {
            $signed = $a->contracts->whereNotNull('signed_at')->sortBy('signed_at')->first();

            // Trichter-Tiefe = MAXIMUM aus aktueller Phase und Transition-Log.
            // Beide Quellen sind fuer sich unvollstaendig, und zwar auf
            // gegenlaeufige Weise:
            //  - das LOG ueberlebt Umbenennungen, verliert aber die `order`,
            //    wenn die Phase geloescht wurde (to_phase_id ist dann null oder
            //    der Join greift nicht) — dort ist es blind;
            //  - die AKTUELLE Phase kennt nur den Jetzt-Zustand: wer
            //    zurueckgesetzt wurde, hatte die tiefere Phase trotzdem erreicht.
            // Das Maximum nimmt von beiden das Beste. Lueckenlos-kumulativ macht
            // daraus der CohortAssigner (wer Phase 4 erreicht hat, hat 1–3
            // durchlaufen), hier wird nur die Tiefe bestimmt.
            $phaseOrder = $a->phase?->order;
            $loggedOrder = $maxLoggedPhaseOrder[$a->id] ?? null;

            $rows[] = [
                'id' => $a->id,
                'is_test' => (bool) $a->is_test,
                'applied_at' => $a->applied_at?->toDateString(),
                'duplicate' => $a->duplicate_of_applicant_id !== null,
                'unrouted' => (bool) $a->is_unrouted,
                'import' => $a->import_source !== null,
                'parked' => (bool) $a->is_parked,
                'rejected' => $a->rejected_at !== null,
                'hr_desk' => (bool) $a->is_on_hr_desk,
                'phase_position_id' => $a->phase?->rec_position_id,
                'phase_name' => $a->phase?->name,
                'phase_order' => $phaseOrder,
                // null nur, wenn KEINE der beiden Quellen etwas weiss — dann
                // bleibt die Trichter-Spalte fuer diese Bewerbung leer, statt
                // eine Phase 0 zu erfinden.
                'phase_order_reached' => ($phaseOrder === null && $loggedOrder === null)
                    ? null
                    : max((int) $phaseOrder, (int) $loggedOrder),
                'enrichment_status' => $a->enrichment_status,
                'contract_sent' => $a->contracts->whereNotNull('sent_at')->isNotEmpty(),
                'contract_signed' => $signed !== null,
                'applied_to_signed_days' => ($signed && $a->applied_at)
                    ? max(0, $a->applied_at->startOfDay()->diffInDays($signed->signed_at->startOfDay()))
                    : null,
            ];
            $bookings[$a->id] = $a->interviewBookings->map(fn ($b) => [
                'booking_id' => $b->id,
                'interview_id' => $b->rec_interview_id,
                'status' => $b->status,
                'seat_released' => $b->seat_released_at !== null,
                'starts_at' => $b->interview?->starts_at?->toDateTimeString(),
                // heute identisch mit false (kein withTrashed) — aber
                // selbstkorrigierend, falls die Relation je geaendert wird
                'deleted' => $b->deleted_at !== null,
            ])->all();
            $pivots[$a->id] = $a->postings
                ->filter(fn ($p) => $postingId === null || (int) $p->id === $postingId)
                ->map(fn ($p) => [
                    'posting_id' => $p->id,
                    'position_id' => $p->rec_position_id,
                    'location' => $p->position?->location,
                    'activity' => $p->activity,
                    'posting_title' => (string) $p->title,
                    // „geschlossen" ist das EXAKTE Gegenteil von „online", und
                    // online heisst status=published UND is_active. Alles andere
                    // (draft, archiviert, deaktiviert) ist geschlossen.
                    //
                    // closes_at in der Vergangenheit gehoert absichtlich NICHT
                    // dazu: eine abgelaufene, aber noch veroeffentlichte
                    // Ausschreibung ist online erreichbar, und der Filter
                    // postingStatusFilter (Task 10) baut auf derselben
                    // Definition. Zwei auseinanderdriftende Begriffe von
                    // „geschlossen" waeren genau der Widerspruch, den diese
                    // Seite abschaffen soll.
                    'posting_closed' => !($p->status === 'published' && (bool) $p->is_active),
                ])->all();
        }

        $result = (new CohortAssigner())->assign($rows, $bookings, $pivots, $this->filterFrom, $this->filterTo);

        // Ziel-Werte an die Zeilen haengen. Der Assigner ist eine pure Klasse
        // ohne DB und kennt keine Ausschreibungs-Stammdaten — Bedarf, Faktor und
        // Laufzeit sind deshalb Beigabe des Aufrufers.
        //
        // Kein Default, kein Raten: fehlt der Eintrag (oder haengt die Zeile an
        // keiner Ausschreibung), bleibt jedes Feld null. „Leer heisst nicht
        // gepflegt" ist die tragende Regel dieses Features — eine nicht
        // gepflegte Ausschreibung zeigt eine graue Ampel, nie eine erfundene.
        $result['rows'] = array_map(function (array $row) use ($postingTargets) {
            $target = $row['posting_id'] !== null
                ? ($postingTargets[$row['posting_id']] ?? null)
                : null;

            return $row + [
                'bedarf' => $target['bedarf'] ?? null,
                'bewerbungs_faktor' => $target['bewerbungs_faktor'] ?? null,
                'published_ymd' => $target['published_ymd'] ?? null,
                'closes_ymd' => $target['closes_ymd'] ?? null,
            ];
        }, $result['rows']);

        // Ort-/Taetigkeits-Filter wirken auf die GRUPPE (nach dem Assign, damit
        // die Rekonziliation innerhalb der Auswahl geschlossen bleibt)
        if ($this->ortFilter !== null || $this->activityFilter !== null) {
            $result['rows'] = array_values(array_filter($result['rows'], fn ($r) =>
                ($this->ortFilter === null || $r['group']['ort'] === $this->ortFilter)
                && ($this->activityFilter === null || $r['group']['taetigkeit'] === $this->activityFilter)));
            $result['total_ids'] = array_merge(...array_map(fn ($r) => $r['ids'], $result['rows']) ?: [[]]);
        }

        return $result;
    }

    /**
     * Hoechste im Transition-Log erreichte Phasen-`order` je Bewerber —
     * `rec_applicant_id => MAX(rec_phases.order)`.
     *
     * EINE aggregierte Query fuer die ganze Menge, nicht eine pro Bewerber: die
     * Statistik-Seite laedt bei weit gestelltem Zeitraum das halbe Team, und ein
     * N+1 an dieser Stelle waere im Betrieb nicht mehr einzufangen.
     *
     * Gezaehlt werden NUR Log-Eintraege zur AKTUELLEN STELLE des Bewerbers
     * (`rec_phase_transitions.rec_position_id` gegen die Stelle seiner aktuellen
     * Phase). Grund: Phasen sind pro Stelle geklont, und eine `order` aus dem
     * Phasensatz einer ANDEREN Stelle ist im Trichter dieser Ausschreibung keine
     * erreichte Stufe. Ohne die Einschraenkung wuerde die Trichter-Tiefe nach
     * einem Stellenwechsel zu gross — und Stellenwechsel sind kein Randfall
     * (gemessen: 15, elf davon in sechs Tagen).
     *
     * Zwei Wege fallen dadurch bewusst heraus, beide konservativ (keine erfundene
     * Tiefe, die aktuelle Phase deckt den Live-Zustand ohnehin ab):
     *  - Log-Eintraege ohne `rec_position_id` (Spalte ist nullable);
     *  - Bewerber ohne aktuelle Phase — sie haben keine Stelle, gegen die man
     *    vergleichen koennte.
     *
     * Der INNER JOIN auf die ZIELPHASE ist ebenfalls Absicht: gehoert sie nicht
     * mehr zu einem Phasensatz (Phase geloescht → to_phase_id per nullOnDelete
     * null), gibt es keine `order` und der Eintrag traegt nichts bei.
     *
     * `order` ist in MySQL ein reserviertes Wort und muss im MAX() gequotet
     * werden; die Quotierung kommt aus der Grammatik der Verbindung, damit sie
     * nicht auf einen Dialekt festgenagelt ist.
     *
     * @param  list<int>  $applicantIds
     * @return array<int,int>
     */
    private function maxLoggedPhaseOrder(int $teamId, array $applicantIds): array
    {
        if ($applicantIds === []) {
            return [];
        }

        $orderColumn = DB::getQueryGrammar()->wrap('to_phase.order');

        return RecPhaseTransition::query()
            ->where('rec_phase_transitions.team_id', $teamId)
            ->whereIn('rec_phase_transitions.rec_applicant_id', $applicantIds)
            ->join('rec_phases as to_phase', 'to_phase.id', '=', 'rec_phase_transitions.to_phase_id')
            // Stelle des Bewerbers = Stelle seiner aktuellen Phase
            ->join('rec_applicants', 'rec_applicants.id', '=', 'rec_phase_transitions.rec_applicant_id')
            ->join('rec_phases as current_phase', 'current_phase.id', '=', 'rec_applicants.rec_phase_id')
            ->whereColumn('rec_phase_transitions.rec_position_id', 'current_phase.rec_position_id')
            ->groupBy('rec_phase_transitions.rec_applicant_id')
            ->select('rec_phase_transitions.rec_applicant_id')
            ->selectRaw('MAX(' . $orderColumn . ') as max_order')
            // toBase(): reine Zahlen, keine Model-Hydrierung fuer zwei Spalten
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->rec_applicant_id => (int) $r->max_order])
            ->all();
    }

    /**
     * Ziel-Stammdaten der Ausschreibungen: `posting_id => [bedarf, faktor,
     * published_ymd, closes_ymd]`.
     *
     * Bewusst eine EIGENE Query, obwohl `postings` oben schon eager geladen ist:
     * so haengt die Ampel nicht daran, welche Spalten der Eager-Load gerade
     * mitnimmt. Wuerde dort je eine Spaltenliste stehen, waere `bedarf` still
     * null — und still null heisst hier „nicht gepflegt", also graue Ampel ohne
     * Fehlermeldung. Genau diese Sorte stiller Fehlanzeige soll die Seite nicht
     * haben.
     *
     * Datumsformat: TargetLight rechnet auf Y-m-d-STRINGS (pure Klasse, kein
     * Carbon) — die Umwandlung passiert hier, an der Systemgrenze.
     *
     * forTeam ist Pflicht, nicht Redundanz: die IDs stammen aus geladenen
     * Bewerbern, aber der Scope ist die Zusicherung, dass diese Seite nie
     * Fremd-Team-Daten liest.
     *
     * @param  list<int>  $postingIds
     * @return array<int, array{bedarf:?int, bewerbungs_faktor:?float, published_ymd:?string, closes_ymd:?string}>
     */
    private function postingTargets(int $teamId, array $postingIds): array
    {
        if ($postingIds === []) {
            return [];
        }

        return RecPosting::forTeam($teamId)
            ->whereIn('id', $postingIds)
            ->get(['id', 'bedarf', 'bewerbungs_faktor', 'published_at', 'closes_at'])
            ->mapWithKeys(fn ($p) => [(int) $p->id => [
                'bedarf' => $p->bedarf,
                'bewerbungs_faktor' => $p->bewerbungs_faktor,
                'published_ymd' => $p->published_at?->toDateString(),
                'closes_ymd' => $p->closes_at?->toDateString(),
            ]])->all();
    }

    /**
     * Anzeige-Baum fuer die Tabelle: Ort → Taetigkeit → Zeilen, Zeilen in der
     * Reihenfolge der Praezedenz-Kette. Die Gruppierung ist reine Darstellung —
     * addiert wird ausschliesslich ueber die Zeilen des Assigners, damit
     * Gruppen-Summe und Gesamt-Summe per Konstruktion die Rekonziliation
     * erfuellen (Spec §4). Logik in CohortViewModel, weil sie dort ohne
     * Laravel/DB testbar ist (Modul-Konvention).
     *
     * @return array<string, array{ort:string, activities:array<string, list<array>>}>
     */
    #[Computed]
    public function groups(): array
    {
        $startsAt = [];
        foreach ($this->interviewMeta as $interviewId => $meta) {
            $startsAt[$interviewId] = (string) ($meta['starts_at'] ?? '');
        }

        return $this->viewModel()->groups($this->cohort['rows'], $startsAt);
    }

    private function viewModel(): CohortViewModel
    {
        return new CohortViewModel();
    }

    /**
     * Eine Zeile je Ausschreibung (Tabelle 1) — inklusive der Ziel-Stammdaten,
     * die cohort() an die Zeilen gehaengt hat. Gruppierung und Sortierung liegen
     * im CohortViewModel, weil sie dort ohne Laravel/DB testbar sind.
     *
     * @return list<array>
     */
    #[Computed]
    public function postingGroups(): array
    {
        return $this->viewModel()->postingGroups($this->cohort['rows']);
    }

    /**
     * Phasen sind pro Stelle geklont und frei benannt, deshalb aus der Auswahl
     * lesen statt fest verdrahten; bei mehreren Stellen am Ort gewinnt der letzte
     * Name je `order`, was unkritisch ist, weil geklonte Phasen gleich heissen.
     *
     * OHNE Ort-Filter ist die Liste NICHT leer, und das ist eine Falle: Laravel
     * macht aus `where('location', null)` ein `whereNull`, die Kopfzeilen kommen
     * dann aus dem Phasensatz ORTLOSER Stellen, waehrend die Zahlen darunter alle
     * Orte enthalten. Kosmetisch schief, aber nicht falsch gezaehlt (die Spalten
     * zaehlen ueber die `order`). Task 10 macht den Ort zur Pflichtauswahl und
     * beseitigt den Fall — bis dahin bewusst KEIN Fallback, der waere danach
     * toter Code.
     *
     * Kostet zwei Queries (Stellen des Orts, dann deren Phasen).
     *
     * @return array<int,string> order => Name, aus dem Phasensatz der gefilterten Filiale
     */
    #[Computed]
    public function phaseLabels(): array
    {
        $positionIds = RecPosition::forTeam($this->teamId())
            ->where('location', $this->ortFilter)
            ->pluck('id');

        return RecPhase::forTeam($this->teamId())
            ->whereIn('rec_position_id', $positionIds)
            ->where('is_active', true)
            ->orderBy('order')
            ->pluck('name', 'order')
            ->all();
    }

    /**
     * Spaltenschluessel einer Phasen-Spalte ("phase_reached:3"). Die Spalte
     * `phase_reached` ist verschachtelt und darf NIE flach gelesen werden —
     * count() darauf zaehlt Phasen statt Bewerbungen. Der Schluessel kommt
     * deshalb aus dem ViewModel, nicht aus der View.
     */
    public function phaseColumnKey(int $order): string
    {
        return CohortViewModel::phaseColumnKey($order);
    }

    /**
     * Prozent einer Summen-Zeile aus absoluten Summen — nicht der Mittelwert der
     * Zeilen-Prozente (1/1 und 1/99 sind 2 %, nicht 50 %).
     *
     * @param  list<array>  $rows
     */
    public function sumPercent(array $rows, string $numeratorColumn = 'unterschrieben', string $bedarfKey = 'bedarf'): ?int
    {
        return $this->viewModel()->sumPercent($rows, $numeratorColumn, $bedarfKey);
    }

    /**
     * Summe der gepflegten Bedarfe (null = keiner gepflegt). Dieselbe Auswahl wie
     * der Nenner von sumPercent().
     *
     * @param  list<array>  $rows
     */
    public function sumBedarf(array $rows): ?int
    {
        return $this->viewModel()->sumBedarf($rows);
    }

    /**
     * Erfuellungs-Ampel einer Ausschreibungs-Zeile: Unterschriften gegen Bedarf,
     * ABSOLUT (keine Hochrechnung — Unterschriften kommen schubweise nach jeder
     * Schulung, ein linearer Verlauf waere irrefuehrend).
     *
     * Gezaehlt wird ueber countIn() auf den Zeilen der Gruppe, also ueber
     * denselben Weg wie die Zelle daneben und wie das Drill-down.
     *
     * @return array{status:string, pct:?int, reason:string}
     */
    public function fulfilmentLight(array $group): array
    {
        return TargetLight::fulfilment(
            $this->countIn($group['rows'], 'unterschrieben'),
            $group['bedarf'] ?? null,
        );
    }

    /**
     * Pipeline-Ampel einer Ausschreibungs-Zeile: Bewerbungen gegen Bedarf x
     * Faktor, hochgerechnet auf das Laufzeitende. Die Uhr sitzt HIER, die Regel
     * in TargetLight — `today` reist als Y-m-d-String hinein, damit die pure
     * Klasse ohne Uhr testbar bleibt.
     *
     * @return array{status:string, pct:?int, projected:?int, target:?int, reason:string}
     */
    public function pipelineLight(array $group): array
    {
        return TargetLight::pipeline(
            $this->countIn($group['rows'], 'ids'),
            $group['bedarf'] ?? null,
            $group['bewerbungs_faktor'] ?? null,
            $group['published_ymd'] ?? null,
            $group['closes_ymd'] ?? null,
            now()->toDateString(),
        );
    }

    /**
     * Erfuellungs-Ampel der GESAMT-Zeile — mit demselben Ampelpunkt wie jede
     * Zeile darueber, damit die Gesamt-Zeile sich liest wie der Rest der Tabelle.
     *
     * Status und Begruendung kommen aus TargetLight::fulfilment(Σ, Σ), der
     * Prozentwert weiterhin aus sumPercent(). Das sind KEINE zwei Wahrheiten:
     * beide rechnen Σ Zaehler / Σ Nenner und sind arithmetisch identisch (im
     * Review nachgerechnet) — sumPercent() bleibt die benannte Quelle der Zahl,
     * TargetLight die einzige Quelle der Schwellen (60/90 %). Eine eigene
     * Farb-Arithmetik hier waere die zweite Wahrheit.
     *
     * Der `reason` wird ersetzt, weil er in der Gesamt-Zeile die Bezugsgroessen
     * benennen muss: welche absoluten Zahlen den Prozentwert bilden und wie viele
     * Ausschreibungen wegen fehlendem Bedarf NICHT darin stecken. Ohne diese
     * Angabe widerspricht die Quote scheinbar der Spalte „Unterschrieben"
     * daneben, die alle Ausschreibungen zaehlt.
     *
     * @param  list<array>  $groups
     * @return array{status:string, pct:?int, reason:string, signed:int, bedarf:?int, excluded_groups:int, excluded_signed:int}
     */
    public function fulfilmentTotalLight(array $groups): array
    {
        $totals = $this->viewModel()->fulfilmentTotals($groups);
        $light = TargetLight::fulfilment($totals['signed'], $totals['bedarf']);
        $light['pct'] = $totals['pct'];

        $light['reason'] = $totals['bedarf'] === null
            ? 'An keiner Ausschreibung dieser Auswahl ist ein Bedarf gepflegt — keine Quote möglich.'
            : $totals['signed'] . ' von ' . $totals['bedarf'] . ' benötigten Einstellungen unterschrieben'
                . ' (nur Ausschreibungen mit gepflegtem Bedarf).'
                . ($totals['excluded_groups'] > 0
                    ? ' NICHT in dieser Quote: ' . $totals['excluded_groups'] . ' '
                        . ($totals['excluded_groups'] === 1 ? 'Ausschreibung' : 'Ausschreibungen')
                        . ' ohne gepflegten Bedarf mit ' . $totals['excluded_signed'] . ' '
                        . ($totals['excluded_signed'] === 1 ? 'Unterschrift' : 'Unterschriften')
                        . ' — deshalb ist die Spalte „Unterschrieben" höher als der Zähler hier.'
                    : '');

        // Die Absolutzahlen reisen mit, damit die Zelle sie anzeigen kann, ohne
        // sie ein zweites Mal zu berechnen.
        return $light + $totals;
    }

    /**
     * Pipeline-Ampel der GESAMT-Zeile: Σ Bewerbungen gegen Σ (Bedarf x Faktor).
     *
     * Zwei Dinge, die hier absichtlich anders laufen als in einer Zeile:
     *  - KEIN Faktor. Faktoren lassen sich nicht addieren (8,0 und 12,0 sind
     *    nicht 20,0), deshalb reist nur das fertig gerechnete Ziel weiter. Der
     *    Faktor 1,0 unten ist reine Durchleitung: er macht aus dem Ziel dasselbe
     *    Ziel und haelt die Schwellen (60/90 %) in TargetLight — eine eigene
     *    Ampel-Arithmetik hier waere eine zweite Wahrheit.
     *  - KEINE Hochrechnung. Jede Ausschreibung hat ihre eigene Laufzeit; eine
     *    gemeinsame gibt es nicht, also wird absolut verglichen (published/closes
     *    bewusst null → absolute Lesart in TargetLight).
     *
     * Der `reason` wird ersetzt, weil der von TargetLight fuer den Einzelfall
     * formuliert ist („kein Laufzeitende gepflegt") und in der Gesamt-Zeile
     * schlicht falsch waere.
     *
     * @param  list<array>  $groups
     * @return array{status:string, pct:?int, projected:?int, target:?int, reason:string}
     */
    public function pipelineTotalLight(array $groups): array
    {
        $totals = $this->viewModel()->pipelineTotals($groups);
        $light = TargetLight::pipeline(
            $totals['bewerbungen'],
            $totals['target'],
            1.0,
            null,
            null,
            now()->toDateString(),
        );

        $light['reason'] = $totals['target'] === null
            ? 'An keiner Ausschreibung dieser Auswahl sind Bedarf und Faktor gepflegt — keine Aussage möglich.'
            : $totals['bewerbungen'] . ' Bewerbungen gegen ' . $totals['target']
                . ' benötigte (Summe aus Bedarf × Faktor). Ohne Hochrechnung, weil jede '
                . 'Ausschreibung ihre eigene Laufzeit hat. Gezählt werden nur Ausschreibungen '
                . 'mit gepflegtem Bedarf UND Faktor — auf beiden Seiten des Bruchs.';

        return $light;
    }

    /** Interview-ID einer Schulungszeile aus dem Row-Key ("schulung:42"). */
    public function interviewIdOf(array $row): ?int
    {
        return CohortViewModel::interviewIdOf($row);
    }

    #[Computed]
    public function interviewMeta(): array
    {
        $ids = [];
        foreach ($this->cohort['rows'] as $row) {
            if ($row['type'] === 'schulung') {
                $ids[] = (int) substr($row['key'], strlen('schulung:'));
            }
        }
        if ($ids === []) {
            return [];
        }
        // withCount statt takenSeatsCount() pro Termin: die Methode feuert je
        // Termin ein eigenes COUNT (N+1, und das Query-Budget ist ein
        // Abnahmekriterium §2). Der seatTaking-Scope ist derselbe — die zentrale
        // Zaehlregel bleibt die einzige Wahrheit, nur eben in einem Query.
        // forTeam ist Pflicht: die IDs stammen aus den Row-Keys des Assigners und
        // damit indirekt aus einer public Livewire-Property — ohne Scope waere das
        // ein Leck fuer Termindaten fremder Teams. (Einzige Query hier, die den
        // Scope zunaechst nicht hatte.)
        return RecInterview::forTeam($this->teamId())
            ->with('interviewType:id,name')
            ->withCount(['bookings as seat_taking_count' => fn ($q) => $q->seatTaking()])
            ->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($i) => [$i->id => [
                'starts_at' => $i->starts_at,
                'location' => $i->location, // nur Info-Spalte (Spec §3)
                'type' => $i->interviewType?->name ?? 'ohne Terminart',
                'max' => $i->max_participants,
                'seat_taking' => (int) $i->seat_taking_count,
            ]])->all();
    }

    #[Computed]
    public function tiles(): array
    {
        $c = $this->cohort; // Kacheln lesen NUR aus dem Kohorten-Ergebnis (Spec §3)
        // KEIN array_unique: die Zeilen sind per Rekonziliations-Invariante
        // disjunkt — unique wuerde eine Verletzung maskieren statt aufdecken.
        $sum = fn (string $col) => array_sum(array_map(fn ($r) => count($r['columns'][$col]), $c['rows']));
        $total = count($c['total_ids']);
        $signed = $sum('unterschrieben');
        // P5: tth pro Zeile aggregiert → folgt automatisch jedem Zeilen-Filter
        // (Ort/Taetigkeit), Kachel und Tabelle koennen sich nicht widersprechen
        $tth = array_merge(...array_map(fn ($r) => $r['tth_days'], $c['rows']) ?: [[]]);
        sort($tth);
        $n = count($tth);
        // „Ohne Termin" ist die Antwort auf die Kundenfrage „wo hängen die
        // restlichen fest" und stand vorher nur als Nebenzeile in der Tabelle.
        // Summiert ueber die Zeilen des laufenden Typs ohne_schulung — dieselbe
        // Menge, die das Drill-down mit scope=type_all aufloest.
        $ohneTermin = array_sum(array_map(
            fn ($r) => $r['type'] === 'ohne_schulung' ? count($r['ids']) : 0,
            $c['rows'],
        ));

        return [
            'bewerbungen' => $total,
            'gebucht' => $sum('gebucht'),
            'ohne_termin' => $ohneTermin,
            'unterschrieben' => $signed,
            'conversion' => $total > 0 ? (int) round($signed / $total * 100) : 0,
            'tth_median' => $n > 0
                ? ($n % 2 === 0
                    ? (int) round(($tth[$n / 2 - 1] + $tth[$n / 2]) / 2)
                    : $tth[intdiv($n, 2)])
                : null,
        ];
    }

    /**
     * Zellenwert einer Zeilenmenge — dieselbe Aufloesung wie das Drill-down,
     * damit angezeigte Zahl und Modal-Laenge nicht auseinanderlaufen koennen.
     *
     * @param  list<array>  $rows
     */
    public function countIn(array $rows, string $column): int
    {
        return $this->viewModel()->countIn($rows, $column);
    }

    /**
     * Conversion einer Zeilenmenge in Prozent (unterschrieben/Bewerbungen).
     * null = keine Bewerbungen, also keine Quote (nicht 0 %).
     *
     * @param  list<array>  $rows
     */
    public function conversionOf(array $rows): ?int
    {
        return $this->viewModel()->conversionOf($rows);
    }

    /**
     * Right-Censoring (Spec §6): Conversion dieser Zeilenmenge ausgrauen, weil die
     * Kohorte juenger ist als der Median-Durchlauf? Die Uhr sitzt HIER, die Regel im
     * CohortViewModel — `today` reist als Y-m-d-String hinein, damit die pure Klasse
     * ohne Uhr testbar bleibt.
     *
     * Schwelle ist der Median der aktuellen Gesamtsicht, also genau der Wert, den
     * die Kachel zeigt — Kachel und Tabelle koennen sich nicht widersprechen.
     *
     * Ob die Einzelzeilen- oder die Aggregat-Regel gilt, leitet das ViewModel aus
     * der Zeilenmenge ab (isCensoredForRows) — bewusst KEIN Flag von hier, das an
     * einer von vier Aufrufstellen falsch gesetzt werden koennte.
     *
     * @param  list<array>  $rows
     */
    public function isCensored(array $rows): bool
    {
        return $this->viewModel()->isCensoredForRows(
            $rows,
            now()->toDateString(),
            $this->tiles['tth_median'],
        );
    }

    /**
     * Ist „noch offen" fuer diese Zeilenmenge ueberhaupt eine Aussage? Nur laufende
     * Kohorten (Schulung, ohne Schulung) haben einen offenen Ausgang; auf
     * ausgeschlossenen Buckets zeigt die Tabelle „–" statt einer Null.
     *
     * @param  list<array>  $rows
     */
    public function hasRunningRow(array $rows): bool
    {
        return $this->viewModel()->hasRunningRow($rows);
    }

    /**
     * Begruendungstext fuer eine ausgegraute Conversion — EINE Quelle fuer Kachel
     * und Tabellenzelle. Beide zeigen dieselbe Zahl; zwei Textvarianten waeren der
     * erste Schritt zu zwei Regeln.
     */
    public function censorNote(): string
    {
        $median = $this->tiles['tth_median'];

        return $median !== null
            ? 'Kohorte jünger als der Median-Durchlauf (' . $median . ' Tage) — Conversion noch nicht aussagekräftig'
            : 'Kein Median-Durchlauf vorhanden (noch keine Unterschrift) — Conversion noch nicht aussagekräftig';
    }

    /**
     * Baut das erste Argument fuer wire:click="drill(...)" — EIN Token pro
     * Tabellenzeile, das fuer alle Spalten derselben Zeile wiederverwendet wird.
     *
     * Die Spalte reist daneben im Klartext: Spaltenschluessel und -label sind
     * Konstanten aus der View, keine Nutzerdaten.
     *
     * $scope: 'row' (genau eine Zeile) | 'type' (Bucket in einer Gruppe)
     *         | 'ort' (Ort-Summe) | 'posting' (alle Zeilen EINER Ausschreibung,
     *         die Zeilen-Einheit der Ausschreibungs-Tabelle) | 'all' (Gesamt)
     *
     * $extra reist unveraendert durch (encodeScope hier, decodeScope in drill(),
     * von dort direkt in resolveIds) — Hin- und Rueckweg sind also fuer alle
     * Bestandteile derselbe, es gibt keine Feld-Liste, die man vergessen koennte.
     * Fuer die Scopes 'row' und 'posting' ist seit v2 'posting' =>
     * $row['posting_id'] PFLICHT: die Zeilen sind je Ausschreibung, 'key' allein
     * trifft sonst mehrere. Fehlt es, loest resolveIds fail-closed nichts auf
     * (leeres Modal statt vermischter IDs).
     */
    public function drillToken(string $scope, string $prefix, array $extra = []): string
    {
        return $this->viewModel()->encodeScope(array_merge([
            'scope' => $scope,
            'prefix' => $prefix,
        ], $extra));
    }

    /**
     * Drill-down oeffnen. Die Auswahl wird immer gegen die FRISCH berechneten
     * Assigner-Zeilen aufgeloest — das Token benennt nur eine Menge, es liefert
     * keine IDs. Damit kann ein manipuliertes Token nichts sehen, was die
     * aktuelle (team-gescopte) Kohorte nicht ohnehin enthaelt.
     *
     * Diese Methode ist die CLIENT-GRENZE: $token, $column und $columnLabel
     * kommen aus dem Request. Alle drei Bestandteile werden hier deshalb nach
     * derselben Regel behandelt — unbrauchbar heisst LEERE Auswahl, nicht
     * Fehlerseite:
     *  - Token nicht dekodierbar  → Abbruch (unten),
     *  - Scope unbekannt          → resolveIds trifft nichts (fail-closed dort),
     *  - Spalte unbrauchbar       → resolveIdsFromClient liefert [].
     * Innen gilt die andere Regel: ein verschachtelter Spaltenwert ist ein
     * Programmierfehler und wirft in CohortViewModel::flatColumn weiter. Was von
     * draussen kommt, ist Eingabe; was drinnen passiert, ist Code.
     */
    public function drill(string $token, string $column = 'ids', string $columnLabel = ''): void
    {
        $vm = $this->viewModel();
        $spec = $vm->decodeScope($token);
        if ($spec === null) {
            return;
        }

        $this->drillIds = $vm->resolveIdsFromClient($this->cohort['rows'], $spec, $column);

        $prefix = (string) ($spec['prefix'] ?? '');
        $this->drillLabel = $columnLabel === '' ? $prefix : trim($prefix . ' — ' . $columnLabel);
        $this->showDrill = true;
    }

    #[Computed]
    public function drillApplicants()
    {
        if ($this->drillIds === []) {
            return collect();
        }
        // forTeam ist Pflicht, NICHT Redundanz: $drillIds ist eine public
        // Livewire-Property und damit clientseitig manipulierbar. Ohne den
        // Scope liesse sich das Modal als Namens-Leak fuer fremde Teams nutzen.
        return RecApplicant::forTeam($this->teamId())
            ->whereIn('id', $this->drillIds)
            ->with('crmContactLinks.contact')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string,string> */
    #[Computed]
    public function ortOptions(): array
    {
        return RecPosition::forTeam($this->teamId())
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->distinct()
            ->orderBy('location')
            ->pluck('location')
            ->mapWithKeys(fn ($v) => [$v => $v])
            ->all();
    }

    /** @return array<string,string> */
    #[Computed]
    public function activityOptions(): array
    {
        return RecPosting::forTeam($this->teamId())
            ->whereNotNull('activity')
            ->where('activity', '!=', '')
            ->distinct()
            ->orderBy('activity')
            ->pluck('activity')
            ->mapWithKeys(fn ($v) => [$v => $v])
            ->all();
    }

    /**
     * Bewusst ALLE Ausschreibungen, nicht nur aktive (Abweichung vom Brief):
     * eine Statistik-Seite blickt zurueck — mit ->active() waeren die Kohorten
     * geschlossener Ausschreibungen nicht mehr filterbar. Inaktive sind
     * gekennzeichnet, damit die Liste ehrlich bleibt.
     *
     * @return array<int,string>
     */
    #[Computed]
    public function postingOptions(): array
    {
        return RecPosting::forTeam($this->teamId())
            ->orderBy('title')
            ->get(['id', 'title', 'is_active'])
            ->mapWithKeys(fn ($p) => [
                $p->id => $p->title . ($p->is_active ? '' : ' (inaktiv)'),
            ])->all();
    }

    /** @return array<int,string> */
    #[Computed]
    public function sourceOptions(): array
    {
        // RecSourcePlatform hat keinen forTeam-Scope — team_id direkt.
        return RecSourcePlatform::where('team_id', $this->teamId())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function render()
    {
        return view('recruiting::livewire.statistics.index')
            ->layout('platform::layouts.app');
    }
}
