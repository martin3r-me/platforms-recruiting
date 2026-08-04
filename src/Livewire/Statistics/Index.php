<?php

namespace Platform\Recruiting\Livewire\Statistics;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\Statistics\CohortAssigner;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

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

        $rows = [];
        $bookings = [];
        $pivots = [];
        foreach ($applicants as $a) {
            $signed = $a->contracts->whereNotNull('signed_at')->sortBy('signed_at')->first();
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
                'phase_order' => $a->phase?->order,
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
                ])->all();
        }

        $result = (new CohortAssigner())->assign($rows, $bookings, $pivots, $this->filterFrom, $this->filterTo);

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
        return [
            'bewerbungen' => $total,
            'gebucht' => $sum('gebucht'),
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
     * @param  list<array>  $rows
     */
    public function isCensored(array $rows): bool
    {
        $vm = $this->viewModel();

        return $vm->isCensored(
            $vm->minAppliedAt($rows),
            now()->toDateString(),
            $this->tiles['tth_median'],
        );
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
     *         | 'ort' (Ort-Summe) | 'all' (Gesamt)
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
     */
    public function drill(string $token, string $column = 'ids', string $columnLabel = ''): void
    {
        $vm = $this->viewModel();
        $spec = $vm->decodeScope($token);
        if ($spec === null) {
            return;
        }

        $this->drillIds = $vm->resolveIds($this->cohort['rows'], $spec, $column);

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
