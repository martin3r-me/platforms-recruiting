{{--
    Statistik-Seite (Kundenansicht). Diese Datei setzt nur ZUSAMMEN — gerechnet
    wird in der Livewire-Komponente, gerendert in den beiden Tabellen-Partials:

      1. Filterleiste — Filiale (PFLICHT), Status der Ausschreibungen, Tätigkeit,
         Ausschreibung, Quelle und der Termin-Zeitraum.
      2. KPI-Kacheln — lesen ausschliesslich aus dem Kohorten-Ergebnis.
      3. Tabelle 1 (Ausschreibungen) und Tabelle 2 (Schulungstermine), je ein
         fertiger Abschnitt mit eigenem Panel.
      4. Fünf Blöcke: Ausgeschieden, Geschlossene Ausschreibungen, Ohne
         Filial-Zuordnung, Herkunft unbekannt, Rekonziliation.
      5. Drill-down-Modal.

    DIE BLÖCKE SIND KEIN ANHANG. Gemessen zeigte die Seite bei einer Filiale mit
    Status „online“ eine von sieben Bewerbungen — die anderen sechs steckten in
    geschlossenen Ausschreibungen, an Stellen ohne gepflegten Standort oder an
    keiner Ausschreibung, und der Rekonziliations-Hinweis schwieg dazu (er rechnet
    innerhalb der Auswahl, siehe Block 5). Blöcke 2, 3 und 4 benennen genau diese
    Mengen; zusammen mit den Tabellen erfassen sie jede Bewerbung des Teams genau
    einmal (Test: StatisticsPageReconciliationTest).

    Block 4 (Task 10, „Herkunft unbekannt“) ist ein SONDERFALL von Block 3: eine
    als Stellenwechsel markierte Verknüpfung (matched_via = 'position_switch',
    rein historisch — rund 15 Altfälle, kein wachsender Topf) sieht der Assigner
    genauso an wie eine Bewerbung ganz ohne Ausschreibung und würde ohne
    Sonderbehandlung in Block 3 landen. Index::cohort() schließt diese
    Bewerbungen dort ausdrücklich aus, damit sie NUR in Block 4 stehen — sonst
    zählte eine Bewerbung doppelt.

    DIE FILIALE IST PFLICHT, und das ist keine Kosmetik: phaseLabels() liest den
    Phasensatz der gefilterten Filiale, und `where('location', null)` macht Laravel
    zu einem `whereNull` — die Spaltenköpfe kämen dann aus dem Phasensatz ORTLOSER
    Stellen über Zahlen aller Orte. Hinter dem Guard `$this->hasOrt()` stehen
    deshalb die KACHELN und die beiden TABELLEN; ohne Filiale steht dort eine
    Aufforderung. Die fünf Blöcke stehen AUSSERHALB des Guards — genau ohne
    Filialauswahl steckt jede Bewerbung in Block 2, 3 oder 4, dort ist die
    Erklärung also am nötigsten (Begründung an der Stelle).

    Die alte Kohorten-Tabelle (Baum Ort → Tätigkeit → Zeilentyp) ist ERSETZT
    (Kunden-Entscheidung); mit ihr sind das Computed `groups()`, `interviewMeta()`
    und `interviewIdOf()` entfallen.
--}}
@php
    // Alles hier oben ist unabhaengig von der Kohorte: die Filterleiste rendert
    // auch dann, wenn es nichts zu rechnen gibt. (Die Kohorte selbst kostet mit und
    // ohne Filialauswahl dasselbe — cohort() laedt die Bewerber des Teams, der
    // Filial-Filter greift danach in PHP.)
    $statusOptions = [
        'online' => 'nur online',
        'alle' => 'alle (auch geschlossene)',
    ];

    // Der Zeitraum der Seite ist das TERMINDATUM (Tabelle 2), nicht das
    // Bewerbungsdatum — der frühere Bewerbungs-Zeitraum ist mit dieser Ansicht
    // entfallen. Die Beschriftung sagt das auch, sonst liest man die Kachel
    // „Bewerbungen“ als „Bewerbungen im Zeitraum“.
    $rangeSubtitle = ($this->interviewFrom || $this->interviewTo)
        ? trim(($this->interviewFrom ? \Carbon\Carbon::parse($this->interviewFrom)->format('d.m.Y') : '…')
            . ' – ' . ($this->interviewTo ? \Carbon\Carbon::parse($this->interviewTo)->format('d.m.Y') : '…'))
        : 'alle Termine';

    $filterNote = 'Der Zeitraum filtert die TERMINE (Tabelle „Schulungstermine“), nicht den Bewerbungseingang: '
        . 'eine Ausschreibung hat ein Ziel, ein Termin hat einen Zeitpunkt. Tabelle 1 und die Kacheln zeigen '
        . 'deshalb alle Bewerbungen der gewählten Filiale. Testbewerber sind immer ausgeschlossen.';

    // Die Property ist untypisiert (Livewire-Hydrierung von ''), ein gecrafteter
    // Snapshot koennte also auch ein Array tragen — als Array-Schluessel waere das
    // ein 500er. Deshalb NICHT direkt indizieren, sondern erst auf einen bekannten
    // Schluessel bringen. Die Filter-Logik selbst prueft `!== 'alle'` und ist damit
    // ohnehin unempfindlich.
    $statusKey = (is_string($this->postingStatusFilter) && isset($statusOptions[$this->postingStatusFilter]))
        ? $this->postingStatusFilter
        : 'online';

    $statusNote = '„Online“ heißt veröffentlicht UND aktiv. Alles andere (Entwurf, archiviert, deaktiviert) gilt '
        . 'als geschlossen — ein abgelaufenes Laufzeitende allein nicht. Geschlossene Ausschreibungen stehen im '
        . 'Block unter den Tabellen, auch wenn sie hier ausgefiltert sind. Die Auswahl „Einzelne Ausschreibung“ '
        . 'folgt diesem Status und der gewählten Filiale — dort steht nur, was in dieser Ansicht auch vorkommt.';
@endphp

<div class="space-y-6 p-6">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Filterleiste                                                       --}}
    {{-- ------------------------------------------------------------------ --}}
    {{-- relative z-30: hebt die Filterleiste (inkl. der absolut positionierten
         Select-Dropdowns) ueber die KPI-Kacheln darunter. Ohne eigenen
         Stacking-Context wird das z-50 der Dropdowns von spaeteren Siblings
         uebermalt (Overlay-Bug, Live-Check 2026-08-04). --}}
    <x-ui-panel title="Statistik" subtitle="Eine Filiale, zwei Tabellen — jede Zahl ist anklickbar und zeigt die Personen dahinter" class="relative z-30">
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
            {{-- PFLICHTAUSWAHL: KEIN nullable-Attribut und kein nullLabel — „alle
                 Orte“ gibt es nicht mehr, die Seite zeigt immer genau eine Filiale
                 (siehe Kopfkommentar). `nullable` ist in x-ui-input-select ohnehin
                 standardmaessig false; ein ausdrueckliches :nullable="false" waere
                 nur Rauschen an der Stelle, an der die Absicht steht. --}}
            <x-ui-input-select
                name="ortFilter"
                label="Filiale"
                hint="Pflichtauswahl"
                size="sm"
                :options="$this->ortOptions"
                :required="true"
                wire:model.live="ortFilter"
            />
            <x-ui-input-select
                name="postingStatusFilter"
                label="Status"
                size="sm"
                :options="$statusOptions"
                wire:model.live="postingStatusFilter"
            />
            <x-ui-input-select
                name="activityFilter"
                label="Tätigkeit"
                size="sm"
                :options="$this->activityOptions"
                :nullable="true"
                nullLabel="alle Tätigkeiten"
                wire:model.live="activityFilter"
            />
            <x-ui-input-select
                name="postingFilter"
                label="Einzelne Ausschreibung"
                size="sm"
                :options="$this->postingOptions"
                :nullable="true"
                nullLabel="alle Ausschreibungen"
                wire:model.live="postingFilter"
            />
            <x-ui-input-select
                name="sourcePlatformFilter"
                label="Quelle"
                size="sm"
                :options="$this->sourceOptions"
                :nullable="true"
                nullLabel="alle Quellen"
                wire:model.live="sourcePlatformFilter"
            />
            {{-- Zwei Datumsfelder auf EINER Rasterspalte: sie gehoeren zusammen
                 (ein Zeitraum) und stehen deshalb nicht getrennt in der Reihe. --}}
            <div class="grid grid-cols-2 gap-2">
                {{-- Y-m-d-STRING-Properties, nie ein datetime-Cast: x-ui-input-date
                     per wire:model an einen Cast zu binden ist eine bekannte Falle
                     dieses Projekts. --}}
                <x-ui-input-date name="interviewFrom" label="Termine von" size="sm" wire:model.live="interviewFrom" />
                <x-ui-input-date name="interviewTo" label="bis" size="sm" wire:model.live="interviewTo" />
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            {{-- Kleingedrucktes zusammengefaltet: Zeitraum und Status bleiben
                 sichtbar, die Regeln dahinter stehen im Tooltip. --}}
            <div class="text-xs text-[color:var(--ui-muted)]">
                Termin-Zeitraum: {{ $rangeSubtitle }}
                <span class="ml-1 cursor-help" title="{{ $filterNote }}">ⓘ</span>
                <span class="mx-2 text-[color:var(--ui-border)]">·</span>
                Ausschreibungen: {{ $statusOptions[$statusKey] }}
                <span class="ml-1 cursor-help" title="{{ $statusNote }}">ⓘ</span>
            </div>
            <x-ui-button variant="secondary-outline" size="sm" wire:click="resetFilters">
                Filter zurücksetzen
            </x-ui-button>
        </div>
    </x-ui-panel>

    @if (!$this->hasOrt())
        {{-- KEINE Kacheln und KEINE Tabellen ohne Filiale (Guard, siehe
             Kopfkommentar): die Phasen-Spaltenkoepfe kaemen sonst aus dem
             Phasensatz ortloser Stellen ueber Zahlen aller Orte. Die fuenf
             Bloecke unter dieser Meldung rendern trotzdem — sie brauchen
             phaseLabels() nicht und benennen, wo die Bewerbungen stecken. --}}
        <x-ui-panel title="Filiale wählen">
            @if ($this->ortOptions === [])
                <div class="py-8 text-center text-sm text-[color:var(--ui-muted)]">
                    An keiner Stelle ist ein Standort gepflegt — deshalb gibt es hier nichts zu wählen.
                    <div class="mt-2 text-xs">
                        Diese Seite zeigt die Zahlen einer Filiale. Der Standort hängt an der STELLE
                        (nicht an der Ausschreibung); sobald er dort eingetragen ist, erscheint die Filiale in der Auswahl.
                        Wo die Bewerbungen bis dahin stecken, sagen die Blöcke unter dieser Meldung.
                    </div>
                </div>
            @else
                {{-- SICHERHEITSNETZ, kein Regelfall: mit gepflegten Standorten belegt
                     mount() die Pflichtauswahl, dieser Zweig ist also normalerweise
                     nicht erreichbar. NICHT LOESCHEN — er faengt die Zustaende, in
                     denen die Vorbelegung nicht gegriffen hat (Livewire-Hydrierung
                     mit leerem Ort, ein Ort, der zwischen zwei Requests
                     verschwindet). Ohne ihn stuende an dieser Stelle eine leere
                     Seite ohne Erklaerung, und phaseLabels() liefe in den
                     whereNull-Fall. --}}
                <div class="py-8 text-center text-sm text-[color:var(--ui-muted)]">
                    Bitte oben eine Filiale wählen.
                    <div class="mt-2 text-xs">
                        Die Auswahl ist Pflicht: die Spaltenköpfe des Trichters kommen aus dem Phasensatz der
                        gewählten Filiale, und Phasen sind pro Stelle geklont und frei benannt. Ohne Filiale
                        stünden Überschriften einer Filiale über den Zahlen aller.
                        Die Blöcke unter dieser Meldung zeigen trotzdem, wo die Bewerbungen stecken.
                    </div>
                </div>
            @endif
        </x-ui-panel>
    @else
        @php
            $tiles = $this->tiles;

            // Right-Censoring auf der Kachel: die Regel waehlt das ViewModel
            // anhand der Zeilenmenge (isCensoredForRows) — bei mehreren Zeilen
            // also die Aggregat-Regel „grau nur, wenn JEDE Zeile zu jung ist“.
            // Vorher war die Kachel dauerhaft grau, weil in einer Gesamtsicht
            // immer eine Bewerbung von heute dabei ist — ein Zustand, der nie
            // wechselt, liest sich als Fehler.
            $overallCensored = $this->isCensored($this->cohort['rows']);

            $allToken = $this->drillToken('all', 'Gesamt');
            $ohneTerminToken = $this->drillToken('type_all', 'Ohne Termin', ['type' => 'ohne_schulung']);

            $kpis = [
                ['label' => 'Bewerbungen', 'value' => $tiles['bewerbungen'], 'column' => 'ids', 'token' => $allToken,
                 'muted' => false, 'title' => 'Alle Bewerbungen der aktuellen Auswahl.'],
                ['label' => 'In Schulung gebucht', 'value' => $tiles['gebucht'], 'column' => 'gebucht', 'token' => $allToken,
                 'muted' => false, 'title' => 'Bewerbungen mit einer Buchung auf einem Schulungstermin.'],
                // Die Antwort auf „wo hängen die restlichen fest“ — vorher nur als
                // Nebenzeile in der Tabelle zu finden.
                ['label' => 'Ohne Termin', 'value' => $tiles['ohne_termin'], 'column' => 'ids', 'token' => $ohneTerminToken,
                 'muted' => false, 'title' => 'Aktive Bewerbungen ohne Schulungstermin — in der Ausschreibungs-Tabelle stecken sie in den Phasen-Spalten.'],
                ['label' => 'Unterschriften', 'value' => $tiles['unterschrieben'], 'column' => 'unterschrieben', 'token' => $allToken,
                 'muted' => false, 'title' => 'Bewerbungen mit mindestens einem unterschriebenen Vertrag.'],
                // „–“ statt „0 %“, wenn es keine Bewerbungen gibt: die Kachel liest
                // dieselbe Quelle wie die Gesamt-Zeile der Tabelle (conversionOf),
                // und dort heißt null „keine Quote“. 0 % hätte behauptet, es sei
                // etwas gescheitert.
                ['label' => 'Conversion',
                 'value' => $tiles['conversion'] !== null ? $tiles['conversion'] . ' %' : '–',
                 'column' => null, 'token' => null,
                 'muted' => $overallCensored || $tiles['conversion'] === null,
                 'title' => $tiles['conversion'] === null
                    ? 'Keine Bewerbungen in dieser Auswahl — keine Quote (auch keine 0 %).'
                    : ($overallCensored
                        ? $this->censorNote()
                        : 'Unterschriften geteilt durch alle Bewerbungen der Auswahl. Enthält auch sehr junge Bewerbungen, die noch keine Zeit zur Unterschrift hatten — bei einer Auswahl über viele Zeiträume fällt das kaum ins Gewicht, bei einer einzelnen frischen Schulung schon (dort ist die Quote in der Tabelle ausgegraut).')],
                ['label' => 'Time-to-Hire (Median)', 'value' => $tiles['tth_median'] !== null ? $tiles['tth_median'] . ' Tage' : '–',
                 'column' => null, 'token' => null, 'muted' => false,
                 'title' => 'Median der Tage von Bewerbungseingang bis Unterschrift. Ist zugleich die Schwelle, ab der eine Kohorte als reif gilt und ihre Quote nicht mehr ausgegraut wird.'],
            ];
        @endphp

        {{-- ------------------------------------------------------------------ --}}
        {{-- KPI-Kacheln — lesen ausschliesslich aus dem Kohorten-Ergebnis      --}}
        {{-- ------------------------------------------------------------------ --}}
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
            @foreach ($kpis as $kpi)
                <x-ui-panel>
                    @if ($kpi['column'] !== null)
                        {{-- @js statt nackter Anfuehrungszeichen: hier sind die drei
                             Argumente Konstanten, aber die Regel gilt fuer JEDEN
                             wire:click dieser Seite — ein Apostroph im Argument
                             zerlegt den Ausdruck, und die Ausnahme von der Regel
                             ist die Stelle, an der sie beim naechsten Umbau
                             uebersehen wird. --}}
                        <button
                            type="button"
                            wire:click="drill(@js($kpi['token']), @js($kpi['column']), @js($kpi['label']))"
                            wire:loading.attr="disabled"
                            class="text-2xl font-semibold text-[color:var(--ui-secondary)] hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] rounded cursor-pointer"
                            title="{{ $kpi['title'] }} — klicken zeigt die Personen."
                        >{{ $kpi['value'] }}</button>
                    @else
                        {{-- gleiche Ausgrau-Optik wie die Conversion-Zelle der Tabelle --}}
                        <div class="text-2xl font-semibold {{ $kpi['muted'] ? 'text-gray-400 italic' : 'text-[color:var(--ui-secondary)]' }}"
                             title="{{ $kpi['title'] }}">{{ $kpi['value'] }}</div>
                    @endif
                    <div class="text-sm text-[color:var(--ui-muted)]">
                        {{ $kpi['label'] }}
                        <span class="cursor-help" title="{{ $kpi['title'] }}">ⓘ</span>
                    </div>
                </x-ui-panel>
            @endforeach
        </div>

        {{-- ------------------------------------------------------------------ --}}
        {{-- Tabelle 1: eine Zeile je Ausschreibung                             --}}
        {{-- Tabelle 2: eine Zeile je Schulungstermin                           --}}
        {{-- Beide bringen ihr eigenes Panel mit und zaehlen ueber DIESELBEN     --}}
        {{-- Assigner-Zeilen — es gibt keine zweite Zaehlung derselben Menschen. --}}
        {{-- ------------------------------------------------------------------ --}}
        @include('recruiting::livewire.statistics.postings-table')
        @include('recruiting::livewire.statistics.interviews-table')

    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- DIE FÜNF BLÖCKE STEHEN AUSSERHALB DES FILIAL-GUARDS.                --}}
    {{--                                                                    --}}
    {{-- Genau dann, wenn keine Filiale gewählt ist (oder gar keine gepflegt --}}
    {{-- ist), steckt JEDE Bewerbung in Block 2, 3 oder 4 — dort ist die     --}}
    {{-- Erklärung also am nötigsten. Stünden die Blöcke im @else, zeigte    --}}
    {{-- die Seite in diesem Zustand ausschließlich die Aufforderung, und    --}}
    {{-- die Regel „was aus der Ansicht fällt, wird benannt“ gälte gerade    --}}
    {{-- dort nicht, wo alles herausfällt.                                  --}}
    {{--                                                                    --}}
    {{-- Sie brauchen phaseLabels() nicht (das ist der Grund für den Guard)  --}}
    {{-- und kosten auch keine zusätzliche Query: cohort() lädt die Bewerber --}}
    {{-- des Teams ohnehin, der Filial-Filter greift danach in PHP.         --}}
    {{-- ------------------------------------------------------------------ --}}
    @php
        // EINSCHRÄNKENDE FILTER: Tätigkeit, Ausschreibung und Quelle wirken auch
        // auf die Blöcke (Ausschreibung und Quelle sogar in der Query). Solange
        // einer davon aktiv ist, darf KEIN Blocktext Vollständigkeit behaupten —
        // sonst liest man eine leergeräumte Liste als „da ist nichts“.
        //
        // Der FILIAL-Filter zählt hier absichtlich nicht mit: er ist der einzige,
        // den die Blöcke ignorieren (siehe cohort()).
        $narrowed = $this->activityFilter !== null
            || $this->postingFilter !== null
            || $this->sourcePlatformFilter !== null;

        $narrowNote = $narrowed
            ? ' Die Liste folgt der aktuellen Auswahl (Tätigkeit, Ausschreibung, Quelle) und ist deshalb '
                . 'NICHT vollständig.'
            : '';

        // Der STATUS-Filter schneidet ZUSÄTZLICH — aber nur die AUSWAHL (Block 1
        // liest cohort()['rows']), nicht die drei Ablage-Blöcke: die legt cohort()
        // vor dem Status-Filter beiseite und sie sind unabhängig von ihm vollständig.
        // Deshalb zwei Flags statt einem:
        //   $narrowed          → Blöcke 2, 3 und 4 (Tätigkeit/Ausschreibung/Quelle),
        //   $auswahlBeschnitten → Block 1 (dieselben plus Status).
        // Ein gemeinsames Flag hätte die Blöcke 2–4 im Standardzustand („online“)
        // dauerhaft als unvollständig ausgewiesen, obwohl sie es nicht sind — und
        // eine Warnung, die immer steht, liest niemand mehr.
        // Aufgezählt werden die TATSÄCHLICH aktiven Dimensionen, und die FILIALE
        // gehört dazu: sie schneidet Block 1 genauso (gemessen 7 → 4). Eine feste
        // Liste hätte sie ausgelassen — und bei Filiale plus Status „alle“ hätte
        // gar kein Zusatz gestanden, obwohl die Zahlen dann geschnitten sind.
        $auswahlFilter = [];
        if ($this->hasOrt()) {
            $auswahlFilter[] = 'Filiale';
        }
        if ($this->postingStatusFilter !== 'alle') {
            $auswahlFilter[] = 'Status';
        }
        if ($this->activityFilter !== null) {
            $auswahlFilter[] = 'Tätigkeit';
        }
        if ($this->postingFilter !== null) {
            $auswahlFilter[] = 'Ausschreibung';
        }
        if ($this->sourcePlatformFilter !== null) {
            $auswahlFilter[] = 'Quelle';
        }

        $auswahlBeschnitten = $auswahlFilter !== [];

        $auswahlNote = $auswahlBeschnitten
            ? ' Die Zahlen folgen der aktuellen Auswahl (' . implode(', ', $auswahlFilter) . ') — '
                . 'im Team können es mehr sein.'
            : '';

        // ---------------------------------------------------------------
        // Block 1: Ausgeschieden
        // ---------------------------------------------------------------
        // Diese Zeilentypen sind fuer die KPIs nicht direkt relevant, muessen
        // aber SICHTBAR sein: sonst ist die Differenz zwischen „Bewerbungen“
        // und dem laufenden Trichter nicht benannt, und genau solche stillen
        // Differenzen sind der Grund fuer diese Seite.
        //
        // Sie sind in den Zahlen der Tabellen oben ENTHALTEN (jede Bewerbung
        // steckt in genau einer Assigner-Zeile, und Tabelle 1 summiert je
        // Ausschreibung ueber alle Zeilentypen) — dieser Block schluesselt sie
        // nur auf, er zieht nichts ab.
        $excludedTypes = [
            'geparkt' => ['label' => 'Geparkt',
                'title' => 'Bewerbung ist geparkt: sie ruht, ist aber nicht abgesagt.'],
            'abgesagt' => ['label' => 'Abgesagt',
                'title' => 'Absage ist gesetzt (rejected_at).'],
            'dublette' => ['label' => 'Dubletten',
                'title' => 'Als Doppelung eines anderen Datensatzes markiert — zählt nur einmal, nämlich hier.'],
            'unrouted' => ['label' => 'Nicht zugeordnet (Eingang)',
                'title' => 'Liegt im Eingang und ist keiner Stelle zugeordnet.'],
            'ohne_datum' => ['label' => 'Ohne Bewerbungsdatum',
                'title' => 'Kein Bewerbungsdatum gepflegt (Stufe 2 der Präzedenz-Kette). Eigene Zeile, damit die Summe vollständig bleibt — der Zeitraum dieser Seite filtert Termine, nicht Bewerbungen.'],
            'unbekannter_status' => ['label' => 'Unbekannter Buchungsstatus',
                'title' => 'Buchung mit einem Status, den die Zählregel nicht kennt — bewusst sichtbar statt verschluckt. Bitte prüfen.'],
        ];

        $excludedRows = [];
        $excludedTotal = 0;
        foreach ($excludedTypes as $type => $meta) {
            $typeRows = array_values(array_filter(
                $this->cohort['rows'],
                fn ($r) => $r['type'] === $type,
            ));
            $count = $this->countIn($typeRows, 'ids');
            $excludedTotal += $count;
            $excludedRows[] = [
                'label' => $meta['label'],
                'title' => $meta['title'],
                'count' => $count,
                // 'type_all' = ein Zeilentyp ueber alle Gruppen der Auswahl,
                // derselbe Scope wie die Kachel „Ohne Termin“. Kein neuer
                // Scope: resolveIds kennt ihn, und ein erfundener traefe
                // fail-closed nichts.
                'token' => $this->drillToken('type_all', $meta['label'], ['type' => $type]),
            ];
        }

        // ---------------------------------------------------------------
        // Blöcke 2, 3 und 4: die drei Mengen, die aus der Auswahl fallen
        // ---------------------------------------------------------------
        // Alle drei Listen sind gleich gebaut (eine Zeile je Ausschreibung, mit
        // Ort und Drill-down) und werden deshalb von EINEM Bauplan erzeugt: drei
        // Kopien desselben Markups liefen beim nächsten Umbau auseinander, und
        // gerade diese drei Blöcke müssen sich wie eine Lesart lesen.
        //
        // Das Token trägt zwei Angaben, beide Pflicht: 'posting' ist der
        // Zuschnitt (ohne ihn trifft der Scope fail-closed nichts), 'set' die
        // ZEILENMENGE. Ohne 'set' löste der Klick gegen die Auswahl auf — und
        // dort stehen diese Zeilen gerade nicht.
        //
        // $nullTitle ist NUR fürs Label einer posting_id===null-Zeile ein
        // Parameter, weil dieselbe Form zwei verschiedene Befunde trägt: „ohne
        // Ausschreibung“ heißt in Block 3 wörtlich das (Fall 3 der
        // Zuordnungsregel), in Block 4 (Task 10) ist die Ausschreibung dagegen
        // schlicht nicht mehr REKONSTRUIERBAR — zwei Ursachen, die dasselbe Wort
        // nicht tragen sollten.
        $sideList = function (array $groups, string $set, string $nullTitle = 'ohne Ausschreibung'): array {
            $list = [];
            $total = 0;

            foreach ($groups as $group) {
                $count = $this->countIn($group['rows'], 'ids');
                $total += $count;

                $orte = [];
                foreach ($group['rows'] as $row) {
                    $ortLabel = (string) ($row['group']['ort'] ?? '');
                    if ($ortLabel !== '' && !in_array($ortLabel, $orte, true)) {
                        $orte[] = $ortLabel;
                    }
                }

                $title = $group['posting_title'] !== '' ? $group['posting_title'] : 'ohne Titel';
                $list[] = [
                    'title' => $group['posting_id'] === null ? $nullTitle : $title,
                    'orte' => implode(', ', $orte),
                    'count' => $count,
                    'token' => $this->drillToken('posting', $title, [
                        'posting' => $group['posting_id'],
                        'set' => $set,
                    ]),
                ];
            }

            return ['list' => $list, 'total' => $total];
        };

        // Block 2 — GESCHLOSSENE Ausschreibungen (aus cohort()['closed_rows']).
        // Bewusst nicht ortsgefiltert: hier stehen auch die geschlossenen
        // Ausschreibungen ANDERER Filialen und die an Stellen ohne gepflegten
        // Standort. Der Ort steht deshalb an jeder Zeile.
        $closed = $sideList($this->closedPostingGroups, 'closed');

        // Block 3 — OHNE FILIAL-ZUORDNUNG (aus cohort()['unreachable_rows']).
        // Die Mengen, die über KEINE Filial-Auswahl erreichbar sind: Stellen
        // ohne gepflegten Standort (gemessen rund 929 Bewerbungen) und
        // Bewerbungen ohne jede Ausschreibung. Ohne diesen Block wären sie
        // nirgends — und der Rekonziliations-Hinweis darunter kann sie nicht
        // finden, weil er innerhalb der Auswahl rechnet (siehe dort).
        $unreachable = $sideList($this->unreachablePostingGroups, 'unreachable');

        // Block 4 — HERKUNFT UNBEKANNT (aus cohort()['unknown_origin_rows'],
        // Task 10). Stellenwechsel-Altfälle: die einzige Verknüpfung dieser
        // Bewerbungen ist als Wechsel markiert (matched_via = 'position_switch')
        // — rein historisch, der laufende Betrieb erzeugt den Marker nicht mehr
        // (rund 15 Altfälle, kein wachsender Topf). Der Assigner sieht sie
        // mangels verbliebener Verknüpfung wie eine Bewerbung ganz OHNE
        // Ausschreibung; Index::cohort() schließt sie deshalb ausdrücklich aus
        // Block 3 aus, damit sie NUR hier stehen (Disjunktheit, Test:
        // StatisticsPageReconciliationTest).
        $herkunftUnbekannt = $sideList(
            $this->unknownOriginPostingGroups,
            'unknown_origin',
            'Stellenwechsel — ursprüngliche Anzeige nicht mehr bekannt',
        );

        // ---------------------------------------------------------------
        // Block 5: Rekonziliation
        // ---------------------------------------------------------------
        // Unveraendert uebernommen: Summe der Zeilen MUSS die Gesamtmenge sein
        // (Spec §4). Abweichung wird sichtbar gemacht, nicht still korrigiert.
        //
        // WAS DIESER HINWEIS NICHT KANN: total_ids wird nach dem Filtern neu
        // gebildet (Index::cohort()), er prueft also die Auswahl gegen sich
        // selbst. Was VOR dem Filter herausfiel, sehen nur die Bloecke 2, 3 und
        // 4 — deshalb sind sie keine Kosmetik, sondern das eigentliche Netz.
        $rowSum = $this->countIn($this->cohort['rows'], 'ids');
        $totalIds = count($this->cohort['total_ids']);
    @endphp

    {{-- ------------------------------------------------------------------ --}}
    {{-- Block 1: Ausgeschieden                                             --}}
    {{-- ------------------------------------------------------------------ --}}
    <x-ui-panel>
        <div x-data="{ open: false }">
            <button
                type="button"
                x-on:click="open = !open"
                class="flex w-full items-center gap-2 rounded text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] cursor-pointer"
            >
                <span x-text="open ? '▾' : '▸'" class="text-[color:var(--ui-muted)]">▸</span>
                <span class="font-semibold text-[color:var(--ui-secondary)]">Ausgeschieden</span>
                <span class="rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-xs font-medium tabular-nums text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60">
                    {{ $excludedTotal }}
                </span>
                <span class="ml-auto text-xs text-[color:var(--ui-muted)]">Bewerbungen, die nicht im laufenden Trichter stehen</span>
            </button>

            <div x-show="open" style="display: none;" class="mt-3 border-t border-[var(--ui-border)]/60 pt-3">
                <div class="mb-3 text-xs text-[color:var(--ui-muted)]">
                    Diese Zeilen sind in den Zahlen der aktuellen Auswahl ENTHALTEN — jede Bewerbung steckt in
                    genau einer Zeile, und Tabelle 1 summiert je Ausschreibung über alle Zeilentypen. Hier
                    stehen sie einzeln, damit benannt ist, was die Differenz zwischen „Bewerbungen“ und dem
                    laufenden Trichter ausmacht.
                    @if (!$this->hasOrt())
                        {{-- „das ganze Team“ gilt nur, wenn AUSSER der Filiale nichts
                             einschränkt — der Status-Filter steht standardmäßig auf
                             „online“ und schneidet schon (gemessen: „Abgesagt 1“ bei
                             zwei Absagen im Team). --}}
                        @if ($auswahlBeschnitten)
                            Ohne gewählte Filiale umfasst die Auswahl alle Filialen, aber nicht das ganze Team:
                            Status, Tätigkeit, Ausschreibung und Quelle schneiden weiter.
                        @else
                            Ohne gewählte Filiale ist die Auswahl das ganze Team; die Tabellen dazu erscheinen,
                            sobald oben eine Filiale gewählt ist.
                        @endif
                    @endif
                    <span class="cursor-help"
                          title="Nicht aufgeführt ist der Zeilentyp „Import“ (Altbestand): er ist ebenfalls in den Zahlen der Auswahl enthalten, aber kein Ausscheide-Grund, sondern eine Herkunft.{{ $auswahlNote }}">ⓘ</span>
                </div>
                <ul class="divide-y divide-[var(--ui-border)]/60">
                    @foreach ($excludedRows as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="text-sm text-[color:var(--ui-secondary)]">
                                {{ $row['label'] }}
                                <span class="cursor-help text-[color:var(--ui-muted)]" title="{{ $row['title'] }}">ⓘ</span>
                            </span>
                            @if ($row['count'] > 0)
                                <button
                                    type="button"
                                    wire:click="drill(@js($row['token']), @js('ids'))"
                                    wire:loading.attr="disabled"
                                    title="{{ $row['label'] }}: Personen anzeigen"
                                    class="inline-flex min-w-[2rem] items-center justify-center rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-xs font-medium tabular-nums text-[color:var(--ui-secondary)] ring-1 ring-[var(--ui-border)]/60 hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer"
                                >{{ $row['count'] }}</button>
                            @else
                                {{-- Nullen tragen KEINE Pille und keinen Klick:
                                     eine gefuellte Pille ist die Markierung fuer
                                     „hier ist etwas passiert“ (wie in den Tabellen). --}}
                                <span class="text-xs text-[color:var(--ui-muted)] tabular-nums">0</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </x-ui-panel>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Block 2: Geschlossene Ausschreibungen                              --}}
    {{-- Block 3: Ohne Filial-Zuordnung                                     --}}
    {{-- Block 4: Herkunft unbekannt (Task 10)                              --}}
    {{--                                                                    --}}
    {{-- Gleiches Markup, drei Mengen — deshalb EIN Durchlauf ueber drei     --}}
    {{-- Beschreibungen statt dreimal derselbe Block. Was sie unterscheidet, --}}
    {{-- steht im Text jedes Blocks, nicht in der Struktur.                 --}}
    {{-- ------------------------------------------------------------------ --}}
    @php
        $sideBlocks = [
            [
                'title' => 'Geschlossene Ausschreibungen',
                'summary' => count($closed['list']) . ' '
                    . (count($closed['list']) === 1 ? 'Ausschreibung' : 'Ausschreibungen') . ', nicht online',
                'data' => $closed,
                // Auch der LEER-Text darf keine Aussage ueber die Gesamtheit machen,
                // solange ein einschraenkender Filter aktiv ist: „keine geschlossene
                // Ausschreibung“ ist dann schlicht falsch — sie wurde nur
                // weggefiltert. Derselbe Fehler wie im Blocktext, nur im leeren Zweig.
                'empty' => $narrowed
                    ? 'In dieser Auswahl keine geschlossene Ausschreibung mit Bewerbungen — '
                        . 'ohne Tätigkeits-, Ausschreibungs- und Quellen-Filter können es mehr sein.'
                    : 'Keine geschlossene Ausschreibung mit Bewerbungen.',
                'note' => 'Nicht online heißt: nicht veröffentlicht oder nicht aktiv — ein abgelaufenes '
                    . 'Laufzeitende allein gilt nicht als geschlossen. Diese Liste ist bewusst NICHT auf die '
                    . 'gewählte Filiale eingeschränkt, deshalb steht der Ort an jeder Zeile.' . $narrowNote,
                'noteHint' => 'Der Tätigkeits-Filter wirkt in diesem Block MIT, der Filial-Filter nicht: wer '
                    . 'eine Tätigkeit wählt, will keine fremde sehen — wer eine Filiale wählt, könnte die '
                    . 'Zeilen ohne Filiale über keine Auswahl erreichen. '
                    . 'Asymmetrie, die man kennen muss: GESCHLOSSENE Ausschreibungen anderer Filialen '
                    . 'sind hier sichtbar (der Ort steht dran). ONLINE-Ausschreibungen anderer Filialen sind es '
                    . 'nicht — die sieht man, indem man oben die Filiale wechselt. Bei Status „alle (auch '
                    . 'geschlossene)“ stehen die geschlossenen Ausschreibungen der gewählten Filiale '
                    . 'zusätzlich in der Tabelle oben.'
                    // KEINE Vollständigkeits-Behauptung, sobald ein einschränkender
                    // Filter aktiv ist: Ausschreibungs- und Quellen-Filter räumen
                    // die Blöcke leer, der Tätigkeits-Filter schneidet sie zu.
                    . ($narrowed
                        ? ' Mit aktivem Tätigkeits-, Ausschreibungs- oder Quellen-Filter ist die Liste ein '
                            . 'Ausschnitt: Zeilen, die diese Filter ausschließen, fehlen hier ebenso wie oben '
                            . '— auch Bewerbungen ohne gepflegte Tätigkeit.'
                        : ' Ohne einschränkende Filter ist dieser Block die vollständige Liste und die Tabelle '
                            . 'die Auswahl.'),
            ],
            [
                'title' => 'Ohne Filial-Zuordnung',
                'summary' => 'über keine Filial-Auswahl erreichbar',
                'data' => $unreachable,
                'empty' => $narrowed
                    ? 'In dieser Auswahl hängt jede Bewerbung an einer Ausschreibung mit gepflegtem Standort — '
                        . 'ohne Tätigkeits-, Ausschreibungs- und Quellen-Filter können es mehr sein.'
                    : 'Jede Bewerbung hängt an einer Ausschreibung mit gepflegtem Standort.',
                'note' => 'Diese Bewerbungen stehen in KEINER Filial-Ansicht — nicht in dieser und in keiner '
                    . 'anderen. Zwei Gründe gibt es: an der Stelle der Ausschreibung ist kein Standort '
                    . 'gepflegt („ohne Ort“), oder an der Bewerbung hängt überhaupt keine Ausschreibung '
                    . '(„ohne Ausschreibung“). Gepflegt wird der Standort an der STELLE, nicht an der '
                    . 'Ausschreibung.' . $narrowNote,
                'noteHint' => 'Der Tätigkeits-Filter wirkt auch hier mit, der Filial-Filter nicht. '
                    . 'Warum dieser Block überhaupt nötig ist: der Rekonziliations-Hinweis darunter '
                    . 'rechnet innerhalb der Auswahl (die Gesamtmenge wird nach dem Filtern neu gebildet) und '
                    . 'kann eine Bewerbung, die vor dem Filter herausfiel, nicht sehen. Hier sind nur '
                    . 'ONLINE-Ausschreibungen aufgeführt; die geschlossenen stehen im Block darüber, damit '
                    . 'nichts doppelt gezählt wird.'
                    . ($narrowed
                        ? ' Mit aktivem Tätigkeits-, Ausschreibungs- oder Quellen-Filter ist auch diese Liste '
                            . 'ein Ausschnitt — Zeilen, die diese Filter ausschließen, fehlen hier ebenso wie '
                            . 'oben.'
                        : ' Ohne einschränkende Filter ist die Liste vollständig.'),
            ],
            [
                'title' => 'Herkunft unbekannt',
                'summary' => 'Stellenwechsel-Altfälle, keine Anzeige zuordenbar',
                'data' => $herkunftUnbekannt,
                'empty' => $narrowed
                    ? 'In dieser Auswahl kein Stellenwechsel-Altfall mit unbekannter Herkunft — '
                        . 'ohne Tätigkeits-, Ausschreibungs- und Quellen-Filter können es mehr sein.'
                    : 'Kein Stellenwechsel-Altfall mit unbekannter Herkunft.',
                // WICHTIG: kein laufendes Geschehen behaupten. Der Marker entsteht im
                // Betrieb nicht mehr (Stellenwechsel fasst den Pivot seit dem Umbau
                // nicht mehr an) — gesetzt wurde er von einem frueheren
                // Zwischenstand und vom Backfill-Kommando. Der Text darf deshalb
                // nicht wie eine wachsende Warnung klingen.
                'note' => 'Diese Bewerbungen haben AUSSCHLIESSLICH eine als Stellenwechsel markierte '
                    . 'Verknüpfung — das ist keine Bewerbung auf die verlinkte Anzeige, sondern ein '
                    . 'technisches Artefakt eines früheren Stellenwechsels. Ihre ursprüngliche Anzeige ist '
                    . 'nicht mehr bekannt. Rein historisch: rund 15 Altfälle, der laufende Betrieb erzeugt '
                    . 'diesen Marker nicht mehr — kein wachsender Topf.' . $narrowNote,
                'noteHint' => 'Der Tätigkeits-Filter wirkt auch hier mit, der Filial-Filter nicht — dieselbe '
                    . 'Asymmetrie wie in den beiden Blöcken darüber. Ohne diesen Block wären diese Bewerbungen '
                    . 'von einer Bewerbung ganz ohne Ausschreibung nicht zu unterscheiden gewesen: der Assigner '
                    . 'sieht beide gleich („ohne Ausschreibung“), Index::cohort() schließt sie deshalb aus '
                    . 'Block 3 aus, damit keine Bewerbung in zwei Blöcken steht.'
                    . ($narrowed
                        ? ' Mit aktivem Tätigkeits-, Ausschreibungs- oder Quellen-Filter ist auch diese Liste '
                            . 'ein Ausschnitt.'
                        : ' Ohne einschränkende Filter ist die Liste vollständig.'),
            ],
        ];
    @endphp

    @foreach ($sideBlocks as $block)
        <x-ui-panel>
            <div x-data="{ open: false }">
                <button
                    type="button"
                    x-on:click="open = !open"
                    class="flex w-full items-center gap-2 rounded text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] cursor-pointer"
                >
                    <span x-text="open ? '▾' : '▸'" class="text-[color:var(--ui-muted)]">▸</span>
                    <span class="font-semibold text-[color:var(--ui-secondary)]">{{ $block['title'] }}</span>
                    <span class="rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-xs font-medium tabular-nums text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60">
                        {{ $block['data']['total'] }}
                    </span>
                    <span class="ml-auto text-xs text-[color:var(--ui-muted)]">{{ $block['summary'] }}</span>
                </button>

                <div x-show="open" style="display: none;" class="mt-3 border-t border-[var(--ui-border)]/60 pt-3">
                    <div class="mb-3 text-xs text-[color:var(--ui-muted)]">
                        {{ $block['note'] }}
                        <span class="cursor-help" title="{{ $block['noteHint'] }}">ⓘ</span>
                    </div>

                    @if ($block['data']['list'] === [])
                        <div class="py-4 text-center text-sm text-[color:var(--ui-muted)]">
                            {{ $block['empty'] }}
                        </div>
                    @else
                        <ul class="divide-y divide-[var(--ui-border)]/60">
                            @foreach ($block['data']['list'] as $entry)
                                <li class="flex items-center justify-between gap-3 py-2">
                                    <span class="min-w-0 text-sm text-[color:var(--ui-secondary)]">
                                        <span class="block truncate" title="{{ $entry['title'] }}">{{ $entry['title'] }}</span>
                                        @if ($entry['orte'] !== '')
                                            {{-- Task 11 (Textfehler-Fix): „ohne Ausschreibung“ heißt hier NICHT
                                                 immer „an der Bewerbung hängt keine Ausschreibung“ — für die
                                                 Stellenwechsel-Altfälle (Block „Herkunft unbekannt“) stimmt das
                                                 sachlich nicht, die haben sehr wohl eine Ausschreibung, nur eine
                                                 disqualifizierte Verknüpfung dazu (matched_via = 'position_switch').
                                                 Der Text hier gilt deshalb für BEIDE Ursachen, ohne das generische
                                                 Label des Assigners (CohortAssigner::noAssignment) anzufassen. --}}
                                            <span class="block text-xs text-[color:var(--ui-muted)]"
                                                  title="Ort der Stelle. „ohne Ort“ heißt: an der Stelle ist kein Standort gepflegt; „ohne Ausschreibung“ heißt: es gibt keine verwendbare Verknüpfung zu einer Ausschreibung mehr — entweder, weil an der Bewerbung wirklich keine hängt, oder weil die einzige Verknüpfung nachträglich als Stellenwechsel disqualifiziert wurde. Beides fällt aus jeder Filial-Ansicht heraus.">
                                                {{ $entry['orte'] }}
                                            </span>
                                        @endif
                                    </span>
                                    @if ($entry['count'] > 0)
                                        <button
                                            type="button"
                                            wire:click="drill(@js($entry['token']), @js('ids'))"
                                            wire:loading.attr="disabled"
                                            title="Personen dieser Zeile anzeigen"
                                            class="inline-flex min-w-[2rem] shrink-0 items-center justify-center rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-xs font-medium tabular-nums text-[color:var(--ui-secondary)] ring-1 ring-[var(--ui-border)]/60 hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer"
                                        >{{ $entry['count'] }}</button>
                                    @else
                                        <span class="shrink-0 text-xs text-[color:var(--ui-muted)] tabular-nums">0</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </x-ui-panel>
    @endforeach

    {{-- ------------------------------------------------------------------ --}}
    {{-- Block 5: Rekonziliation                                            --}}
    {{-- ------------------------------------------------------------------ --}}
    @if ($rowSum !== $totalIds)
        {{-- Rekonziliations-Invariante verletzt: die Summe der Zeilen MUSS die
             Gesamtmenge sein. Sichtbar machen statt still korrigieren —
             derselbe Hinweis wie in der Gesamt-Zeile der Tabelle, hier in
             Lesegroesse, weil er die ganze Seite betrifft. --}}
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <span class="font-bold">Rekonziliation verletzt:</span>
            Summe der Zeilen ({{ $rowSum }}) weicht von der Gesamtmenge ({{ $totalIds }}) ab.
            <div class="mt-1 text-xs">
                Jede Bewerbung muss in genau einer Zeile stecken. Weicht die Summe ab, ist eine Zahl auf
                dieser Seite falsch — bitte melden, statt sie zu benutzen.
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Drill-down-Modal                                                   --}}
    {{-- ------------------------------------------------------------------ --}}
    {{-- Ausserhalb des Filial-Guards: das Modal kann offen sein, wenn der Ort
         gewechselt wird. resetDrill() leert es dann, es rendert also leer statt
         Personen einer anderen Auswahl zu zeigen. --}}
    {{-- wire:model bindet eine echte bool-Property (NICHT drillLabel): das
         Modal setzt den Wert beim Schliessen auf false, was einer als string
         typisierten Property einen TypeError bescheren wuerde. --}}
    @php
        $campaignEnabled = $this->campaignEnabled();
        $campaignRows = $campaignEnabled ? $this->campaignRows : [];
        $campaignProgress = $campaignEnabled ? $this->campaignProgress : null;
        $campaignCounts = $campaignEnabled ? $this->campaignCounts() : ['A' => 0, 'B' => 0, 'total' => 0];
        $campaignTemplates = $campaignEnabled ? $this->campaignTemplates : [];
        $campaignRunning = $campaignProgress !== null && !($campaignProgress['done'] ?? false);
        $pollAttr = $campaignRunning ? 'wire:poll.3s' : '';
    @endphp
    <x-ui-modal wire:model="showDrill" size="lg" :hideFooter="!$campaignEnabled">
        <x-slot name="header">
            {{ $this->drillLabel !== '' ? $this->drillLabel : 'Personen' }} ({{ count($this->drillIds) }})
        </x-slot>

        @if (count($this->drillIds) === 0)
            <div class="py-6 text-center text-sm text-[color:var(--ui-muted)]">Keine Personen in dieser Auswahl.</div>
        @elseif ($campaignEnabled)
            {{-- Kampagne „Neue Termine“: Auswahl + Badges. Polling nur, solange ein Versand laeuft. --}}
            <div {!! $pollAttr !!}>
                <div class="mb-2 flex items-center justify-between text-xs text-[color:var(--ui-muted)]">
                    <span>{{ $campaignCounts['total'] }} von {{ count($campaignRows) }} gewählt — {{ $campaignCounts['A'] }}× Template A (Bewerbung vervollständigen), {{ $campaignCounts['B'] }}× Template B (Terminauswahl)</span>
                    <span class="flex gap-2">
                        <button type="button" class="underline" wire:click="campaignSelectAll(true)">alle</button>
                        <button type="button" class="underline" wire:click="campaignSelectAll(false)">keine</button>
                    </span>
                </div>
                <ul class="divide-y divide-[var(--ui-border)]/60">
                    @foreach ($campaignRows as $id => $row)
                        @php
                            $rowDate = $row['applied_at'] ? \Illuminate\Support\Carbon::parse($row['applied_at'])->format('d.m.Y') : 'ohne Datum';
                            $rowDisabled = !$row['selectable'] || $campaignRunning;
                        @endphp
                        <li class="py-2 flex items-center gap-3 {{ $row['selectable'] ? '' : 'opacity-60' }}">
                            <input type="checkbox" class="h-4 w-4 rounded border-[var(--ui-border)]"
                                   wire:model.live="campaignSelection.{{ $id }}" @disabled($rowDisabled) />
                            <div class="flex-1 min-w-0">
                                <a href="{{ route('recruiting.applicants.show', $id) }}" class="text-[color:var(--ui-primary)] hover:underline text-sm">{{ $row['name'] }}</a>
                                <span class="ml-2 text-xs text-[color:var(--ui-muted)]">{{ $row['phase'] }} · {{ $row['template'] }}</span>
                                @foreach ($row['badges'] as $badge)
                                    <span class="ml-1 inline-block rounded bg-[var(--ui-muted-5)] px-1.5 py-0.5 text-[11px] text-[color:var(--ui-muted)]">{{ $badge }}</span>
                                @endforeach
                            </div>
                            <span class="text-xs text-[color:var(--ui-muted)] whitespace-nowrap tabular-nums">{{ $rowDate }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            @php $drillApplicants = $this->drillApplicants; @endphp
            @if ($drillApplicants->count() !== count($this->drillIds))
                <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    {{ count($this->drillIds) }} IDs in der Auswahl, aber {{ $drillApplicants->count() }} Bewerber ladbar
                    (team-fremde oder gelöschte Datensätze werden nicht angezeigt).
                </div>
            @endif
            <ul class="divide-y divide-[var(--ui-border)]/60">
                @foreach ($drillApplicants as $applicant)
                    @php
                        $applicantName = $applicant->crmContactLinks->first()?->contact?->full_name;
                    @endphp
                    <li class="py-2 flex items-center justify-between gap-3">
                        <a href="{{ route('recruiting.applicants.show', $applicant) }}"
                           class="text-[color:var(--ui-primary)] hover:underline text-sm">
                            {{ $applicantName ?: 'Bewerber #' . $applicant->id }}
                        </a>
                        <span class="text-xs text-[color:var(--ui-muted)] whitespace-nowrap tabular-nums">
                            {{ $applicant->applied_at?->format('d.m.Y') ?? 'ohne Datum' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($campaignEnabled)
            <x-slot name="footer">
                <div class="w-full space-y-2">
                    @if ($campaignProgress !== null)
                        <div class="text-sm">
                            <strong>{{ $campaignProgress['sent'] }}</strong> / {{ $campaignProgress['total'] }} gesendet
                            · {{ $campaignProgress['failed'] }} Fehler · {{ $campaignProgress['skipped'] }} übersprungen
                            {!! ($campaignProgress['done'] ?? false) ? ' · <span class="text-green-700">abgeschlossen</span>' : ' · läuft …' !!}
                        </div>
                        @if (!empty($campaignProgress['errors']))
                            <ul class="text-xs text-red-700 list-disc pl-4">
                                @foreach ($campaignProgress['errors'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                            <x-ui-input-select
                                :value="$this->campaignTemplateA"
                                name="campaignTemplateA"
                                label="Template A — Bewerbung vervollständigen"
                                :options="$campaignTemplates"
                                optionValue="id"
                                optionLabel="label"
                                :nullable="true"
                                nullLabel="– Template wählen –"
                                displayMode="dropdown"
                                wire:model.live="campaignTemplateA"
                            />
                            <x-ui-input-select
                                :value="$this->campaignTemplateB"
                                name="campaignTemplateB"
                                label="Template B — Terminauswahl"
                                :options="$campaignTemplates"
                                optionValue="id"
                                optionLabel="label"
                                :nullable="true"
                                nullLabel="– Template wählen –"
                                displayMode="dropdown"
                                wire:model.live="campaignTemplateB"
                            />
                        </div>
                        @if ($this->campaignError !== '')
                            <div class="text-xs text-red-700">{{ $this->campaignError }}</div>
                        @endif
                        <div class="flex justify-end">
                            <x-ui-button variant="primary" wire:click="startCampaign" wire:loading.attr="disabled" wire:target="startCampaign" :disabled="$campaignCounts['total'] === 0">
                                Kampagne an {{ $campaignCounts['total'] }} Personen senden
                            </x-ui-button>
                        </div>
                    @endif
                </div>
            </x-slot>
        @endif
    </x-ui-modal>
</div>
