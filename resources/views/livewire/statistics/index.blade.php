{{--
    Statistik-Seite (Kundenansicht). Diese Datei setzt nur ZUSAMMEN — gerechnet
    wird in der Livewire-Komponente, gerendert in den beiden Tabellen-Partials:

      1. Filterleiste — Filiale (PFLICHT), Status der Ausschreibungen, Tätigkeit,
         Ausschreibung, Quelle und der Termin-Zeitraum.
      2. KPI-Kacheln — lesen ausschliesslich aus dem Kohorten-Ergebnis.
      3. Tabelle 1 (Ausschreibungen) und Tabelle 2 (Schulungstermine), je ein
         fertiger Abschnitt mit eigenem Panel.
      4. Vier Blöcke: Ausgeschieden, Geschlossene Ausschreibungen, Ohne
         Filial-Zuordnung, Rekonziliation.
      5. Drill-down-Modal.

    DIE BLÖCKE SIND KEIN ANHANG. Gemessen zeigte die Seite bei einer Filiale mit
    Status „online“ eine von sieben Bewerbungen — die anderen sechs steckten in
    geschlossenen Ausschreibungen, an Stellen ohne gepflegten Standort oder an
    keiner Ausschreibung, und der Rekonziliations-Hinweis schwieg dazu (er rechnet
    innerhalb der Auswahl, siehe Block 4). Blöcke 2 und 3 benennen genau diese
    Mengen; zusammen mit den Tabellen erfassen sie jede Bewerbung des Teams genau
    einmal (Test: StatisticsPageReconciliationTest).

    DIE FILIALE IST PFLICHT, und das ist keine Kosmetik: phaseLabels() liest den
    Phasensatz der gefilterten Filiale, und `where('location', null)` macht Laravel
    zu einem `whereNull` — die Spaltenköpfe kämen dann aus dem Phasensatz ORTLOSER
    Stellen über Zahlen aller Orte. Deshalb steht alles, was Zahlen zeigt, hinter
    dem Guard `$this->hasOrt()`; ohne Filiale steht hier eine Aufforderung.

    Die alte Kohorten-Tabelle (Baum Ort → Tätigkeit → Zeilentyp) ist ERSETZT
    (Kunden-Entscheidung); mit ihr sind das Computed `groups()`, `interviewMeta()`
    und `interviewIdOf()` entfallen.
--}}
@php
    // Alles hier oben ist unabhaengig von der Kohorte — die teure Rechnung steht
    // absichtlich erst hinter dem Filial-Guard, damit ein Aufruf ohne Filiale
    // nicht das ganze Team laedt.
    $statusOptions = [
        'online' => 'nur online',
        'alle' => 'alle (auch geschlossene)',
    ];

    // Der Zeitraum der Seite ist das TERMINDATUM (Tabelle 2), nicht das
    // Bewerbungsdatum — der frühere Bewerbungs-Zeitraum ist mit dieser Ansicht
    // entfallen. Die Beschriftung sagt das auch, sonst liest man die Kachel
    // „Bewerbungen" als „Bewerbungen im Zeitraum".
    $rangeSubtitle = ($this->interviewFrom || $this->interviewTo)
        ? trim(($this->interviewFrom ? \Carbon\Carbon::parse($this->interviewFrom)->format('d.m.Y') : '…')
            . ' – ' . ($this->interviewTo ? \Carbon\Carbon::parse($this->interviewTo)->format('d.m.Y') : '…'))
        : 'alle Termine';

    $filterNote = 'Der Zeitraum filtert die TERMINE (Tabelle „Schulungstermine"), nicht den Bewerbungseingang: '
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

    $statusNote = '„Online" heißt veröffentlicht UND aktiv. Alles andere (Entwurf, archiviert, deaktiviert) gilt '
        . 'als geschlossen — ein abgelaufenes Laufzeitende allein nicht. Geschlossene Ausschreibungen stehen im '
        . 'Block unter den Tabellen, auch wenn sie hier ausgefiltert sind.';
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
                 Orte" gibt es nicht mehr, die Seite zeigt immer genau eine Filiale
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
                label="Ausschreibungen"
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
                label="Ausschreibung"
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
        {{-- KEINE Zahlen ohne Filiale (Guard, siehe Kopfkommentar). Hier wird
             absichtlich nichts gerechnet: cohort() wuerde ohne Ort-Filter das
             ganze Team laden, und die Phasen-Spaltenkoepfe kaemen aus dem
             Phasensatz ortloser Stellen. --}}
        <x-ui-panel title="Filiale wählen">
            @if ($this->ortOptions === [])
                <div class="py-8 text-center text-sm text-[color:var(--ui-muted)]">
                    An keiner Stelle ist ein Standort gepflegt — deshalb gibt es hier nichts zu wählen.
                    <div class="mt-2 text-xs">
                        Diese Seite zeigt die Zahlen einer Filiale. Der Standort hängt an der STELLE
                        (nicht an der Ausschreibung); sobald er dort eingetragen ist, erscheint die Filiale in der Auswahl.
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
                    </div>
                </div>
            @endif
        </x-ui-panel>
    @else
        @php
            $tiles = $this->tiles;

            // Right-Censoring auf der Kachel: die Regel waehlt das ViewModel
            // anhand der Zeilenmenge (isCensoredForRows) — bei mehreren Zeilen
            // also die Aggregat-Regel „grau nur, wenn JEDE Zeile zu jung ist".
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
                // Die Antwort auf „wo hängen die restlichen fest" — vorher nur als
                // Nebenzeile in der Tabelle zu finden.
                ['label' => 'Ohne Termin', 'value' => $tiles['ohne_termin'], 'column' => 'ids', 'token' => $ohneTerminToken,
                 'muted' => false, 'title' => 'Aktive Bewerbungen ohne Schulungstermin — in der Ausschreibungs-Tabelle stecken sie in den Phasen-Spalten.'],
                ['label' => 'Unterschriften', 'value' => $tiles['unterschrieben'], 'column' => 'unterschrieben', 'token' => $allToken,
                 'muted' => false, 'title' => 'Bewerbungen mit mindestens einem unterschriebenen Vertrag.'],
                ['label' => 'Conversion', 'value' => $tiles['conversion'] . ' %', 'column' => null, 'token' => null,
                 'muted' => $overallCensored,
                 'title' => $overallCensored
                    ? $this->censorNote()
                    : 'Unterschriften geteilt durch alle Bewerbungen der Auswahl. Enthält auch sehr junge Bewerbungen, die noch keine Zeit zur Unterschrift hatten — bei einer Auswahl über viele Zeiträume fällt das kaum ins Gewicht, bei einer einzelnen frischen Schulung schon (dort ist die Quote in der Tabelle ausgegraut).'],
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

        @php
            // ---------------------------------------------------------------
            // Block 1: Ausgeschieden
            // ---------------------------------------------------------------
            // Diese Zeilentypen sind fuer die KPIs nicht direkt relevant, muessen
            // aber SICHTBAR sein: sonst ist die Differenz zwischen „Bewerbungen"
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
                    // derselbe Scope wie die Kachel „Ohne Termin". Kein neuer
                    // Scope: resolveIds kennt ihn, und ein erfundener traefe
                    // fail-closed nichts.
                    'token' => $this->drillToken('type_all', $meta['label'], ['type' => $type]),
                ];
            }

            // ---------------------------------------------------------------
            // Blöcke 2 und 3: die beiden Mengen, die aus der Auswahl fallen
            // ---------------------------------------------------------------
            // Beide Listen sind gleich gebaut (eine Zeile je Ausschreibung, mit Ort
            // und Drill-down) und werden deshalb von EINEM Bauplan erzeugt: zwei
            // Kopien desselben Markups liefen beim nächsten Umbau auseinander, und
            // gerade diese beiden Blöcke müssen sich wie eine Lesart lesen.
            //
            // Das Token trägt zwei Angaben, beide Pflicht: 'posting' ist der
            // Zuschnitt (ohne ihn trifft der Scope fail-closed nichts), 'set' die
            // ZEILENMENGE. Ohne 'set' löste der Klick gegen die Auswahl auf — und
            // dort stehen diese Zeilen gerade nicht.
            $sideList = function (array $groups, string $set): array {
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
                        'title' => $group['posting_id'] === null ? 'ohne Ausschreibung' : $title,
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

            // ---------------------------------------------------------------
            // Block 4: Rekonziliation
            // ---------------------------------------------------------------
            // Unveraendert uebernommen: Summe der Zeilen MUSS die Gesamtmenge sein
            // (Spec §4). Abweichung wird sichtbar gemacht, nicht still korrigiert.
            //
            // WAS DIESER HINWEIS NICHT KANN: total_ids wird nach dem Filtern neu
            // gebildet (Index::cohort()), er prueft also die Auswahl gegen sich
            // selbst. Was VOR dem Filter herausfiel, sehen nur die Bloecke 2 und 3 —
            // deshalb sind sie keine Kosmetik, sondern das eigentliche Netz.
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
                        Diese Zeilen sind in den Zahlen der Tabellen oben ENTHALTEN — jede Bewerbung steckt in
                        genau einer Zeile, und Tabelle 1 summiert je Ausschreibung über alle Zeilentypen. Hier
                        stehen sie einzeln, damit benannt ist, was die Differenz zwischen „Bewerbungen" und dem
                        laufenden Trichter ausmacht.
                        <span class="cursor-help"
                              title="Nicht aufgeführt ist der Zeilentyp „Import“ (Altbestand): er ist ebenfalls in den Zahlen oben enthalten, aber kein Ausscheide-Grund, sondern eine Herkunft.">ⓘ</span>
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
                                         „hier ist etwas passiert" (wie in den Tabellen). --}}
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
        {{--                                                                    --}}
        {{-- Gleiches Markup, zwei Mengen — deshalb EIN Durchlauf ueber zwei     --}}
        {{-- Beschreibungen statt zweimal derselbe Block. Was sie unterscheidet, --}}
        {{-- steht im Text jedes Blocks, nicht in der Struktur.                 --}}
        {{-- ------------------------------------------------------------------ --}}
        @php
            $sideBlocks = [
                [
                    'title' => 'Geschlossene Ausschreibungen',
                    'summary' => count($closed['list']) . ' '
                        . (count($closed['list']) === 1 ? 'Ausschreibung' : 'Ausschreibungen') . ', nicht online',
                    'data' => $closed,
                    'empty' => 'Keine geschlossene Ausschreibung mit Bewerbungen.',
                    'note' => 'Nicht online heißt: nicht veröffentlicht oder nicht aktiv — ein abgelaufenes '
                        . 'Laufzeitende allein gilt nicht als geschlossen. Diese Liste ist bewusst NICHT auf die '
                        . 'gewählte Filiale eingeschränkt, deshalb steht der Ort an jeder Zeile.',
                    'noteHint' => 'Der Tätigkeits-Filter wirkt in diesem Block MIT, der Filial-Filter nicht: wer '
                        . 'eine Tätigkeit wählt, will keine fremde sehen — wer eine Filiale wählt, könnte die '
                        . 'Zeilen ohne Filiale über keine Auswahl erreichen. '
                        . 'Asymmetrie, die man kennen muss: GESCHLOSSENE Ausschreibungen anderer Filialen '
                        . 'sind hier sichtbar (der Ort steht dran). ONLINE-Ausschreibungen anderer Filialen sind es '
                        . 'nicht — die sieht man, indem man oben die Filiale wechselt. Bei Status „alle (auch '
                        . 'geschlossene)“ stehen die geschlossenen Ausschreibungen der gewählten Filiale '
                        . 'zusätzlich in der Tabelle oben; dieser Block ist die vollständige Liste, die Tabelle '
                        . 'die Auswahl.',
                ],
                [
                    'title' => 'Ohne Filial-Zuordnung',
                    'summary' => 'über keine Filial-Auswahl erreichbar',
                    'data' => $unreachable,
                    'empty' => 'Jede Bewerbung hängt an einer Ausschreibung mit gepflegtem Standort.',
                    'note' => 'Diese Bewerbungen stehen in KEINER Filial-Ansicht — nicht in dieser und in keiner '
                        . 'anderen. Zwei Gründe gibt es: an der Stelle der Ausschreibung ist kein Standort '
                        . 'gepflegt („ohne Ort“), oder an der Bewerbung hängt überhaupt keine Ausschreibung '
                        . '(„ohne Ausschreibung“). Gepflegt wird der Standort an der STELLE, nicht an der '
                        . 'Ausschreibung.',
                    'noteHint' => 'Der Tätigkeits-Filter wirkt auch hier mit, der Filial-Filter nicht. '
                        . 'Warum dieser Block überhaupt nötig ist: der Rekonziliations-Hinweis darunter '
                        . 'rechnet innerhalb der Auswahl (die Gesamtmenge wird nach dem Filtern neu gebildet) und '
                        . 'kann eine Bewerbung, die vor dem Filter herausfiel, nicht sehen. Hier sind nur '
                        . 'ONLINE-Ausschreibungen aufgeführt; die geschlossenen stehen im Block darüber, damit '
                        . 'nichts doppelt gezählt wird.',
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
                                                <span class="block text-xs text-[color:var(--ui-muted)]"
                                                      title="Ort der Stelle. „ohne Ort“ heißt: an der Stelle ist kein Standort gepflegt; „ohne Ausschreibung“ heißt: an der Bewerbung hängt keine. Beides fällt aus jeder Filial-Ansicht heraus.">
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
        {{-- Block 4: Rekonziliation                                            --}}
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
    <x-ui-modal wire:model="showDrill" size="lg" hideFooter>
        <x-slot name="header">
            {{ $this->drillLabel !== '' ? $this->drillLabel : 'Personen' }} ({{ count($this->drillIds) }})
        </x-slot>

        @if (count($this->drillIds) === 0)
            <div class="py-6 text-center text-sm text-[color:var(--ui-muted)]">Keine Personen in dieser Auswahl.</div>
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
    </x-ui-modal>
</div>
