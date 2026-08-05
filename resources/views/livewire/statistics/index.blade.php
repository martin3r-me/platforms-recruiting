@php
    // Spaltendefinition an EINER Stelle — thead, jede Datenzeile und beide
    // Summen-Zeilen lesen daraus. Farbklassen bewusst als Literale (Tailwind-JIT
    // findet keine zusammengesetzten Klassennamen).
    // Jede Spalte traegt ihre Definition als Tooltip (Spec §6): die Zahlen sind
    // ohne die Definition mehrdeutig, und der Tooltip ist der einzige Ort, an dem
    // sie mitreist.
    //
    // FARBSYSTEM (dataviz): Der Trichter ist eine ABFOLGE — jede Stufe ist eine
    // Teilmenge der vorigen. Er traegt deshalb EINE Farbe in zunehmender
    // Saettigung (sky 50 → 400), damit man das Abnehmen sieht. Sechs unabhaengige
    // Buntfarben liessen ihn wie sechs unverbundene Kategorien aussehen.
    // Abzweige tragen Status-Farben (Standby = Warnung, Nicht erschienen =
    // kritisch), das Vertragsziel eine eigene Rampe (emerald), das unentschiedene
    // Restfeld Neutral-Grau. Vier Farbfamilien statt neun.
    // 'gstart' zieht die Trennlinie zur vorigen Spaltengruppe.
    $colDefs = [
        ['key' => 'ids',                'label' => 'Bewerbungen',    'gstart' => true,
         'on' => 'bg-sky-50 text-sky-900',       'total' => 'bg-sky-100 text-sky-950',
         'title' => 'Alle Bewerbungen dieser Zeile — Testbewerber sind immer ausgeschlossen, Bewerbungen ohne Datum stehen in einer eigenen Zeile. Bezugsgröße aller anderen Spalten.'],
        ['key' => 'kontaktiert',        'label' => 'Kontaktiert',
         'on' => 'bg-sky-100 text-sky-900',      'total' => 'bg-sky-200 text-sky-950',
         'title' => 'Anreicherungs-Proxy (enrichment_status), kein Kontaktnachweis'],
        ['key' => 'gebucht',            'label' => 'Gebucht',
         'on' => 'bg-sky-200 text-sky-900',      'total' => 'bg-sky-300 text-sky-950',
         'title' => 'Hat eine kohorten-relevante Buchung auf diesem Termin (Rang ≥ 1: booked/registered und höher). Storno zählt nicht.'],
        ['key' => 'bestaetigt',         'label' => 'Bestätigt',
         'on' => 'bg-sky-300 text-sky-950',      'total' => 'bg-sky-400 text-sky-950',
         'title' => 'confirmed/attended/no_show — registered zählt bewusst nicht (mehrdeutig, siehe Auftrag ③). Wert ist ein Status-Snapshot und kann zwischen Aufrufen sinken.'],
        ['key' => 'teilgenommen',       'label' => 'Teilgenommen',
         'on' => 'bg-sky-400 text-sky-950',      'total' => 'bg-sky-500 text-sky-950',
         'title' => 'Status attended (Rang 3). „Nicht erschienen" ist ein Abzweig und zählt hier NICHT mit.'],
        ['key' => 'standby',            'label' => 'Standby',        'gstart' => true,
         'on' => 'bg-amber-100 text-amber-900',  'total' => 'bg-amber-200 text-amber-900',
         'title' => 'Buchung besteht, belegt aber keinen Platz mehr (booked + seat_released_at) — zählt in keiner der beiden Belegungs-Spalten mit.'],
        ['key' => 'no_show',            'label' => 'Nicht erschienen',
         'on' => 'bg-red-100 text-red-900',      'total' => 'bg-red-200 text-red-900',
         'title' => 'Status no_show — gebucht und bestätigt, aber nicht erschienen. Gilt als abgeschlossen, zählt also nicht als „noch offen".'],
        ['key' => 'vertrag_verschickt', 'label' => 'Vertrag verschickt', 'gstart' => true,
         'on' => 'bg-emerald-50 text-emerald-900',  'total' => 'bg-emerald-100 text-emerald-900',
         'title' => 'Mindestens ein Vertrag mit sent_at. Stornierte Verträge (status=cancelled) sind ausgeschlossen.'],
        ['key' => 'unterschrieben',     'label' => 'Unterschrieben',
         'on' => 'bg-emerald-200 text-emerald-900', 'total' => 'bg-emerald-300 text-emerald-950',
         'title' => 'Mindestens ein Vertrag mit signed_at — das Ziel des Trichters.'],
        // Neutral-grau statt einer Trichter-Farbe: "noch offen" ist kein
        // Fortschritt, sondern das unentschiedene Restfeld.
        ['key' => 'offen_ids',          'label' => 'Noch offen',     'gstart' => true,
         'on' => 'bg-gray-100 text-gray-700',    'total' => 'bg-gray-200 text-gray-800',
         'onlyRunning' => true,
         'title' => 'Weder unterschrieben noch „nicht erschienen" — die Bewerbungen, deren Ausgang noch offen ist (Bewerbungen − Unterschrieben − Nicht erschienen). Nur für laufende Kohorten (Schulung / ohne Schulung); ausgeschlossene Buckets zeigen „–".'],
    ];

    // Spaltengruppen als zweite Kopfzeile. Vierzehn Spalten sind lesbar, sobald
    // der Blick Absaetze bekommt — ohne Gruppen stehen fuenf verschiedene Arten
    // von Information gleichrangig nebeneinander.
    $colGroups = [
        ['label' => '', 'span' => 1, 'title' => ''],
        ['label' => 'Trichter', 'span' => 5,
         'title' => 'Der Weg durch den Prozess — jede Stufe ist eine Teilmenge der vorigen, die Farbe wird dabei dunkler.'],
        ['label' => 'Abzweige', 'span' => 2,
         'title' => 'Wege aus dem Trichter heraus, die keine Stufe sind.'],
        ['label' => 'Vertrag', 'span' => 2,
         'title' => 'Das Ziel: Vertrag verschickt und unterschrieben.'],
        ['label' => 'Stand', 'span' => 2,
         'title' => 'Was noch offen ist und was daraus geworden ist.'],
        ['label' => 'Belegung', 'span' => 2,
         'title' => 'Plätze des Schulungstermins — links nur die Bewerbungen dieser Zeile, rechts der Termin insgesamt.'],
    ];

    // 1 Zeilen-Spalte + Zahlen + Conversion + 2 Belegungs-Spalten
    $colSpanAll = count($colDefs) + 4;

    // Labels der Assigner-Zeilentypen (Spec §4). Jeder Typ ist sichtbar — nichts
    // wird verschluckt, auch 'unbekannter_status' nicht. Die Reihenfolge in der
    // Tabelle macht CohortViewModel (Anzeige-Reihenfolge, nicht die Kette).
    $typeLabels = [
        'geparkt' => 'Geparkt',
        'abgesagt' => 'Abgesagt',
        'dublette' => 'Dubletten',
        'unrouted' => 'Nicht zugeordnet (Eingang)',
        'import' => 'Import (Altbestand)',
        'ohne_datum' => 'Ohne Bewerbungsdatum',
        'unbekannter_status' => 'Unbekannter Buchungsstatus',
    ];

    $tiles = $this->tiles;
    $rangeSubtitle = ($this->filterFrom || $this->filterTo)
        ? trim(($this->filterFrom ? \Carbon\Carbon::parse($this->filterFrom)->format('d.m.Y') : '…')
            . ' – ' . ($this->filterTo ? \Carbon\Carbon::parse($this->filterTo)->format('d.m.Y') : '…'))
        : 'alle Zeiträume';

    $filterNote = 'Testbewerber sind immer ausgeschlossen. Bewerbungen ohne Datum bleiben trotz Zeitraum-Filter sichtbar und stehen in einer eigenen Zeile, damit die Summe vollständig bleibt.';
    $snapshotNote = 'Der Trichter zeigt den aktuellen Status jeder Bewerbung, keine Historie. Werte können zwischen zwei Aufrufen auch sinken, wenn sich ein Status ändert (z. B. eine bestätigte Buchung wird storniert). Jeder Spaltenkopf trägt seine Definition als Tooltip.';

    // Right-Censoring auf der Kachel folgt der Aggregat-Regel (siehe
    // CohortViewModel::isCensoredAggregate): grau nur, wenn JEDE Zeile der
    // Auswahl zu jung ist. Vorher war die Kachel dauerhaft grau, weil in einer
    // Gesamtsicht immer eine Bewerbung von heute dabei ist — ein Zustand, der
    // nie wechselt, liest sich als Fehler.
    $overallCensored = $this->isCensored($this->cohort['rows'], true);

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
         'muted' => false, 'title' => 'Aktive Bewerbungen ohne Schulungstermin — aufgeschlüsselt nach Phase in der Tabelle unter „Ohne Schulung".'],
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

<div class="space-y-6 p-6">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Filterleiste                                                       --}}
    {{-- ------------------------------------------------------------------ --}}
    {{-- relative z-30: hebt die Filterleiste (inkl. der absolut positionierten
         Select-Dropdowns) ueber die KPI-Kacheln darunter. Ohne eigenen
         Stacking-Context wird das z-50 der Dropdowns von spaeteren Siblings
         uebermalt (Overlay-Bug, Live-Check 2026-08-04). --}}
    <x-ui-panel title="Statistik" subtitle="Jede Zahl ist anklickbar und zeigt die Personen dahinter" class="relative z-30">
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
            <x-ui-input-date name="filterFrom" label="Von" size="sm" wire:model.live="filterFrom" />
            <x-ui-input-date name="filterTo" label="Bis" size="sm" wire:model.live="filterTo" />
            <x-ui-input-select
                name="ortFilter"
                label="Ort"
                size="sm"
                :options="$this->ortOptions"
                :nullable="true"
                nullLabel="alle Orte"
                wire:model.live="ortFilter"
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
        </div>
        <div class="mt-3 flex items-center justify-between gap-3">
            {{-- Kleingedrucktes zusammengefaltet: der Zeitraum bleibt sichtbar,
                 die Regeln dahinter stehen im Tooltip. --}}
            <div class="text-xs text-[color:var(--ui-muted)]">
                Zeitraum: {{ $rangeSubtitle }}
                <span class="ml-1 cursor-help" title="{{ $filterNote }}">ⓘ</span>
            </div>
            <x-ui-button variant="secondary-outline" size="sm" wire:click="resetFilters">
                Filter zurücksetzen
            </x-ui-button>
        </div>
    </x-ui-panel>

    {{-- ------------------------------------------------------------------ --}}
    {{-- KPI-Kacheln — lesen ausschliesslich aus dem Kohorten-Ergebnis      --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        @foreach ($kpis as $kpi)
            <x-ui-panel>
                @if ($kpi['column'] !== null)
                    <button
                        type="button"
                        wire:click="drill('{{ $kpi['token'] }}', '{{ $kpi['column'] }}', '{{ $kpi['label'] }}')"
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
    {{-- Kohorten-Tabelle: Ort → Tätigkeit → Zeilen in Anzeige-Reihenfolge   --}}
    {{-- ------------------------------------------------------------------ --}}
    <x-ui-panel title="Bewerbungen pro Schulung" subtitle="Gruppiert nach Ort und Tätigkeit — jede Summe ist die Addition der Zeilen darüber">
        {{-- Snapshot-Hinweis (Spec §6) auf eine Zeile eingekocht: ohne ihn liest
             man sinkende Zahlen als Fehler, als Bannerkasten war er aber lauter
             als die Tabelle selbst. --}}
        <div class="mb-2 text-xs text-[color:var(--ui-muted)]">
            Momentaufnahme des aktuellen Status, keine Historie
            <span class="ml-1 cursor-help" title="{{ $snapshotNote }}">ⓘ</span>
        </div>

        @if (count($this->cohort['rows']) === 0)
            <div class="py-10 text-center text-sm text-[color:var(--ui-muted)]">
                Keine Bewerbungen in dieser Auswahl.
            </div>
        @else
            {{-- Eigener Scroll-Container: macht den Kopf und die Gesamt-Zeile
                 verlaesslich klebend (sticky braucht einen Scroll-Vorfahren) und
                 haelt die Tabelle bei vielen Zeilen im Blick. --}}
            <div class="max-h-[75vh] overflow-auto rounded-lg border border-[var(--ui-border)]/60">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        {{-- Gruppenzeile: feste Hoehe h-7, weil die zweite Kopfzeile
                             mit top-7 darunter klebt. --}}
                        <tr class="bg-[var(--ui-muted-5)] text-[10px] uppercase tracking-widest text-[color:var(--ui-muted)]">
                            @foreach ($colGroups as $g)
                                <th colspan="{{ $g['span'] }}"
                                    @class([
                                        'sticky top-0 h-7 px-3 py-0 text-center font-semibold bg-[var(--ui-muted-5)]',
                                        'left-0 z-30 text-left' => $loop->first,
                                        'z-20 border-l border-[var(--ui-border)]/60' => !$loop->first,
                                    ])
                                    @if ($g['title']) title="{{ $g['title'] }}" @endif>
                                    {{ $g['label'] }}
                                </th>
                            @endforeach
                        </tr>
                        <tr class="border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)] text-left text-xs uppercase tracking-wide text-[var(--ui-muted)]">
                            <th class="sticky left-0 top-7 z-30 bg-[var(--ui-surface)] px-4 py-3"
                                title="Zeilentyp aus der Präzedenz-Kette (Spec §4): jede Bewerbung steckt in genau einer Zeile.">Zeile</th>
                            @foreach ($colDefs as $col)
                                <th @class([
                                        'sticky top-7 z-20 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom',
                                        'border-l border-[var(--ui-border)]/60' => !empty($col['gstart']),
                                    ])
                                    title="{{ $col['title'] }}">
                                    {{ $col['label'] }}
                                    <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                                </th>
                            @endforeach
                            <th class="sticky top-7 z-20 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom"
                                title="Unterschriften geteilt durch Bewerbungen dieser Zeile. Ausgegraut, solange die Kohorte jünger ist als der Median-Durchlauf — dann ist die Quote strukturell zu niedrig (Right-Censoring). Summen- und Gesamtzeilen werden nur ausgegraut, wenn ALLE enthaltenen Zeilen zu jung sind.">
                                Conversion
                                <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                            </th>
                            <th class="sticky top-7 z-20 border-l border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom"
                                title="Einheit: BEWERBUNGEN. Bewerbungen dieser Zeile mit platzbelegender Buchung auf diesem Termin (also innerhalb der aktuellen Filter-Auswahl) — Standby zählt nicht mit.">
                                Belegt (Zeile)
                                <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                            </th>
                            <th class="sticky top-7 z-20 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom"
                                title="Einheit: BUCHUNGEN. Platzbelegende Buchungen des Termins insgesamt nach zentraler Zählregel, unabhängig von Filtern und Gruppierung. Unterschied zur Spalte „Belegt (Zeile)“: eine Person mit zwei aktiven Buchungen auf denselben Termin zählt links 1, hier 2.">
                                Belegt (Termin)
                                <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                            </th>
                        </tr>
                    </thead>

                    @foreach ($this->groups as $ortKey => $group)
                        @php
                            $ort = $group['ort'];
                            $ortRows = [];
                            foreach ($group['activities'] as $actRows) {
                                $ortRows = array_merge($ortRows, $actRows);
                            }
                            $ortToken = $this->drillToken('ort', 'Summe ' . $ort, ['ort' => $ort]);
                            $ortIsFallback = $ort === 'ohne Ort' || $ort === 'ohne Ausschreibung';
                        @endphp
                        {{-- x-data auf dem tbody: die Aufklapp-Zustände der ohne_schulung-Buckets
                             liegen pro Ort-Gruppe in EINEM Alpine-Scope, weil sich der Scope
                             eines <tr> nicht auf Geschwister-<tr> erstreckt. Schlüssel ist der
                             Tätigkeits-INDEX, nicht der Name — Namen sind freier Nutzertext. --}}
                        <tbody x-data="{ openPhases: {} }" class="divide-y divide-[var(--ui-border)]/60">

                            {{-- Ort-Gruppen-Header. Der Inhalt klebt links mit, sonst
                                 scrollt die Ortsbezeichnung beim Blick nach rechts weg. --}}
                            <tr class="bg-[var(--ui-muted-5)]">
                                <td colspan="{{ $colSpanAll }}" class="px-4 pt-4 pb-2">
                                    <span class="sticky left-4 inline-block text-xs font-bold uppercase tracking-widest text-[color:var(--ui-secondary)]">
                                        {{ $ort }}
                                        @if ($ortIsFallback)
                                            <span class="ml-2 rounded-full bg-[var(--ui-surface)] px-2 py-0.5 text-[11px] font-medium normal-case tracking-normal text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60"
                                                  title="An der Stelle ist kein Standort gepflegt (oder es hängt keine Ausschreibung am Bewerber). Die Zeilen sind vollständig enthalten, nur nicht einem Ort zuzuordnen.">
                                                kein Standort gepflegt ⓘ
                                            </span>
                                        @endif
                                    </span>
                                </td>
                            </tr>

                            @foreach ($group['activities'] as $act => $actRows)
                                @php
                                    $actIndex = $loop->index;
                                    $schulungRows = array_values(array_filter($actRows, fn ($r) => $r['type'] === 'schulung'));
                                    $phaseRows = array_values(array_filter($actRows, fn ($r) => $r['type'] === 'ohne_schulung'));
                                    $bucketRows = array_values(array_filter($actRows, fn ($r) => $r['type'] !== 'schulung' && $r['type'] !== 'ohne_schulung'));
                                    $actIsFallback = $act === 'ohne Tätigkeit' || $act === 'ohne Ausschreibung';
                                @endphp

                                {{-- Tätigkeits-Zwischenheader --}}
                                <tr>
                                    <td colspan="{{ $colSpanAll }}" class="px-6 pt-3 pb-1">
                                        <span class="sticky left-6 inline-block text-xs font-semibold text-[color:var(--ui-secondary)]">
                                            {{ $act }}
                                            @if ($actIsFallback)
                                                <span class="ml-2 rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60"
                                                      title="An der Ausschreibung ist keine Tätigkeit gepflegt. Die Zeilen sind vollständig enthalten, nur nicht einer Tätigkeit zuzuordnen.">
                                                    keine Tätigkeit gepflegt ⓘ
                                                </span>
                                            @endif
                                        </span>
                                    </td>
                                </tr>

                                {{-- Schulungszeilen (Präzedenz-Kette Stufe 6) --}}
                                @foreach ($schulungRows as $row)
                                    @php
                                        $interviewId = $this->interviewIdOf($row);
                                        $meta = $this->interviewMeta[$interviewId] ?? null;
                                        $dateLabel = $meta && $meta['starts_at']
                                            ? $meta['starts_at']->format('d.m.Y H:i')
                                            : 'Termin ohne Datum';
                                        $rowPrefix = $dateLabel . ' · ' . ($meta['type'] ?? 'ohne Terminart');
                                        $rowToken = $this->drillToken('row', $rowPrefix, [
                                            'ort' => $ort, 'act' => $act, 'type' => $row['type'], 'key' => $row['key'],
                                        ]);

                                        $max = $meta['max'] ?? null;
                                        // Belegte Plaetze, NICHT Zeilen-Mitgliedschaft: count($row['ids'])
                                        // wuerde Standby mitzaehlen, waehrend "Belegt (Termin)"
                                        // (seatTaking-Scope) es nicht tut — die beiden Spalten haetten
                                        // sich widersprochen.
                                        // Warum die Subtraktion exakt der zentralen Zaehlregel entspricht:
                                        //  - standby ⊆ ids, weil der Assigner beide nur fuer dieselbe
                                        //    Schulungszeile aus derselben Buchung fuellt;
                                        //  - der Gewinner einer Schulungszeile ist nie 'cancelled'
                                        //    (isCohortAssigned schliesst SEAT_FREEING_STATUSES aus);
                                        //  - seat_released_at existiert laut Model-Invariante nur auf
                                        //    status='booked' (saving-Guard in RecInterviewBooking).
                                        // Damit gilt countsAsSeat ⇔ !standby, also belegt = ids − standby.
                                        $cohortTaken = count($row['ids']) - count($row['columns']['standby']);
                                        $totalTaken = $meta['seat_taking'] ?? null;
                                    @endphp
                                    <tr class="group transition-colors hover:bg-[var(--ui-muted-5)]">
                                        {{-- Erste Spalte klebt links: bei 14 Spalten scrollt die
                                             Tabelle horizontal, und ohne Anker weiss man nach
                                             wenigen Zeilen nicht mehr, welche Zeile man liest. --}}
                                        <td class="sticky left-0 z-10 border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-6 py-2 text-[color:var(--ui-secondary)] group-hover:bg-[var(--ui-muted-5)]">
                                            <div class="flex items-center gap-2 whitespace-nowrap">
                                                <span class="font-semibold tabular-nums">{{ $dateLabel }}</span>
                                                <span class="rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60">
                                                    {{ $meta['type'] ?? 'ohne Terminart' }}
                                                </span>
                                                @include('recruiting::livewire.statistics.markers', ['rows' => [$row], 'token' => $rowToken, 'prefix' => $rowPrefix])
                                            </div>
                                            @if (!empty($meta['location']))
                                                {{-- Adresse in eigener Zeile und gekuerzt: die vollen
                                                     Strings enthalten Treffpunktbeschreibungen und
                                                     schoben die Zahlen aus dem Blick. --}}
                                                <div class="mt-0.5 max-w-[18rem] truncate text-xs text-[color:var(--ui-muted)]"
                                                     title="Ort des Termins (nur Information — gruppiert wird nach dem Ort der Stelle): {{ $meta['location'] }}">
                                                    {{ $meta['location'] }}
                                                </div>
                                            @endif
                                        </td>
                                        @include('recruiting::livewire.statistics.cells', ['rows' => [$row], 'token' => $rowToken, 'prefix' => $rowPrefix, 'isTotal' => false])
                                        @include('recruiting::livewire.statistics.conversion', ['rows' => [$row], 'isTotal' => false])
                                        @include('recruiting::livewire.statistics.meter', [
                                            'taken' => $cohortTaken, 'max' => $max, 'borderLeft' => true,
                                            'title' => 'Belegt (Zeile) — Einheit: Bewerbungen dieser Zeile mit platzbelegender Buchung; Standby zählt nicht mit',
                                        ])
                                        @include('recruiting::livewire.statistics.meter', [
                                            'taken' => $totalTaken, 'max' => $max, 'borderLeft' => false,
                                            'title' => 'Belegt (Termin) — Einheit: alle platzbelegenden Buchungen dieses Termins, unabhängig von Filtern',
                                        ])
                                    </tr>
                                @endforeach

                                {{-- ohne_schulung: Summenzeile + aufklappbare Phasen-Aufschlüsselung --}}
                                @if ($phaseRows !== [])
                                    @php
                                        $bucketPrefix = 'Ohne Schulung';
                                        $bucketToken = $this->drillToken('type', $bucketPrefix, [
                                            'ort' => $ort, 'act' => $act, 'type' => 'ohne_schulung',
                                        ]);
                                    @endphp
                                    <tr class="group transition-colors hover:bg-[var(--ui-muted-5)]">
                                        <td class="sticky left-0 z-10 border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-6 py-2 text-[color:var(--ui-secondary)] group-hover:bg-[var(--ui-muted-5)]">
                                            <button
                                                type="button"
                                                x-on:click="openPhases[{{ $actIndex }}] = !openPhases[{{ $actIndex }}]"
                                                class="inline-flex items-center gap-1 font-medium hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] rounded cursor-pointer"
                                            >
                                                <span x-text="openPhases[{{ $actIndex }}] ? '▾' : '▸'" class="text-[color:var(--ui-muted)]">▸</span>
                                                {{ $bucketPrefix }}
                                                <span class="text-xs text-[color:var(--ui-muted)]">({{ count($phaseRows) }} Phasen)</span>
                                            </button>
                                            @include('recruiting::livewire.statistics.markers', ['rows' => $phaseRows, 'token' => $bucketToken, 'prefix' => $bucketPrefix])
                                        </td>
                                        @include('recruiting::livewire.statistics.cells', ['rows' => $phaseRows, 'token' => $bucketToken, 'prefix' => $bucketPrefix, 'isTotal' => false])
                                        @include('recruiting::livewire.statistics.conversion', ['rows' => $phaseRows, 'isTotal' => false])
                                        @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => true, 'title' => 'Belegung gilt nur für Schulungstermine'])
                                        @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => false, 'title' => 'Belegung gilt nur für Schulungstermine'])
                                    </tr>

                                    @foreach ($phaseRows as $row)
                                        @php
                                            // key = "ohne_schulung:{order}|{phaseName}"
                                            $keyTail = substr($row['key'], strlen('ohne_schulung:'));
                                            $sep = strpos($keyTail, '|');
                                            $phaseName = $sep === false ? $keyTail : substr($keyTail, $sep + 1);
                                            $phasePrefix = 'Ohne Schulung · ' . $phaseName;
                                            $phaseToken = $this->drillToken('row', $phasePrefix, [
                                                'ort' => $ort, 'act' => $act, 'type' => $row['type'], 'key' => $row['key'],
                                            ]);
                                        @endphp
                                        {{-- style statt x-cloak: im Projekt ist keine [x-cloak]-CSS-Regel
                                             definiert (vgl. modal.blade.php, das denselben Weg geht) --}}
                                        <tr x-show="openPhases[{{ $actIndex }}]" style="display: none;" class="group transition-colors hover:bg-[var(--ui-muted-5)]">
                                            <td class="sticky left-0 z-10 border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-10 py-2 text-xs text-[color:var(--ui-muted)] group-hover:bg-[var(--ui-muted-5)]">
                                                Phase: <span class="text-[color:var(--ui-secondary)]">{{ $phaseName }}</span>
                                                @include('recruiting::livewire.statistics.markers', ['rows' => [$row], 'token' => $phaseToken, 'prefix' => $phasePrefix])
                                            </td>
                                            @include('recruiting::livewire.statistics.cells', ['rows' => [$row], 'token' => $phaseToken, 'prefix' => $phasePrefix, 'isTotal' => false])
                                            @include('recruiting::livewire.statistics.conversion', ['rows' => [$row], 'isTotal' => false])
                                            @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => true, 'title' => 'Belegung gilt nur für Schulungstermine'])
                                            @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => false, 'title' => 'Belegung gilt nur für Schulungstermine'])
                                        </tr>
                                    @endforeach
                                @endif

                                {{-- Übrige Buckets, bereits in Anzeige-Reihenfolge sortiert.
                                     Bewusst gedeckt gesetzt: das sind Zustands-Eimer, keine
                                     Termine — sie sollen als Nebenschauplatz lesbar sein,
                                     nicht mit den Schulungszeilen konkurrieren. --}}
                                @foreach ($bucketRows as $row)
                                    @php
                                        $bucketLabel = $typeLabels[$row['type']] ?? $row['type'];
                                        $bRowToken = $this->drillToken('row', $bucketLabel, [
                                            'ort' => $ort, 'act' => $act, 'type' => $row['type'], 'key' => $row['key'],
                                        ]);
                                    @endphp
                                    <tr class="group transition-colors hover:bg-[var(--ui-muted-5)]">
                                        <td class="sticky left-0 z-10 border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-6 py-2 text-[color:var(--ui-muted)] group-hover:bg-[var(--ui-muted-5)]">
                                            <span class="font-medium">{{ $bucketLabel }}</span>
                                            @if ($row['type'] === 'unbekannter_status')
                                                <span class="ml-2 rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60"
                                                      title="Buchung mit einem Status, den die Zählregel nicht kennt — bewusst sichtbar statt verschluckt. Bitte prüfen.">
                                                    prüfen ⓘ
                                                </span>
                                            @endif
                                            @include('recruiting::livewire.statistics.markers', ['rows' => [$row], 'token' => $bRowToken, 'prefix' => $bucketLabel])
                                        </td>
                                        @include('recruiting::livewire.statistics.cells', ['rows' => [$row], 'token' => $bRowToken, 'prefix' => $bucketLabel, 'isTotal' => false])
                                        @include('recruiting::livewire.statistics.conversion', ['rows' => [$row], 'isTotal' => false])
                                        @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => true, 'title' => 'Belegung gilt nur für Schulungstermine'])
                                        @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => false, 'title' => 'Belegung gilt nur für Schulungstermine'])
                                    </tr>
                                @endforeach
                            @endforeach

                            {{-- Summe je Ort-Gruppe = Addition der Zeilen dieses Orts --}}
                            <tr class="group border-t-2 border-[var(--ui-border)] bg-[var(--ui-muted-5)] font-semibold">
                                <td class="sticky left-0 z-10 border-b border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)] px-4 py-2 text-xs uppercase tracking-wide text-[color:var(--ui-secondary)]">
                                    Summe {{ $ort }}
                                </td>
                                @include('recruiting::livewire.statistics.cells', ['rows' => $ortRows, 'token' => $ortToken, 'prefix' => 'Summe ' . $ort, 'isTotal' => true])
                                @include('recruiting::livewire.statistics.conversion', ['rows' => $ortRows, 'isTotal' => true])
                                @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => true, 'title' => 'Belegung gilt nur für einzelne Schulungstermine'])
                                @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => false, 'title' => 'Belegung gilt nur für einzelne Schulungstermine'])
                            </tr>
                        </tbody>
                    @endforeach

                    {{-- Gesamt klebt am unteren Rand: die Bezugsgroesse bleibt beim
                         Scrollen durch lange Tabellen sichtbar. --}}
                    <tfoot>
                        <tr class="border-t-2 border-[var(--ui-border)] font-bold">
                            <td class="sticky bottom-0 left-0 z-30 bg-[var(--ui-surface)] px-4 py-3 text-[color:var(--ui-secondary)]">
                                Gesamt
                                @php
                                    $rowSum = $this->countIn($this->cohort['rows'], 'ids');
                                    $totalIds = count($this->cohort['total_ids']);
                                @endphp
                                @if ($rowSum !== $totalIds)
                                    {{-- Rekonziliations-Invariante verletzt: Zeilensumme muss die
                                         Gesamtmenge sein. Sichtbar machen statt still korrigieren. --}}
                                    <span class="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-bold text-red-800"
                                          title="Rekonziliation verletzt: Summe der Zeilen ({{ $rowSum }}) weicht von der Gesamtmenge ({{ $totalIds }}) ab.">
                                        Rekonziliation: {{ $rowSum }} ≠ {{ $totalIds }}
                                    </span>
                                @endif
                            </td>
                            @include('recruiting::livewire.statistics.cells', ['rows' => $this->cohort['rows'], 'token' => $allToken, 'prefix' => 'Gesamt', 'isTotal' => true])
                            @include('recruiting::livewire.statistics.conversion', ['rows' => $this->cohort['rows'], 'isTotal' => true])
                            @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => true, 'pad' => 'px-3 py-3', 'title' => 'Belegung gilt nur für einzelne Schulungstermine'])
                            @include('recruiting::livewire.statistics.meter', ['taken' => null, 'max' => null, 'borderLeft' => false, 'pad' => 'px-3 py-3', 'title' => 'Belegung gilt nur für einzelne Schulungstermine'])
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-ui-panel>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Drill-down-Modal                                                   --}}
    {{-- ------------------------------------------------------------------ --}}
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
