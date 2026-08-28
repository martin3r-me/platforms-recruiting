<?php

namespace Platform\Recruiting\Livewire\Statistics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Jobs\SendNewDatesCampaign;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignRecipients;
use Platform\Recruiting\Services\Statistics\CohortAssigner;
use Platform\Recruiting\Services\Statistics\CohortViewModel;
use Platform\Recruiting\Services\Statistics\TargetLight;
use Platform\Recruiting\Support\CampaignSegment;

/**
 * Statistik-Seite (Spec §3/§4): duenne Livewire-Schale um den CohortAssigner.
 * Die Praezedenz-Kette und die Zuordnungsregel leben NICHT hier, sondern im
 * Assigner — diese Klasse laedt, mappt, delegiert und loest Drill-down-IDs auf.
 *
 * V1 nur per Direkt-URL erreichbar (kein Sidebar-Eintrag, Spec §1 Rollout).
 */
class Index extends Component
{
    /**
     * DER Zeitraum der Seite — und er gehoert der TERMIN-Tabelle (Tabelle 2):
     * gefiltert wird das TERMINDATUM, nicht das Bewerbungsdatum.
     *
     * Die Unterscheidung ist die Stelle, an der man sich hier vergreifen kann,
     * deshalb steht sie ausdruecklich da: ein Termin hat einen Zeitpunkt, eine
     * Ausschreibung hat ein ZIEL. Tabelle 1 fragt „laeuft diese Ausschreibung auf
     * Ziel?" — ein Bewerbungs-Zeitraum wuerde dort den Zaehler beschneiden und den
     * Nenner (Bedarf) stehen lassen, also eine Erfuellungsquote gegen einen
     * Ausschnitt der Bewerbungen rechnen. Genau darum ist der frueher hier
     * vorhandene Zeitraum auf das Bewerbungsdatum (filterFrom/filterTo) mit Task 10
     * ENTFALLEN und nicht bloss umbenannt.
     *
     * Y-m-d-STRINGS, keine Datums-Objekte: x-ui-input-date darf nie per
     * wire:model an ein datetime-Cast gebunden werden (bekannte Falle dieses
     * Projekts — Livewire kann Carbon nicht sauber hydrieren).
     */
    public ?string $interviewFrom = null;
    public ?string $interviewTo = null;

    /**
     * Ein geleertes Datumsfeld liefert '' — normalisiert wird auf null, damit die
     * Property nur zwei Zustaende kennt: gesetzt oder nicht gesetzt.
     *
     * WAS DIESER HOOK NICHT TUT (hier stand vorher die falsche Begruendung): er
     * repariert keinen Filter. `when('')` ueberspringt die Klausel bereits, ein
     * Leerstring haette also nichts weggeschnitten. Der Wert hat in einer
     * ?string-Property trotzdem nichts zu suchen: jeder spaetere Leser, der auf
     * `!== null` prueft — die Anzeige des gewaehlten Zeitraums (Task 10), ein
     * strikter Vergleich, eine Weitergabe an Code, der auf Y-m-d-Strings rechnet —
     * muesste sonst '' und null getrennt behandeln, und DORT waere '' dann
     * gefaehrlich, weil '2026-07-05' >= '' wahr ist. Der Hook haelt den Zustand
     * sauber, statt sich auf das Verhalten von when() zu verlassen.
     */
    public function updatedInterviewFrom($value): void
    {
        $this->interviewFrom = $value ?: null;
        $this->resetDrill();
    }

    public function updatedInterviewTo($value): void
    {
        $this->interviewTo = $value ?: null;
        $this->resetDrill();
    }

    /**
     * PFLICHTAUSWAHL (Kunden-Entscheidung): die Seite zeigt immer genau eine
     * Filiale, „alle Orte" gibt es nicht mehr. Vorbelegt wird in mount() mit dem
     * ersten Ort; ohne gewaehlten Ort zeigt die Seite eine Aufforderung statt
     * einer Tabelle (Guard hasOrt() in der View).
     *
     * Der Pflicht-Ort ist keine Kosmetik, sondern haelt phaseLabels() ehrlich:
     * dort wird der Phasensatz der GEFILTERTEN Filiale gelesen, und
     * `where('location', null)` macht Laravel zu einem `whereNull` — die
     * Spaltenkoepfe kaemen dann aus dem Phasensatz ORTLOSER Stellen, waehrend die
     * Zahlen darunter alle Orte enthalten.
     */
    public ?string $ortFilter = null;
    public ?string $activityFilter = null;

    /**
     * Status der Ausschreibungen: 'online' (Standard) oder 'alle'.
     *
     * „online" heisst status = 'published' UND is_active — und diese Definition
     * wird hier NICHT zum zweiten Mal hergeleitet: cohort() setzt je Zeile
     * `posting_closed` als das exakte Gegenteil davon (Task 8), dieser Filter
     * liest nur dieses eine Feld. Zwei auseinanderdriftende Begriffe von
     * „geschlossen" waeren genau der Widerspruch, den diese Seite abschafft.
     *
     * Absichtlich UNTYPED wie die anderen Select-Properties: ein geleertes
     * <select> sendet '', und Livewire hydriert das nicht sauber in eine
     * typisierte Property.
     */
    public $postingStatusFilter = 'online';

    /**
     * Vorbelegung der Pflichtauswahl. Bewusst NUR, wenn nichts gesetzt ist: ein
     * Ort aus der URL/Session (Livewire-Hydrierung) darf nicht ueberschrieben
     * werden.
     *
     * Ist an keiner Stelle ein Standort gepflegt, bleibt der Filter null — die
     * View sagt dann, dass es nichts zu waehlen gibt, statt eine leere Tabelle zu
     * zeigen.
     */
    public function mount(): void
    {
        $this->applyOrtDefault();
    }

    /**
     * Erster Ort als Vorbelegung, falls keiner gesetzt ist — EINE Stelle fuer
     * mount() und resetFilters(), damit „zuruecksetzen" denselben
     * Ausgangszustand herstellt wie ein frischer Seitenaufruf.
     */
    private function applyOrtDefault(): void
    {
        if ($this->hasOrt()) {
            return;
        }

        // ortOptions() als METHODE, nicht als Computed-Property: mount() laeuft im
        // Livewire-Lebenszyklus, diese Klasse wird aber auch nackt getestet (kein
        // Lifecycle, also kein __get auf Computed Properties). Kostet dieselbe eine
        // Query und ist an beiden Orten dasselbe Ergebnis.
        $options = $this->ortOptions();
        $this->ortFilter = $options === [] ? null : (string) array_key_first($options);
    }

    /**
     * Ist eine Filiale gewaehlt? '' zaehlt wie null — ein geleertes <select>
     * schickt einen Leerstring, und eine Livewire-Hydrierung kann ihn an der
     * updated-Hook vorbei in die Property tragen. Ohne diese Gleichsetzung waere
     * '' ein „gewaehlter Ort", der auf keine Filiale passt: die Seite zeigte dann
     * leere Tabellen statt der Aufforderung.
     */
    public function hasOrt(): bool
    {
        return $this->ortFilter !== null && $this->ortFilter !== '';
    }

    /**
     * Request-Cache der Ortsliste (siehe ortOptions()). PRIVATE, also nicht Teil
     * des Livewire-Snapshots — der Wert wird pro Request neu geholt und kann vom
     * Client nicht gesetzt werden.
     *
     * @var array<string,string>|null
     */
    private ?array $ortOptionsCache = null;

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
        $this->verwerfeUngueltigeAusschreibung();
        $this->resetDrill();
    }

    /**
     * Faellt die gewaehlte Ausschreibung durch einen Wechsel von Filiale oder
     * Status aus der Auswahlliste, wird sie VERWORFEN.
     *
     * Ohne das bliebe eine Ausschreibung stehen, die es in der neuen Auswahl gar
     * nicht gibt: die Tabellen waeren leer, und im Dropdown stuende ein Titel, der
     * dort nicht mehr zur Wahl steht — der Nutzer saehe nicht, WARUM nichts kommt.
     * Dieselbe Falle wie beim Stellenwechsel im Termin-Formular (Task 6).
     *
     * postingOptions() wird hier als METHODE gerufen, nicht als Property: diese
     * Klasse wird auch nackt getestet (kein Livewire-Lebenszyklus, also kein __get
     * auf #[Computed]) — derselbe Grund wie bei ortOptions() in applyOrtDefault().
     * Ein Invalidieren des Computed-Caches braucht es dabei nicht: dieser Hook
     * laeuft VOR dem Rendern, und die Property wird erst beim Rendern aufgeloest.
     */
    private function verwerfeUngueltigeAusschreibung(): void
    {
        if ($this->postingFilter === null) {
            return;
        }

        if (! array_key_exists((int) $this->postingFilter, $this->postingOptions())) {
            $this->postingFilter = null;
        }
    }

    public function updatedActivityFilter($value): void
    {
        $this->activityFilter = ($value === '' || $value === null) ? null : (string) $value;
        $this->resetDrill();
    }

    /**
     * Zwei Zustaende, kein dritter: alles, was nicht ausdruecklich 'alle' ist,
     * faellt auf 'online' zurueck. Ein unbekannter Wert (geleertes Select,
     * gecrafteter Request) wuerde sonst wie 'alle' wirken, weil der Filter unten
     * nur auf 'alle' prueft — und damit waere die engere Standard-Auswahl per
     * Zufall aufgehoben.
     */
    public function updatedPostingStatusFilter($value): void
    {
        $this->postingStatusFilter = $value === 'alle' ? 'alle' : 'online';
        $this->verwerfeUngueltigeAusschreibung();
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

    /**
     * IDs fuer das Drill-down-Modal.
     *
     * #[Locked]: kein Pfad setzt diese Property vom Client — sie wird
     * ausschliesslich in drill() aus den frisch berechneten Assigner-Zeilen
     * gefuellt (resetDrill leert sie). Damit ist das TOKEN der einzige Weg in das
     * Modal; eine clientseitig gesetzte ID-Liste wird von Livewire abgewiesen,
     * statt sich auf den forTeam-Scope in drillApplicants() als letzte Instanz zu
     * verlassen. Der bleibt trotzdem Pflicht (zwei Schlösser, nicht eins).
     *
     * NICHT locked ist $showDrill: die Modal-Komponente bindet es per wire:model
     * (Schliessen im Client), ein Lock wuerde das Modal unschliessbar machen.
     *
     * @var list<int>
     */
    #[Locked]
    public array $drillIds = [];
    public string $drillLabel = '';
    public bool $showDrill = false;

    /**
     * Kampagne „Neue Termine“ (Spec §5.3). Der Scope-Typ kommt aus dem Drill-
     * Token und ist locked wie $drillIds: nur die Kachel/Zeilen „Ohne Termin“
     * (ohne_schulung) schalten den Kampagnen-Bereich frei.
     *
     * $campaignSelection ist NICHT locked (der Client hakt an/ab), wird aber
     * serverseitig gegen Kohorte UND Waehlbarkeit geschnitten
     * (CampaignSegment::selectedIds) — nur IDs aus dem Modal werden je versendet.
     */
    #[Locked]
    public string $drillScopeType = '';
    /**
     * Scope-NAME des Drill-Tokens (z. B. 'type_all'), NICHT der Zeilen-Typ.
     * campaignEnabled() verlangt alle drei Locked-Felder zusammen (siehe dort):
     * der einzige Token in der View, der type => 'ohne_schulung' setzt, ist die
     * Kachel index.blade.php:215 mit scope 'type_all' und OHNE 'set'-Schluessel.
     * Ohne diese Sperre oeffnet ein gecraftetes Token wie
     * {"scope":"all","type":"ohne_schulung"} die Kampagne ueber die GANZE
     * Kohorte statt nur ueber "Ohne Termin".
     */
    #[Locked]
    public string $drillScopeName = '';
    /**
     * Ob das Token einen 'set'-Schluessel trug (closed/unreachable/
     * unknown_origin). drill() loest $rows unabhaengig von 'scope' allein
     * anhand von 'set' auf (match ($spec['set'] ?? null) ...) — ein Token wie
     * {"scope":"type_all","type":"ohne_schulung","set":"unknown_origin"}
     * besteht die Scope/Type-Pruefung, zeigt IDs aber gegen eine DISJUNKTE
     * Population (die drei "beiseite gelegten" Bloecke), die die Kachel "Ohne
     * Termin" nie zeigt. Der legitime Kachel-Token traegt kein 'set'.
     */
    #[Locked]
    public bool $drillHasSet = false;
    /** @var array<int,bool> applicant_id => angehakt */
    public array $campaignSelection = [];
    public ?int $campaignTemplateA = null;
    public ?int $campaignTemplateB = null;
    public ?string $campaignUuid = null;
    public string $campaignError = '';

    /**
     * Ein Filterwechsel invalidiert die Drill-Auswahl — sonst zeigt das Modal
     * Personen, die in der neuen Menge gar nicht mehr vorkommen.
     */
    private function resetDrill(): void
    {
        $this->drillIds = [];
        $this->drillLabel = '';
        $this->showDrill = false;
        $this->drillScopeType = '';
        $this->drillScopeName = '';
        $this->drillHasSet = false;
        $this->campaignSelection = [];
        $this->campaignUuid = null;
        $this->campaignError = '';
    }

    /**
     * Zuruecksetzen heisst AUSGANGSZUSTAND, nicht „alles leer": der Ort ist
     * Pflichtauswahl und wird deshalb wieder vorbelegt (wie in mount()), der
     * Status faellt auf 'online' zurueck. Ein null-Ort haette die Seite auf die
     * Aufforderung zurueckgeworfen — ein „Filter zurücksetzen", nach dem keine
     * Zahlen mehr dastehen, liest sich als Defekt.
     */
    public function resetFilters(): void
    {
        $this->interviewFrom = null;
        $this->interviewTo = null;
        $this->activityFilter = null;
        $this->postingFilter = null;
        $this->sourcePlatformFilter = null;
        $this->postingStatusFilter = 'online';
        $this->ortFilter = null;
        $this->applyOrtDefault();
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
        // `?: null` statt `!== null`: ein Leerstring (Livewire-Hydrierung an der
        // updated-Hook vorbei) wuerde zu 0 casten, und 0 passt auf keine
        // Ausschreibung — der Pivot-Filter unten haette dann JEDE Zuordnung
        // verworfen und jede Bewerbung als „ohne Ausschreibung" gezeigt, waehrend
        // der Vorfilter der Query wegen when(0) gar nicht greift.
        $postingId = ((int) $this->postingFilter) ?: null;

        // P2: Vorfilter spiegeln die PHP-Logik verlustfrei (is_test = Stufe 1,
        // Posting-/Quellen-Filter = Mengeneinschraenkung P3) — Rekonziliation
        // unveraendert, aber die Query laedt nie das ganze Team (Query-Budget ist
        // Abnahmekriterium §2).
        //
        // KEIN Zeitraum-Vorfilter mehr: mit Task 10 ist der Zeitraum das
        // TERMINDATUM (interviewFrom/interviewTo, Tabelle 2) und kein
        // Bewerbungsdatum. Die Bewerber-Menge dieser Query ist damit die volle
        // Auswahl der Seite — eingeschraenkt wird sie danach ueber die Gruppe
        // (Ort/Taetigkeit/Status), nicht ueber applied_at.
        //
        // Falls Q10 grosse Zahlen zeigt: chunkById(500) + assign() pro Chunk
        // fuettern — der Assigner akkumuliert zeilenweise, ist also streamfaehig.
        $applicants = RecApplicant::forTeam($teamId)
            ->where('is_test', false)
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
                // TEAM-SCOPE auf dem Pivot, nicht nur auf den Stammdaten-Lookups.
                //
                // RecApplicant::postings() ist eine ungescopte belongsToMany (eigenes
                // Ticket, hier nicht angefasst) — die Relation folgt also jeder
                // Pivot-Zeile, auch einer, die auf die Ausschreibung eines FREMDEN
                // Teams zeigt. Bis Task 10 fiel das kaum auf, weil die Seite nur Ort
                // und Taetigkeit daraus las; seit der Ausschreibungs-Tabelle steht
                // dort der TITEL, und genau der ist damit sichtbar geworden
                // (nachgewiesen an einem Bewerber, dessen einzige Ausschreibung die
                // fremde war). Dieser Branch hat den Titel hinzugefuegt, also schliesst
                // er die Tuer.
                //
                // Die Bewerbungen VERSCHWINDEN dadurch nicht: ohne verbleibende
                // Pivot-Zeile greift Fall 3 der Zuordnungsregel („ohne
                // Ausschreibung"), die Zeile bleibt in der Gesamtmenge und wird vom
                // Block „Ohne Filial-Zuordnung" benannt (Test). Die SCHREIBSEITE
                // (Dashboard::assignPosting, die exists-Regeln an den
                // Bewerber-Formularen) ist ihr eigenes Ticket.
                'postings' => fn ($q) => $q->forTeam($teamId),
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

        // Positions-Lookups fuer den Fussnoten-Block „Einstellungen in anderer
        // Filiale" (Task 11): woher eine Bewerbung tatsaechlich kam (Stelle der
        // ANZEIGE, aus dem geladenen Posting) gegen wohin sie sich festgelegt
        // hat (Stelle der BEWERBUNG, rec_applicants.rec_position_id). Beide
        // Karten kommen aus der schon geladenen $applicants-Menge — KEINE
        // weitere Query, derselbe Grund wie bei $postingTargets oben.
        $applicantPositionIds = $applicants->pluck('rec_position_id', 'id')->all();
        $postingPositionIds = $applicants->flatMap(fn ($a) => $a->postings)
            ->unique('id')
            ->mapWithKeys(fn ($p) => [(int) $p->id => $p->rec_position_id])
            ->all();

        $rows = [];
        $bookings = [];
        $pivots = [];
        // Ablage fuer Task 10 (Block „Herkunft unbekannt"): applicant_id => true,
        // wenn die einzige(n) unter der aktuellen Ausschreibungs-Auswahl passende(n)
        // Verknuepfung(en) AUSSCHLIESSLICH als Stellenwechsel markiert sind (siehe
        // Schleife unten). Getrennt von $pivots, weil der Assigner diese Information
        // nicht braucht — er sieht nur die (bereits marker-gefilterte) Pivot-Liste.
        $unknownOrigin = [];
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
            // Postings NACH dem Ausschreibungs-Filter der Seite ($postingId), aber
            // VOR dem Marker-Filter — die Menge, die ohne den Marker in
            // $pivots[$a->id] gelandet waere. Erst DANACH wird der Marker
            // abgezogen: nur wenn diese Zwischenmenge nicht leer ist und der
            // Marker sie komplett leerraeumt, ist die Bewerbung ein „Herkunft
            // unbekannt"-Altfall — nicht schon dann, wenn der Ausschreibungs-Filter
            // der Seite ohnehin nichts trifft (das bleibt schlicht „ohne
            // Ausschreibung", Fall 3).
            $postingsUnderFilter = $a->postings
                ->filter(fn ($p) => $postingId === null || (int) $p->id === $postingId);
            // Verknuepfungen aus einem Stellenwechsel sind KEINE Bewerbung auf diese
            // Anzeige (Marker aus switchToPosition bzw. dem Backfill, historisch —
            // der laufende Betrieb erzeugt ihn nicht mehr). Sie zaehlen in keiner
            // Anzeigen-Zeile mit — sonst bekaeme die Anzeige eine Bewerbung, die sie
            // nie erhalten hat. Benannt werden sie im eigenen Block weiter unten.
            $postingsOhneMarker = $postingsUnderFilter
                ->filter(fn ($p) => $p->pivot?->matched_via !== 'position_switch');
            $unknownOrigin[$a->id] = $postingsUnderFilter->isNotEmpty() && $postingsOhneMarker->isEmpty();

            $pivots[$a->id] = $postingsOhneMarker
                ->map(fn ($p) => [
                    'posting_id' => $p->id,
                    'position_id' => $p->rec_position_id,
                    'location' => $p->position?->location,
                    'activity' => $p->activity,
                    'posting_title' => (string) $p->title,
                    // „geschlossen" ist das EXAKTE Gegenteil von „online" — und die
                    // Definition steht NICHT hier, sondern EINMAL im Model
                    // (RecPosting::isOnline). Sie hatte vorher zwei woertliche
                    // Kopien in dieser Datei (hier und in postingOptions), und zwei
                    // auseinanderdriftende Begriffe von „geschlossen" waeren genau
                    // der Widerspruch, den diese Seite abschaffen soll.
                    'posting_closed' => !$p->isOnline(),
                ])->all();
        }

        // null, null als Zeitraum: die SIGNATUR des Assigners bleibt unveraendert
        // (er ist fertig und wird hier nicht angefasst), aber die Seite hat keinen
        // Bewerbungs-Zeitraum mehr — der Zeitraum ist das Termindatum und wirkt in
        // der Termin-Query, nicht in der Kohortenbildung. Der Assigner filtert
        // damit nichts nach Datum; sein Zeitraum-Zweig bleibt fuer Aufrufer und
        // Tests bestehen (dort ist er weiter geprueft).
        //
        // Der Zeilentyp 'ohne_datum' haengt NICHT am Zeitraum, sondern an Stufe 2
        // der Praezedenz-Kette (applied_at IS NULL) — er bleibt also unveraendert
        // Teil der Rekonziliations-Kette und steht weiter im Block „Ausgeschieden".
        //
        // ZWEI ASSIGN-AUFRUFE, EINE POPULATIONS-TRENNUNG (Task 10): Bewerbungen mit
        // $unknownOrigin[$id] === true (Stellenwechsel-Altfaelle) werden VOR dem
        // Assign aus der Hauptpopulation herausgenommen und in einem ZWEITEN,
        // unabhaengigen Aufruf zugeordnet. Der Assigner selbst bleibt dadurch
        // unveraendert (pure Klasse, siehe Klassendoc) und bekommt schlicht zwei
        // disjunkte Eingaben. Das ist staerker als ein Filter NACH dem Assign:
        // eine solche Bewerbung kann so INNERHALB des Assigners nie in denselben
        // Zeilen-Bucket fallen wie eine echte „ohne Ausschreibung"-Bewerbung
        // (Fall 3) — obwohl beide dieselbe Gruppe („ohne Ausschreibung") und
        // womoeglich denselben Zeilentyp haetten —, weil der Assigner die beiden
        // Mengen nie gemeinsam sieht. Ein nachtraeglicher Filter auf dem
        // GRUPPIERTEN Ergebnis koennte das nicht: der Assigner buendelt
        // Bewerbungen mit identischem (type, key, group, posting) in EINER Zeile
        // mit einer 'ids'-LISTE, nicht in einer Zeile je Bewerbung — ein Filter
        // haette entweder die ganze gemischte Zeile behalten oder verworfen.
        $switchedIds = array_flip(array_keys(array_filter($unknownOrigin)));
        $isSwitched = fn (array $r): bool => isset($switchedIds[$r['id']]);

        $result = (new CohortAssigner())->assign(
            array_values(array_filter($rows, fn ($r) => !$isSwitched($r))),
            array_diff_key($bookings, $switchedIds),
            array_diff_key($pivots, $switchedIds),
            null,
            null,
        );
        $unknownOriginAssign = (new CohortAssigner())->assign(
            array_values(array_filter($rows, $isSwitched)),
            array_intersect_key($bookings, $switchedIds),
            array_intersect_key($pivots, $switchedIds),
            null,
            null,
        );

        // Ziel-Werte an die Zeilen haengen. Der Assigner ist eine pure Klasse
        // ohne DB und kennt keine Ausschreibungs-Stammdaten — Bedarf, Faktor und
        // Laufzeit sind deshalb Beigabe des Aufrufers.
        //
        // Kein Default, kein Raten: fehlt der Eintrag (oder haengt die Zeile an
        // keiner Ausschreibung), bleibt jedes Feld null. „Leer heisst nicht
        // gepflegt" ist die tragende Regel dieses Features — eine nicht
        // gepflegte Ausschreibung zeigt eine graue Ampel, nie eine erfundene.
        //
        // BEIDE Assign-Ergebnisse bekommen dieselbe Beigabe (auch wenn
        // posting_id in $unknownOriginAssign['rows'] immer null ist und der
        // Ziel-Lookup dort folglich immer null bleibt): ein Zeilen-Shape, das an
        // einer Stelle Ziel-Felder traegt und an der anderen nicht, waere eine
        // zweite Form, die postingGroups() nicht mehr blind vertrauen koennte.
        $attachTargets = function (array $row) use ($postingTargets): array {
            $target = $row['posting_id'] !== null
                ? ($postingTargets[$row['posting_id']] ?? null)
                : null;

            return $row + [
                'bedarf' => $target['bedarf'] ?? null,
                'bewerbungs_faktor' => $target['bewerbungs_faktor'] ?? null,
                'published_ymd' => $target['published_ymd'] ?? null,
                'closes_ymd' => $target['closes_ymd'] ?? null,
            ];
        };
        $result['rows'] = array_map($attachTargets, $result['rows']);
        $unknownOriginAssign['rows'] = array_map($attachTargets, $unknownOriginAssign['rows']);

        // ------------------------------------------------------------------
        // DREI ABLAGEN, Grundlage je eines eigenen Blocks unter den Tabellen.
        // Sie sind das Netz gegen die einzige stille Luecke, die diese Seite
        // haben kann: eine Bewerbung, die aus der Filial-Ansicht faellt und
        // nirgends benannt wird.
        //
        // Warum die Rekonziliation (Block 5) dieses Netz NICHT ersetzt: `total_ids`
        // wird nach dem Filtern NEU gebildet (unten). Σ Zeilen == Gesamtmenge gilt
        // damit per Konstruktion INNERHALB der Auswahl — was vor dem Filter
        // herausfiel, kann der Hinweis nie sehen.
        //
        // Der TAETIGKEITS-Filter wirkt auf alle drei Ablagen, der ORT-Filter auf
        // keine. Das ist keine Schlamperei, sondern die Asymmetrie der beiden
        // Filter: wer eine Taetigkeit waehlt, will keine fremde sehen — wer eine
        // Filiale waehlt, kann die Zeilen OHNE Filiale ueber keine Auswahl je
        // erreichen.
        $inActivity = fn ($r) => $this->activityFilter === null
            || $r['group']['taetigkeit'] === $this->activityFilter;

        // (1) GESCHLOSSENE Ausschreibungen — Block „Geschlossene Ausschreibungen".
        // 'posting_closed' kommt aus derselben Quelle wie der Status-Filter unten
        // (eine Definition von „online", siehe $postingStatusFilter).
        $result['closed_rows'] = array_values(array_filter(
            $result['rows'],
            fn ($r) => ($r['posting_closed'] ?? false) === true && $inActivity($r),
        ));

        // (2) STELLENWECHSEL-ALTFAELLE (Task 10) — Block „Herkunft unbekannt".
        // Stammt aus dem ZWEITEN, unabhaengigen Assign-Aufruf oben — strukturell
        // disjunkt von $result['rows'] (Ablage 1/3), weil keine einzige dieser
        // Bewerbungen je in dessen Eingabe stand. Der Assigner sieht diese
        // Bewerbungen mangels verbliebener Pivot-Zeile als „ohne Ausschreibung"
        // (posting_id null, Fall 3 der Zuordnungsregel) — der Unterschied zu einer
        // Bewerbung ganz ohne Verknuepfung ist real (hier ist eine Anzeige
        // bekannt, nur eben keine ZUVERLAESSIGE), deshalb der eigene Block statt
        // desselben Fallback-Namens.
        //
        // Rein historisch (Docblock der Klasse): der Marker entsteht im laufenden
        // Betrieb nicht mehr, gesetzt wurde er von einem frueheren Zwischenstand
        // und vom Backfill-Kommando — rund 15 Altfaelle, kein wachsender Topf.
        $result['unknown_origin_rows'] = array_values(array_filter($unknownOriginAssign['rows'], $inActivity));

        // (3) Zeilen, die ueber KEINE Filial-Auswahl erreichbar sind — Block „Ohne
        // Filial-Zuordnung". Das sind die Bewerbungen an Stellen OHNE gepflegten
        // Standort (Gruppe „ohne Ort", gemessen rund 929) und die Bewerbungen ohne
        // jede Ausschreibung (Fall 3 der Zuordnungsregel, Gruppe „ohne
        // Ausschreibung"). Die Stellenwechsel-Altfaelle (Ablage 2) koennen hier
        // NICHT auftauchen — sie stehen gar nicht in $result['rows']/total_ids
        // dieser Auswahl (siehe die Populations-Trennung oben), keine gesonderte
        // Ausschluss-Bedingung noetig.
        //
        // ERREICHBARKEIT statt Namensliste: eine Gruppe ist genau dann waehlbar,
        // wenn sie in der Ortsliste des Filters vorkommt. Damit haengt die
        // Definition an derselben Quelle wie das <select> und nicht an kopierten
        // Fallback-Literalen des Assigners („ohne Ort"/„ohne Ausschreibung") — die
        // koennten auseinanderlaufen, die Ortsliste kann es nicht.
        //
        // posting_closed === false ist PFLICHT: die geschlossenen zeigt schon
        // Ablage (1). Ohne diese Bedingung stuenden dieselben Zeilen in zwei
        // Bloecken, und zwei Bloecke, die dasselbe zaehlen, sind wieder eine Zahl,
        // die niemand nachrechnen kann.
        $selectableOrte = $this->ortOptions(); // eine Query pro Request, siehe dort
        $result['unreachable_rows'] = array_values(array_filter(
            $result['rows'],
            fn ($r) => ($r['posting_closed'] ?? false) === false
                && !array_key_exists((string) $r['group']['ort'], $selectableOrte)
                && $inActivity($r),
        ));

        // Ort-, Taetigkeits- und Status-Filter wirken auf die GRUPPE (nach dem
        // Assign, damit die Rekonziliation innerhalb der Auswahl geschlossen
        // bleibt): total_ids wird aus den verbliebenen Zeilen neu gebildet, die
        // Gesamt-Zeile der Tabelle ist also weiter die Addition ihrer Zeilen.
        //
        // Der Status-Filter gehoert hierher und nicht in die Anzeige: waere er nur
        // ein Anzeige-Filter, zeigte die Gesamt-Zeile mehr als die Summe der
        // sichtbaren Zeilen — dieselbe stille Differenz, wegen der diese Seite
        // gebaut wird.
        //
        // TERMIN-MENGE fuer Tabelle 2, festgehalten VOR dem Auswahl-Filter: alle
        // Kohorten-Zeilen beider Assign-Aufrufe, also jede Buchung des Teams
        // (ausser Testbewerbern, Stufe 1). Ort, Taetigkeit und Status sind
        // Eigenschaften der AUSSCHREIBUNG und sieben deshalb die Ausschreibungs-
        // Zeilen (Tabelle 1) — ein Termin hat aber Teilnehmer, keine Herkunft.
        // Wer ueber die Koelner Anzeige kam und in Duesseldorf teilgenommen hat,
        // hat in Duesseldorf teilgenommen (Live-Befund 25.08.2026: 16 attended, die
        // Zeile zeigte 11). Die Herkunft bleibt als Unterzeile sichtbar, faellt
        // aber nicht mehr als Sieb vor die Zahl.
        //
        // Die Stellenwechsel-Altfaelle (zweiter Assign-Aufruf) gehoeren hier
        // dazu: ihre Buchung ist echt, nur ihre Anzeige ist unbekannt — in der
        // Termin-Zeile stehen sie unter „ohne Ausschreibung".
        //
        // Nicht erfasst bleiben die Vorfilter der Bewerber-QUERY („Einzelne
        // Ausschreibung", Quelle): sie bestimmen, WELCHE Bewerbungen die Seite
        // ueberhaupt laedt, und wirken damit auf beide Tabellen gleich.
        $result['termin_rows'] = array_merge($result['rows'], $unknownOriginAssign['rows']);

        $onlineOnly = $this->postingStatusFilter !== 'alle';
        if ($this->ortFilter !== null || $this->activityFilter !== null || $onlineOnly) {
            $result['rows'] = array_values(array_filter($result['rows'], fn ($r) =>
                ($this->ortFilter === null || $r['group']['ort'] === $this->ortFilter)
                && ($this->activityFilter === null || $r['group']['taetigkeit'] === $this->activityFilter)
                && (!$onlineOnly || ($r['posting_closed'] ?? false) === false)));
            $result['total_ids'] = array_merge(...array_map(fn ($r) => $r['ids'], $result['rows']) ?: [[]]);
        }

        // Reine Beigabe fuer fremdeFilialeTotals() (Task 11) — dieselben zwei
        // Karten wie oben, unveraendert vom Ort-/Taetigkeits-/Status-Filter:
        // die Fussnote liest je Zeile die Stelle der Anzeige und vergleicht sie
        // gegen die Stelle der jeweiligen Bewerbung, der Filter oben hat darauf
        // keinen Einfluss.
        $result['applicant_position_ids'] = $applicantPositionIds;
        $result['posting_position_ids'] = $postingPositionIds;

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
     * Zwei Wege fallen dadurch bewusst heraus, beide konservativ — lieber ein zu
     * flacher Trichter als eine erfundene Stufe:
     *  - Log-Eintraege ohne `rec_position_id` (Spalte ist nullable). Hier deckt
     *    die aktuelle Phase den Live-Zustand ab, es fehlt nur die Historie.
     *  - Bewerber OHNE aktuelle Phase: sie haben keine Stelle, gegen die man
     *    vergleichen koennte, also greift der Join nicht. Hier deckt die aktuelle
     *    Phase eben NICHTS ab — `phase_order_reached` bleibt null und die Zeile
     *    steht mit leerer `phase_reached` im Trichter, obwohl das Log Stufen
     *    kennt. Bewusst so: ohne Stelle ist nicht entscheidbar, zu welchem
     *    Phasensatz die geloggte `order` gehoert, und eine Spalte im Trichter
     *    einer fremden Stelle waere eine Falschzahl.
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
        // Der Status-Filter sitzt in cohort() (Zeilen-Ebene), nicht hier: waere er
        // ein Anzeige-Filter, zeigte die Gesamt-Zeile der Tabelle mehr als die
        // Summe ihrer sichtbaren Zeilen.
        return $this->viewModel()->postingGroups($this->cohort['rows']);
    }

    /**
     * Die Zeilen GESCHLOSSENER Ausschreibungen, gruppiert wie Tabelle 1 — Grundlage
     * des Blocks „Geschlossene Ausschreibungen".
     *
     * Bewusst dieselbe Gruppierung wie die Tabelle (postingGroups), damit ein
     * Eintrag dort genauso zu lesen ist wie eine Zeile oben. Die Menge stammt aber
     * aus `closed_rows` und ist damit NICHT ortsgefiltert: eine Ausschreibung an
     * einer Stelle ohne gepflegten Standort gehoert zu keiner Filiale und waere
     * sonst nirgends sichtbar.
     *
     * @return list<array>
     */
    #[Computed]
    public function closedPostingGroups(): array
    {
        return $this->viewModel()->postingGroups($this->cohort['closed_rows']);
    }

    /**
     * Die Zeilen, die ueber KEINE Filial-Auswahl erreichbar sind — Grundlage des
     * Blocks „Ohne Filial-Zuordnung" (Bewerbungen an Stellen ohne gepflegten
     * Standort und Bewerbungen ohne jede Ausschreibung).
     *
     * Gruppiert wie Tabelle 1 und wie der Block der geschlossenen
     * Ausschreibungen — drei Listen, eine Lesart.
     *
     * @return list<array>
     */
    #[Computed]
    public function unreachablePostingGroups(): array
    {
        return $this->viewModel()->postingGroups($this->cohort['unreachable_rows']);
    }

    /**
     * Die Zeilen der Stellenwechsel-Altfaelle — Grundlage des Blocks „Herkunft
     * unbekannt" (Task 10). Gruppiert wie die drei Bloecke darueber; alle Zeilen
     * haben `posting_id === null` (der Assigner kennt keine verbliebene
     * Verknuepfung mehr), postingGroups() fasst sie deshalb zu genau EINER
     * Gruppe zusammen.
     *
     * @return list<array>
     */
    #[Computed]
    public function unknownOriginPostingGroups(): array
    {
        return $this->viewModel()->postingGroups($this->cohort['unknown_origin_rows']);
    }

    /**
     * Phasen sind pro Stelle geklont und frei benannt, deshalb aus der Auswahl
     * lesen statt fest verdrahten; bei mehreren Stellen am Ort gewinnt der letzte
     * Name je `order`, was unkritisch ist, weil geklonte Phasen gleich heissen.
     *
     * DER ORT IST PFLICHT, und diese Methode ist der Grund: Laravel macht aus
     * `where('location', null)` ein `whereNull` — ohne gewaehlten Ort kaemen die
     * Kopfzeilen aus dem Phasensatz ORTLOSER Stellen, waehrend die Zahlen darunter
     * alle Orte enthalten. Seit Task 10 ist der Fall im Anzeige-Pfad nicht mehr
     * erreichbar: die View rendert beide Tabellen nur hinter dem Guard hasOrt(),
     * und ohne Ort steht dort die Aufforderung, eine Filiale zu waehlen. Deshalb
     * steht hier weiterhin KEIN Fallback — er waere toter Code und wuerde Spalten
     * aus fremden Phasensaetzen mischen.
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
     * benennen muss: welche absoluten Zahlen den Prozentwert bilden und was
     * wegen fehlendem Bedarf NICHT darin steckt. Ohne diese Angabe widerspricht
     * die Quote scheinbar der Spalte „Unterschrieben" daneben, die alle
     * Ausschreibungen zaehlt. Er ist EINE Quelle fuer Tooltip und sichtbare
     * Fussnote — zwei Formulierungen derselben Differenz koennten auseinanderlaufen.
     *
     * Zwei Dinge, die der Text streng trennt (siehe fulfilmentTotals):
     *  - Ausschreibungen ohne gepflegten Bedarf (Pflege-Hinweis) und
     *  - die Zeile „ohne Ausschreibung", wo es nichts zu pflegen GIBT.
     * Ohne die Trennung war die genannte Zahl regelmaessig um eins zu gross und
     * passte nicht zu den Zeilen der Tabelle.
     *
     * @param  list<array>  $groups
     * @return array{status:string, pct:?int, reason:string, signed:int, bedarf:?int,
     *               excluded_postings:int, excluded_signed:int,
     *               without_posting_groups:int, without_posting_signed:int}
     */
    public function fulfilmentTotalLight(array $groups): array
    {
        $totals = $this->viewModel()->fulfilmentTotals($groups);
        $light = TargetLight::fulfilment($totals['signed'], $totals['bedarf']);
        $light['pct'] = $totals['pct'];

        // Was aus der Quote fällt, einzeln benannt — nie zusammengezählt.
        $ausserhalb = [];
        if ($totals['excluded_postings'] > 0) {
            $ausserhalb[] = $totals['excluded_postings'] . ' '
                . ($totals['excluded_postings'] === 1 ? 'Ausschreibung' : 'Ausschreibungen')
                . ' ohne gepflegten Bedarf (' . $totals['excluded_signed'] . ' '
                . ($totals['excluded_signed'] === 1 ? 'Unterschrift' : 'Unterschriften') . ')';
        }
        if ($totals['without_posting_groups'] > 0) {
            $ausserhalb[] = 'die Bewerbungen ohne Ausschreibung ('
                . $totals['without_posting_signed'] . ' '
                . ($totals['without_posting_signed'] === 1 ? 'Unterschrift' : 'Unterschriften') . ')';
        }
        $ausserhalbText = $ausserhalb === [] ? '' : implode(' und ', $ausserhalb);
        // Der Zusatz gilt nur, wenn dort wirklich Unterschriften liegen —
        // sonst waere die Spalte „Unterschrieben" gar nicht hoeher.
        $spaltenHinweis = ($totals['excluded_signed'] + $totals['without_posting_signed']) > 0
            ? ' — deshalb ist die Spalte „Unterschrieben“ höher als der Zähler hier.'
            : '.';

        if ($totals['bedarf'] === null) {
            // KEINE erfundene Null: ohne gepflegten Bedarf gibt es keinen Nenner
            // und damit keine Quote. „0 von 0" hätte behauptet, es sei nichts
            // nötig und nichts erreicht.
            $light['reason'] = 'Kein Bedarf gepflegt — ohne Bedarf gibt es keine Quote (auch keine 0 %).'
                . ($ausserhalbText === '' ? '' : ' Betroffen: ' . $ausserhalbText . '.');
        } else {
            $light['reason'] = $totals['signed'] . ' von ' . $totals['bedarf']
                . ' benötigten Einstellungen unterschrieben (nur Ausschreibungen mit gepflegtem Bedarf).'
                . ($ausserhalbText === ''
                    ? ''
                    : ' NICHT in dieser Quote: ' . $ausserhalbText . $spaltenHinweis);
        }

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
     * Die Absolutzahlen reisen mit (`bewerbungen`, `target`) — wie bei
     * fulfilmentTotalLight, und aus demselben Grund: die Zelle zeigt den Bruch
     * unter dem Prozentwert an, damit er aus seinen eigenen Nachbarn nachrechenbar
     * ist. Ohne sie war die Pipeline-Quote der Gesamt-Zeile die einzige Zahl der
     * Seite, die niemand pruefen konnte.
     *
     * @param  list<array>  $groups
     * @return array{status:string, pct:?int, projected:?int, target:?int, reason:string,
     *               bewerbungen:int}
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

        // + $totals: 'bewerbungen' und 'target' fuer die Anzeige des Bruchs.
        // 'target' steckt schon in $light (derselbe Wert, aus TargetLight) — der
        // Merge darf ihn also nicht ueberschreiben, tut es aber auch nicht: beide
        // kommen aus pipelineTotals.
        return $light + $totals;
    }

    /**
     * Einstellungen in ANDERER Filiale (Task 11, Kunden-Entscheidung): eine
     * Unterschrift zaehlt bei der ANZEIGE, ueber die die Bewerbung kam — auch
     * dann, wenn die Person sich am Ende auf einer ANDEREN Stelle festgelegt
     * hat (rec_applicants.rec_position_id weicht von der Stelle der Anzeige
     * dieser Zeile ab). Der BEDARF haengt dagegen an der Stelle, an der Leute
     * gebraucht werden — wer ueber die Duesseldorfer Anzeige kam und in
     * Moenchengladbach unterschreibt, bedient den Bedarf von Moenchengladbach
     * nicht. Dieser Block beziffert genau diesen Versatz, statt ihn zu
     * verschweigen.
     *
     * KEIN neuer Rechenweg: gezaehlt wird ueber dieselbe Spalte
     * 'unterschrieben' wie die Erfuellungsquote (idsOf()), nur zusaetzlich
     * gegen die beiden Positions-Karten aus cohort() geprueft (dieselbe Quelle
     * fuer Zahl und Text wie bei den beiden Fussnoten darueber). Eine Zeile
     * ohne Ausschreibung (posting_id null) kann hier nie auftauchen — ohne
     * Anzeige gibt es keine „Stelle der Anzeige", gegen die man vergleichen
     * koennte.
     *
     * Fehlt die Stelle der Bewerbung (rec_position_id noch nicht gepflegt),
     * zaehlt die Unterschrift hier NICHT als Abweichung: „leer heisst nicht
     * gepflegt", nicht „garantiert woanders eingestellt".
     *
     * @param  list<array>  $groups  postingGroups()-Ergebnis (Tabelle 1)
     * @param  array{applicant_position_ids:array<int,?int>, posting_position_ids:array<int,?int>}  $cohort
     * @return array{count:int, reason:string}
     */
    public function fremdeFilialeTotals(array $groups, array $cohort): array
    {
        $vm = $this->viewModel();
        $applicantPositionIds = $cohort['applicant_position_ids'] ?? [];
        $postingPositionIds = $cohort['posting_position_ids'] ?? [];

        $ids = [];
        foreach ($groups as $group) {
            $postingId = $group['posting_id'] ?? null;
            if ($postingId === null) {
                continue;
            }
            $postingPositionId = $postingPositionIds[$postingId] ?? null;
            if ($postingPositionId === null) {
                continue;
            }
            foreach ($vm->idsOf($group, 'unterschrieben') as $id) {
                $applicantPositionId = $applicantPositionIds[$id] ?? null;
                if ($applicantPositionId !== null && (int) $applicantPositionId !== (int) $postingPositionId) {
                    $ids[] = $id;
                }
            }
        }

        $count = count($ids);

        return [
            'count' => $count,
            'reason' => $count === 0
                ? ''
                : $count . ' ' . ($count === 1 ? 'Unterschrift zählt' : 'Unterschriften zählen')
                    . ' bei der Anzeige, über die '
                    . ($count === 1 ? 'die Bewerbung kam' : 'die Bewerbungen kamen') . ' — '
                    . ($count === 1 ? 'eingestellt wurde die Person' : 'eingestellt wurden die Personen')
                    . ' aber in einer anderen Filiale (Kunden-Entscheidung: die Herkunft zählt, '
                    . 'nicht der Einstellungsort — der Bedarf der Einstellungs-Filiale wird '
                    . 'dadurch nicht bedient).',
        ];
    }

    /**
     * Die Termine der Auswahl (Tabelle 2). Der Zeitraum-Filter gehoert HIERHIN:
     * ein Termin hat einen Zeitpunkt, eine Ausschreibung hat ein Ziel.
     *
     * `is_active = true` filtert die Test-Termine mit heraus, sobald HR sie
     * inaktiv gesetzt hat — Termine haben kein is_test-Flag (dokumentierter
     * Befund), inaktiv ist der einzige Weg.
     *
     * ORT: eingegrenzt ueber die STELLE des Termins (rec_interviews.rec_position_id
     * → rec_positions.location), also genau so, wie Tabelle 1 den Ort herleitet.
     * Ohne diese Einschraenkung stuenden hier die Termine aller Filialen, waehrend
     * Tabelle 1 eine einzige zeigt — genau das Nebeneinander, das der Kunde
     * reklamiert hat. Der Ort ist seit Task 10 Pflichtauswahl; das `when()` bleibt
     * trotzdem stehen, weil diese Methode auch ohne den View-Guard aufrufbar ist
     * (Tests, ein gecrafteter Action-Aufruf) und dann NICHT die Termine aller
     * Filialen als „eine Filiale" ausgeben soll — ohne Ort schraenkt sie nicht ein,
     * gerendert wird in diesem Zustand aber keine Tabelle.
     *
     * KEIN Status-Filter: `postingStatusFilter` ist eine Eigenschaft der
     * AUSSCHREIBUNG und wirkt auf die Kohorten-Zeilen (Tabelle 1). Ein Termin ist
     * nicht veroeffentlicht, er findet statt — ihn nach dem Status seiner
     * Ausschreibung zu verstecken hiesse, einen stattgefundenen Termin zu
     * verschweigen. Seine Teilnehmer-Zahlen folgen dem Filter ebenfalls NICHT
     * (cohort()['termin_rows']) — nur die Herkunfts-Unterzeilen zeigen, aus
     * welcher Ausschreibung wer kam.
     *
     * NICHT gefiltert wird auf `rec_interviews.location`: das ist freier Text und
     * der VERANSTALTUNGSORT (Bahnhof, Hotel, Treffpunktbeschreibung). Er kann vom
     * Ort der Stelle abweichen und wird nur angezeigt — ein Filter darauf haette
     * Termine verschluckt, die sehr wohl zur Filiale gehoeren.
     *
     * Query-Budget (Abnahmekriterium §2): DREI Queries — eine fuer die Termine
     * (Belegung als Subselect, Ort als Sub-Query), zwei fuer die Eager Loads
     * (Terminart, Ausschreibung). Kein N+1 ueber die Termine; die Belegung kommt
     * bewusst per withCount und nicht ueber takenSeatsCount() je Termin.
     *
     * @return \Illuminate\Support\Collection<int,\Platform\Recruiting\Models\RecInterview>
     */
    #[Computed]
    public function interviews()
    {
        return RecInterview::forTeam($this->teamId())
            ->where('is_active', true)
            ->when($this->ortFilter, fn ($q) => $q->whereHas('position',
                fn ($p) => $p->where('location', $this->ortFilter)))
            ->when($this->interviewFrom, fn ($q) => $q->where('starts_at', '>=', $this->interviewFrom))
            ->when($this->interviewTo, fn ($q) => $q->where('starts_at', '<=', $this->interviewTo . ' 23:59:59'))
            // BEIDE Eager Loads team-gescopt, nicht nur spaltenbeschraenkt.
            // `posting:id,title` allein war ein Leck: der Fremdschluessel am Termin
            // war (bis zur Haertung der Validierung in InterviewSchedule\Index)
            // ungeprueft setzbar, und die Relation folgt ihm ohne Rueckfrage — der
            // TITEL einer fremden Ausschreibung stand dann in dieser Tabelle
            // (gemessen: „GEHEIM Fremdteam Ausschreibung" in der Ansicht eines
            // anderen Teams).
            //
            // Zwei Schloesser, wie beim Drill-down: die Validierung verhindert die
            // Zuordnung, der Scope hier verhindert die ANZEIGE einer Zuordnung, die
            // es trotzdem gibt (Altbestand, Direkt-Import, SQL von Hand). Faellt der
            // Titel weg, zeigt die Tabelle den Termin-Titel — der Termin selbst
            // gehoert ja dem Team.
            ->with([
                'interviewType' => fn ($q) => $q->forTeam($this->teamId())->select('id', 'name'),
                'posting' => fn ($q) => $q->forTeam($this->teamId())->select('id', 'title'),
            ])
            ->withCount(['bookings as seat_taking_count' => fn ($q) => $q->seatTaking()])
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * Tabelle 2 als fertiger Anzeige-Baum: `['rows' => …, 'outside' => …]`.
     *
     * @return array{rows:list<array>, outside:array{interviews:int, applications:int}}
     */
    #[Computed]
    public function interviewTable(): array
    {
        return $this->buildInterviewTable($this->interviews, $this->cohort['termin_rows'], $this->cohort['rows']);
    }

    /**
     * Termin-Query und Kohorten-Zeilen zu EINER Tabellenzeile je Termin
     * zusammenfuehren.
     *
     * Nimmt beide Quellen als ARGUMENTE, statt sie sich selbst zu holen: so laeuft
     * dieselbe Zusammenfuehrung im Test ohne Livewire-Lifecycle (Computed
     * Properties gibt es nur im Request), waehrend die Computed-Huelle darueber in
     * Produktion weiter genau eine Query-Runde kostet.
     *
     * PROTECTED, obwohl der Test sie braucht: jede public Methode einer
     * Livewire-Komponente ist vom Client als Action aufrufbar, und diese hier
     * erwartet Eloquent-Objekte — ein gecrafteter Aufruf mit Arrays waere ein
     * 500er auf Zuruf. Der Test kommt ueber eine Unterklasse heran (Probe), die
     * Produktions-Oberflaeche bleibt zu.
     *
     * ZWEI QUELLEN, und die Trennung ist Absicht:
     *  - TRICHTER: aus den Assigner-Zeilen ($terminRows) — dieselbe Zaehlung wie
     *    Tabelle 1 (ein Assigner-Lauf, keine zweite Query), aber die UNGEFILTERTE
     *    Menge: Ort-, Taetigkeits- und Status-Filter sieben Ausschreibungs-Zeilen,
     *    ein Termin hat dagegen Teilnehmer und keine Herkunft (siehe cohort(),
     *    termin_rows). Die Herkunft steht als Unterzeile darunter.
     *  - BELEGUNG (IST/SOLL): aus dem Termin (seat_taking_count,
     *    max_participants), weil Plaetze eine Eigenschaft des TERMINS sind und
     *    nicht der Kohorte. Sie ignoriert alle Filter der Seite.
     * Die beiden koennen weiterhin auseinandergehen (Testbewerber belegen einen
     * Platz und stehen in keiner Kohorte; eine Buchung mit unbekanntem Status
     * belegt, zaehlt aber nicht) — und sie duerfen NICHT gegeneinander gerechnet
     * werden. Die Spaltenkoepfe der View sagen das auch.
     *
     * Termine OHNE Kohorten-Teilnehmer bleiben eine Zeile: „fuenf Plaetze, einer
     * belegt, keiner davon in dieser Auswahl" ist eine Aussage, ein
     * verschwundener Termin waere keine.
     *
     * `outside` benennt die Gegenrichtung: Teilnehmer, deren Termin NICHT in
     * dieser Auswahl liegt. Sie stecken in Tabelle 1 und fehlen hier — sichtbar
     * gemacht statt verschluckt, sonst ist die kleinere Summe von Tabelle 2 nicht
     * nachvollziehbar. Gerechnet wird das ueber $auswahlRows (die GEFILTERTE
     * Menge, also Tabelle 1): eine Wuppertaler Buchung an einem Wuppertaler
     * Termin ist bei Filiale Essen keine Differenz — beide Tabellen zeigen sie
     * nicht.
     *
     * Die Gruende sind ausdruecklich NICHT abschliessend aufzaehlbar, und die
     * Fussnote der View formuliert das auch so. Bekannt sind mindestens: der
     * Termin ist inaktiv gesetzt; er liegt ausserhalb des Termin-Zeitraums; er
     * haengt an KEINER Stelle oder an einer Stelle einer anderen Filiale
     * (rec_interviews.rec_position_id ist nullable, und der Assigner bildet die
     * Schulungszeile allein ueber die BUCHUNG — der Ort der Zeile kommt von der
     * Ausschreibung des Bewerbers, nicht vom Termin); er ist geloescht
     * (SoftDeletes). Eine Aufzaehlung, die sich vollstaendig gibt und es nicht
     * ist, erklaert die Differenz falsch — und das ist schlimmer, als sie nicht zu
     * erklaeren.
     *
     * @param  iterable<\Platform\Recruiting\Models\RecInterview>  $interviews
     * @param  list<array>  $terminRows  Assigner-Zeilen OHNE Auswahl-Filter (cohort()['termin_rows'])
     * @param  list<array>  $auswahlRows  Assigner-Zeilen der Auswahl (cohort()['rows']), nur fuer `outside`
     * @return array{rows:list<array>, outside:array{interviews:int, applications:int}}
     */
    protected function buildInterviewTable($interviews, array $terminRows, array $auswahlRows): array
    {
        $vm = $this->viewModel();
        $cohorts = $vm->interviewCohorts($terminRows);

        $tableRows = [];
        $shown = [];
        foreach ($interviews as $interview) {
            $interviewId = (int) $interview->id;
            $shown[$interviewId] = true;
            $cohort = $cohorts[$interviewId] ?? ['rows' => [], 'origins' => []];

            // Ausschreibung des Termins, Rueckfall auf den Termin-Titel. Kein
            // "ohne Titel"-Erfinden: ist beides leer, bleibt die Zelle leer und die
            // View schreibt den Befund hin.
            $postingTitle = (string) ($interview->posting?->title ?? '');
            if ($postingTitle === '') {
                $postingTitle = (string) ($interview->title ?? '');
            }

            $tableRows[] = [
                'interview_id' => $interviewId,
                // Carbon (datetime-Cast, Spalte ist NOT NULL) — reine Anzeige,
                // formatiert wird in der View; deshalb gibt es dort auch keinen
                // „Termin ohne Datum"-Zweig
                'starts_at' => $interview->starts_at,
                'type' => $interview->interviewType?->name ?? 'ohne Terminart',
                // freier Text und VERANSTALTUNGSORT: nur Info-Spalte, nie Filter
                'location' => $interview->location,
                'posting_title' => $postingTitle,
                'has_posting' => $interview->posting !== null,
                'max' => $interview->max_participants,
                'seat_taking' => (int) ($interview->seat_taking_count ?? 0),
                'rows' => $cohort['rows'],
                'origins' => $cohort['origins'],
            ];
        }

        $outsideInterviews = 0;
        $outsideApplications = 0;
        foreach ($vm->interviewCohorts($auswahlRows) as $interviewId => $cohort) {
            if (isset($shown[$interviewId])) {
                continue;
            }
            $outsideInterviews++;
            $outsideApplications += $vm->countIn($cohort['rows'], 'ids');
        }

        return [
            'rows' => $tableRows,
            'outside' => ['interviews' => $outsideInterviews, 'applications' => $outsideApplications],
        ];
    }

    /**
     * Summen-Belegung der Termin-Tabelle samt Begruendungstext.
     *
     * Die Arithmetik liegt in CohortViewModel::interviewTotals (Σ Zaehler und
     * Σ Nenner ueber DIESELBE Auswahl — nur Termine MIT Platzbegrenzung), hier
     * kommt der Text dazu. Genau wie bei fulfilmentTotalLight() ist der `reason`
     * EINE Quelle fuer Tooltip und sichtbare Fussnote: zwei Formulierungen
     * derselben Differenz sind zwei Stellen, an denen eine Zahl falsch werden kann.
     *
     * WORTWAHL: „ohne Platzbegrenzung", nicht „ohne gepflegte Kapazitaet". Die
     * Datenzeile rendert fuer denselben Wert „1 / ∞" (meter.blade.php), liest ihn
     * also als UNBEGRENZT — und dieselbe Lesart gilt in Tabelle 1. Zwei Woerter
     * fuer denselben Zustand waeren zwei Lesarten, und die Fussnote wuerde der
     * Zeile daneben widersprechen.
     *
     * Der Text nennt die ausgelassenen Termine MIT ihren belegten Plaetzen. Ohne
     * diese Angabe waere die Zelle aus ihren Nachbarn nicht nachrechenbar: die
     * Zeilen darueber zeigen Belegungen, die in dieser Summe nicht mitzaehlen.
     *
     * PUBLIC, obwohl buildInterviewTable() genau deswegen protected ist — der
     * Unterschied ist der Parametertyp, nicht die Sichtbarkeit an sich: diese
     * Methode nimmt einfache Arrays, liest daraus zwei Zahlen und gibt Zahlen und
     * Text zurueck. Ein gecrafteter Client-Aufruf rechnet also ueber seine eigenen
     * Werte, laesst nichts liegen (Livewire verwirft Rueckgabewerte) und kommt an
     * keine Daten. buildInterviewTable() erwartet dagegen ELOQUENT-Objekte und
     * greift auf deren Relationen zu — dort ist ein Aufruf mit Arrays ein 500er
     * auf Zuruf. Damit steht sie in derselben Reihe wie countIn() oder
     * fulfilmentLight(), die aus demselben Grund public sind.
     *
     * @param  list<array{max:?int, seat_taking:int}>  $interviewRows
     * @return array{taken:?int, max:?int, unlimited_interviews:int,
     *               unlimited_taken:int, reason:string}
     */
    public function belegungTotals(array $interviewRows): array
    {
        $totals = $this->viewModel()->interviewTotals($interviewRows);

        $ausgelassen = $totals['unlimited_interviews'];
        $ausgelassenePlaetze = $totals['unlimited_taken'];
        $ausgelassenText = $ausgelassen === 0
            ? ''
            : $ausgelassen . ' ' . ($ausgelassen === 1 ? 'Termin' : 'Termine')
                . ' ohne Platzbegrenzung (' . $ausgelassenePlaetze . ' '
                . ($ausgelassenePlaetze === 1 ? 'belegter Platz' : 'belegte Plätze') . ')';

        if ($totals['max'] === null) {
            // KEINE erfundene Kapazitaet und keine „0 von ∞"-Anzeige: ohne
            // Platzbegrenzung gibt es keinen Nenner, also keine Belegungs-Quote.
            // Die belegten Plaetze verschwinden trotzdem nicht — sie stehen im Text.
            $totals['reason'] = 'Kein Termin dieser Auswahl hat eine Platzbegrenzung — '
                . 'ohne Begrenzung gibt es keine Belegungs-Quote (auch keine 0 %).'
                . ($ausgelassenText === '' ? '' : ' Betroffen: ' . $ausgelassenText . '.');
        } else {
            // Der Zusatz „deshalb ist die Summe kleiner" haengt an den belegten
            // PLAETZEN, nicht an der Zahl der Termine: ein unbegrenzter Termin ohne
            // Buchung laesst die Summe unveraendert, und eine benannte Differenz,
            // die es nicht gibt, ist derselbe Regelbruch wie eine falsche Quote —
            // nur am Rand. (Bestand [5/2] + [∞/0]: „1 Termin ohne Platzbegrenzung
            // (0 belegte Plätze)" ist richtig, „deshalb kleiner" waere falsch,
            // denn 2 = 2.)
            $totals['reason'] = $totals['taken'] . ' von ' . $totals['max']
                . ' Plätzen belegt (nur Termine mit Platzbegrenzung — Zähler und Nenner '
                . 'zählen dieselben Termine).'
                . ($ausgelassenText === ''
                    ? ''
                    : ' NICHT in dieser Summe: ' . $ausgelassenText
                        . ($ausgelassenePlaetze > 0
                            ? ' — deshalb ist die Summe kleiner als die Belegungen der Zeilen darüber.'
                            : '.'));
        }

        return $totals;
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
            // DIESELBE Quelle wie die Tabelle (conversionOf), nicht dieselbe
            // Rechnung noch einmal: null heisst „keine Bewerbungen, also keine
            // Quote" — nicht 0 %. Vorher stand hier `$total > 0 ? … : 0`, und die
            // Kachel zeigte in einer leeren Auswahl 0 %, waehrend die Gesamt-Zeile
            // der Tabelle daneben „–" zeigte. Zwei Zahlen fuer dieselbe Frage, und
            // die falsche behauptete, es sei etwas gescheitert.
            'conversion' => $this->viewModel()->conversionOf($c['rows']),
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
     * $scope: 'posting' (alle Zeilen EINER Ausschreibung, die Zeilen-Einheit der
     *         Ausschreibungs-Tabelle und der beiden Bloecke)
     *         | 'interviews' (alle Zeilen der angegebenen TERMINE — die
     *         Zeilen-Einheit der Termin-Tabelle, mit einem Eintrag fuer eine
     *         Termin-Zeile und allen sichtbaren fuer ihre Gesamt-Zeile)
     *         | 'interviews_posting' (eine Herkunfts-Unterzeile: Termin und
     *         Ausschreibung) | 'type_all' (ein Zeilentyp ueber alle Gruppen)
     *         | 'all' (Gesamt)
     *
     * $extra reist unveraendert durch (encodeScope hier, decodeScope in drill(),
     * von dort direkt in resolveIds) — Hin- und Rueckweg sind also fuer alle
     * Bestandteile derselbe, es gibt keine Feld-Liste, die man vergessen koennte.
     * Fuer die Scopes 'posting' und 'interviews_posting' ist 'posting' =>
     * $row['posting_id'] PFLICHT: die Zeilen sind je Ausschreibung, ohne die Angabe
     * traefe der Zuschnitt mehrere. Fehlt sie, loest resolveIds fail-closed nichts
     * auf (leeres Modal statt vermischter IDs). Fuer die Termin-Scopes gilt dasselbe
     * fuer 'interviews' => list<int>.
     *
     * $extra kennt zusaetzlich 'set' => 'closed' | 'unreachable' | 'unknown_origin'
     * (die drei Bloecke unter den Tabellen): das waehlt nicht den Zuschnitt,
     * sondern die ZEILENMENGE, gegen die drill() aufloest — siehe dort. Ohne den
     * Schluessel ist es die Auswahl der Seite.
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

        // WELCHE Zeilenmenge das Token benennt. Standard ist die Auswahl der Seite
        // — dieselbe Menge, aus der die angeklickte Zahl gerechnet wurde, sonst
        // passte die Modal-Laenge nicht zur Zahl daneben.
        //
        // 'closed', 'unreachable' und 'unknown_origin' sind die drei beiseite-
        // gelegten Mengen der Bloecke unter den Tabellen: sie stehen absichtlich
        // NICHT in der Auswahl (Status-Filter, Filial-Filter bzw. gar keiner
        // Auswahl zugehoerig), ihre Zahlen muessen aber anklickbar sein. Ein
        // unbekannter Wert faellt auf die Auswahl zurueck; alle vier Mengen
        // stammen aus derselben team-gescopten Kohorte, ein gecraftetes 'set'
        // oeffnet also nichts, was die Seite nicht ohnehin zeigt.
        //
        // Termin-Scopes ('interviews', 'interviews_posting') zeigen auf die
        // UNGEFILTERTE Termin-Menge: die Zahlen von Tabelle 2 sind daraus
        // gerechnet (buildInterviewTable), und ein Klick auf die 16 muss 16
        // Personen oeffnen — nicht die 11, die zufaellig zur Filiale gehoeren.
        $rows = match ($spec['set'] ?? null) {
            'closed' => $this->cohort['closed_rows'],
            'unreachable' => $this->cohort['unreachable_rows'],
            'unknown_origin' => $this->cohort['unknown_origin_rows'],
            default => in_array($spec['scope'] ?? null, ['interviews', 'interviews_posting'], true)
                ? $this->cohort['termin_rows']
                : $this->cohort['rows'],
        };

        $this->drillIds = $vm->resolveIdsFromClient($rows, $spec, $column);

        $this->drillScopeType = (string) ($spec['type'] ?? '');
        $this->drillScopeName = (string) ($spec['scope'] ?? '');
        $this->drillHasSet = array_key_exists('set', $spec);
        $this->campaignSelection = [];
        $this->campaignUuid = null;
        $this->campaignError = '';
        if ($this->campaignEnabled()) {
            // Vorauswahl aus der Segmentregel; Template-Defaults aus den Settings.
            foreach ($this->campaignRows as $id => $row) {
                $this->campaignSelection[$id] = $row['checked'];
            }
            $settings = RecApplicantSettings::getOrCreateForTeam($this->teamId());
            $this->campaignTemplateA = $this->campaignTemplateA ?: (int) ($settings->getSetting('campaign_form_wa_template_id') ?? 0) ?: null;
            $this->campaignTemplateB = $this->campaignTemplateB ?: (int) ($settings->getSetting('campaign_booking_wa_template_id') ?? 0) ?: null;
        }

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
        // forTeam bleibt Pflicht, auch mit #[Locked] auf $drillIds: der Lock
        // schliesst den direkten Weg (Client setzt die ID-Liste), der Scope den
        // indirekten (ein Token benennt eine Menge, die die Kohorte nicht
        // enthaelt). Zwei Schloesser fuer denselben Namens-Leak — das aeussere
        // wegzulassen, weil das innere haelt, ist genau die Art Sparsamkeit, die
        // sich beim naechsten Umbau raecht.
        return RecApplicant::forTeam($this->teamId())
            ->whereIn('id', $this->drillIds)
            ->with('crmContactLinks.contact')
            ->orderBy('id')
            ->get();
    }

    /**
     * Drei Locked-Felder muessen zusammenpassen, keins allein reicht:
     *  - drillScopeName === 'type_all' UND drillScopeType === 'ohne_schulung':
     *    der einzige Token in der View mit dieser Kombination ist die Kachel
     *    "Ohne Termin" (index.blade.php:215).
     *  - !drillHasSet: ein 'set'-Schluessel im Token (closed/unreachable/
     *    unknown_origin) redirigiert die ID-Aufloesung in drill() auf eine
     *    der drei beiseite gelegten Mengen — disjunkt von dem, was die Kachel
     *    zeigt — unabhaengig davon, was 'scope'/'type' im selben Token sagen.
     */
    public function campaignEnabled(): bool
    {
        return !$this->drillHasSet
            && $this->drillScopeName === 'type_all'
            && $this->drillScopeType === 'ohne_schulung'
            && $this->drillIds !== [];
    }

    /**
     * Zeilen der Kampagne — Loader buendelt die Queries (Query-Budget §2).
     * Schluessel applicant_id, Reihenfolge wie $drillIds.
     *
     * @return array<int, array{applicant_id:int, name:string, applied_at:?string, phase:string, template:string, selectable:bool, checked:bool, badges:list<string>}>
     */
    #[Computed]
    public function campaignRows(): array
    {
        if (!$this->campaignEnabled()) {
            return [];
        }

        return app(NewDatesCampaignRecipients::class)->load($this->teamId(), $this->drillIds, new \DateTimeImmutable());
    }

    /** @return list<array{id:int,label:string}> approved Templates des Teams (Muster ApplicantSettingsModal) */
    #[Computed]
    public function campaignTemplates(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }
        $accountId = RecApplicantSettings::getOrCreateForTeam($this->teamId())->getSetting('auto_pilot_wa_account_id');

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->where('status', 'APPROVED')
            ->when($accountId, fn ($q) => $q->where('whatsapp_account_id', (int) $accountId))
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => ['id' => (int) $t->id, 'label' => "{$t->name} ({$t->language})"])
            ->values()
            ->all();
    }

    #[Computed]
    public function campaignProgress(): ?array
    {
        if ($this->campaignUuid === null) {
            return null;
        }

        return Cache::get(SendNewDatesCampaign::cacheKey($this->campaignUuid));
    }

    public function campaignSelectAll(bool $on): void
    {
        foreach ($this->campaignRows as $id => $row) {
            $this->campaignSelection[$id] = $on && $row['selectable'];
        }
    }

    /** @return list<int> */
    public function campaignSelectedIds(): array
    {
        $selectable = array_keys(array_filter($this->campaignRows, fn ($r) => $r['selectable']));

        return CampaignSegment::selectedIds($this->campaignSelection, $this->drillIds, $selectable);
    }

    /**
     * Anzahl der gewaehlten Personen pro Template — fuer die Button-Sperre und
     * den Zaehler. Die reine Zaehlung (kein Container, keine Property) sitzt
     * in CampaignSegment::countsByTemplate — dort auch getestet.
     */
    public function campaignCounts(): array
    {
        return CampaignSegment::countsByTemplate($this->campaignRows, $this->campaignSelectedIds());
    }

    /**
     * Reine Guard-Kette fuer den Start-Button, pro Zeile eine Ablehnung, in
     * genau dieser Reihenfolge geprueft. Container-frei (kein $this), damit
     * die volle Kette ohne Component/DB testbar ist (Muster CampaignSegment).
     *
     * @param array{A:int,B:int,total:int} $counts
     */
    public static function campaignStartError(bool $enabled, bool $alreadyStarted, array $counts, ?int $templateA, ?int $templateB): ?string
    {
        if (!$enabled) {
            return 'Kampagne nicht verfügbar.';
        }
        if ($alreadyStarted) {
            return 'Kampagne läuft bereits.';
        }
        if (($counts['total'] ?? 0) === 0) {
            return 'Niemand ausgewählt.';
        }
        if (($counts['A'] ?? 0) > 0 && !$templateA) {
            return "Für {$counts['A']} Personen fehlt Template A (Bewerbung vervollständigen).";
        }
        if (($counts['B'] ?? 0) > 0 && !$templateB) {
            return "Für {$counts['B']} Personen fehlt Template B (Terminauswahl).";
        }

        return null;
    }

    public function startCampaign(): void
    {
        $this->campaignError = '';
        $ids = $this->campaignSelectedIds();
        $counts = $this->campaignCounts();

        $error = self::campaignStartError($this->campaignEnabled(), $this->campaignUuid !== null, $counts, $this->campaignTemplateA, $this->campaignTemplateB);
        if ($error !== null) {
            $this->campaignError = $error;
            return;
        }

        $uuid = (string) Str::uuid();
        Cache::put(SendNewDatesCampaign::cacheKey($uuid), SendNewDatesCampaign::initialProgress(count($ids)), SendNewDatesCampaign::CACHE_TTL_SECONDS);
        SendNewDatesCampaign::dispatch(
            $uuid,
            $this->teamId(),
            auth()->id(),
            $ids,
            $counts['A'] > 0 ? (int) $this->campaignTemplateA : null,
            $counts['B'] > 0 ? (int) $this->campaignTemplateB : null,
        );
        $this->campaignUuid = $uuid;
    }

    /**
     * Die waehlbaren Filialen — und damit mehr als eine Select-Liste: cohort()
     * entscheidet an derselben Liste, welche Zeilen ueber KEINE Auswahl erreichbar
     * sind (Block „Ohne Filial-Zuordnung"), und mount() nimmt den ersten Eintrag
     * als Vorbelegung.
     *
     * Eigener Cache, obwohl #[Computed]: die Liste wird pro Request aus drei
     * Richtungen gelesen (View als Property, cohort() und mount() als Methode),
     * und der Livewire-Cache greift nur beim Property-Zugriff. Ohne das Feld waere
     * dieselbe Liste drei Queries — Query-Budget ist Abnahmekriterium §2.
     *
     * @return array<string,string>
     */
    #[Computed]
    public function ortOptions(): array
    {
        return $this->ortOptionsCache ??= RecPosition::forTeam($this->teamId())
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
     * geschlossener Ausschreibungen nicht mehr filterbar. Nicht online sind
     * gekennzeichnet, damit die Liste ehrlich bleibt.
     *
     * Die Kennzeichnung liest dieselbe EINE Definition wie das Zeilen-Flag und der
     * Status-Filter (RecPosting::isOnline). Vorher stand hier nur „(inaktiv)" nach
     * is_active — eine ENTWURFS-Ausschreibung sah damit online aus, waehrend die
     * Tabelle sie als geschlossen fuehrte: zwei Lesarten desselben Zustands in
     * derselben Filterleiste.
     *
     * @return array<int,string>
     */
    #[Computed]
    public function postingOptions(): array
    {
        // Die Liste FOLGT den beiden Filtern links von ihr. Vorher standen hier
        // alle Ausschreibungen des Teams: bei Filiale „Duesseldorf" und Status
        // „nur online" waren also Moenchengladbach- und offline-Ausschreibungen
        // waehlbar, deren Auswahl die Tabellen dann leer gezeigt haette. Eine
        // Auswahlliste, die mehr anbietet als die Ansicht enthaelt, ist genau die
        // Sorte Widerspruch, gegen die diese Seite gebaut ist.
        //
        // Ohne gewaehlte Filiale gibt es nichts zu waehlen — die Seite zeigt dort
        // ohnehin die Aufforderung statt einer Tabelle.
        if (! $this->hasOrt()) {
            return [];
        }

        // Der Ort haengt an der STELLE, nicht an der Ausschreibung — dieselbe
        // Herleitung wie in cohort() und in phaseLabels(), damit die drei nicht
        // auseinanderlaufen koennen.
        $postings = RecPosting::forTeam($this->teamId())
            ->whereHas('position', fn ($q) => $q->where('location', $this->ortFilter))
            ->orderBy('title')
            ->get(['id', 'title', 'status', 'is_active', 'rec_position_id']);

        // „online" wird auch hier nicht neu hergeleitet, sondern ueber
        // RecPosting::isOnline() gelesen — dieselbe Quelle wie posting_closed.
        if ($this->postingStatusFilter !== 'alle') {
            $postings = $postings->filter(fn ($p) => $p->isOnline());
        }

        // Der Zusatz bleibt fuer den Fall „alle": dort stehen offene und
        // geschlossene nebeneinander und muessen unterscheidbar sein.
        return $postings->mapWithKeys(fn ($p) => [
            $p->id => $p->title . ($p->isOnline() ? '' : ' (nicht online)'),
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
