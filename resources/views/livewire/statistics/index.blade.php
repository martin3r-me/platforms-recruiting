@php
    // Spaltendefinition an EINER Stelle — thead, jede Datenzeile und beide
    // Summen-Zeilen lesen daraus. Farbklassen bewusst als Literale (Tailwind-JIT
    // findet keine zusammengesetzten Klassennamen).
    // Jede Spalte traegt ihre Definition als Tooltip (Spec §6): die Zahlen sind
    // ohne die Definition mehrdeutig, und der Tooltip ist der einzige Ort, an dem
    // sie mitreist.
    $colDefs = [
        ['key' => 'ids',                'label' => 'Bewerbungen',    'on' => 'bg-blue-50 text-blue-700',       'total' => 'bg-blue-100 text-blue-800',
         'title' => 'Alle Bewerbungen dieser Zeile — Testbewerber sind immer ausgeschlossen, Bewerbungen ohne Datum stehen in einer eigenen Zeile.'],
        ['key' => 'kontaktiert',        'label' => 'Kontaktiert',    'on' => 'bg-indigo-50 text-indigo-700',   'total' => 'bg-indigo-100 text-indigo-800',
         'title' => 'Anreicherungs-Proxy (enrichment_status), kein Kontaktnachweis'],
        ['key' => 'gebucht',            'label' => 'Gebucht',        'on' => 'bg-purple-50 text-purple-700',   'total' => 'bg-purple-100 text-purple-800',
         'title' => 'Hat eine kohorten-relevante Buchung auf diesem Termin (Rang ≥ 1: booked/registered und höher). Storno zählt nicht.'],
        ['key' => 'bestaetigt',         'label' => 'Bestätigt',      'on' => 'bg-green-50 text-green-700',     'total' => 'bg-green-100 text-green-800',
         'title' => 'confirmed/attended/no_show — registered zählt bewusst nicht (mehrdeutig, siehe Auftrag ③). Wert ist ein Status-Snapshot und kann zwischen Aufrufen sinken.'],
        ['key' => 'teilgenommen',       'label' => 'Teilgenommen',   'on' => 'bg-emerald-50 text-emerald-700', 'total' => 'bg-emerald-100 text-emerald-800',
         'title' => 'Status attended (Rang 3). No-Show ist ein Abzweig und zählt hier NICHT mit.'],
        ['key' => 'standby',            'label' => 'Standby',        'on' => 'bg-amber-50 text-amber-700',     'total' => 'bg-amber-100 text-amber-800',
         'title' => 'Buchung besteht, belegt aber keinen Platz mehr (booked + seat_released_at) — zählt in keiner der beiden Kapazitätsspalten mit.'],
        ['key' => 'no_show',            'label' => 'No-Show',        'on' => 'bg-red-50 text-red-700',         'total' => 'bg-red-100 text-red-800',
         'title' => 'Status no_show — gebucht und bestätigt, aber nicht erschienen. Gilt als abgeschlossen, zählt also nicht als „noch offen".'],
        ['key' => 'vertrag_verschickt', 'label' => 'Vertrag raus',   'on' => 'bg-orange-50 text-orange-700',   'total' => 'bg-orange-100 text-orange-800',
         'title' => 'Mindestens ein Vertrag mit sent_at. Stornierte Verträge (status=cancelled) sind ausgeschlossen.'],
        ['key' => 'unterschrieben',     'label' => 'Unterschrieben', 'on' => 'bg-teal-50 text-teal-700',       'total' => 'bg-teal-100 text-teal-800',
         'title' => 'Mindestens ein Vertrag mit signed_at — das Ziel des Funnels.'],
        // Neutral-grau statt einer Funnel-Farbe: "noch offen" ist kein Fortschritt,
        // sondern das unentschiedene Restfeld. Der 100/700-Ton ist zugleich klar von
        // der Null-Darstellung (gray-50/400) unterscheidbar.
        ['key' => 'offen_ids',          'label' => 'Noch offen',     'on' => 'bg-gray-100 text-gray-700',      'total' => 'bg-gray-200 text-gray-800',
         'onlyRunning' => true,
         'title' => 'Weder unterschrieben noch No-Show — die Bewerbungen, deren Ausgang noch offen ist (Bewerbungen − Unterschrieben − No-Show). Nur für laufende Kohorten (Schulung / ohne Schulung); ausgeschlossene Buckets zeigen „–".'],
    ];

    // 1 Zeilen-Spalte + Zahlen + Conversion + 2 Kapazitaets-Spalten
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
        : 'Alle Zeiträume';

    // Right-Censoring gilt fuer die Kachel GENAUSO wie fuer die Gesamt-Zeile der
    // Tabelle — beide zeigen dieselbe Zahl. Waere nur die Tabellenzelle grau,
    // widerspraeche sich die Seite selbst. Dieselbe Zeilenmenge, derselbe Median,
    // derselbe Tooltip-Text (censorNote()).
    $overallCensored = $this->isCensored($this->cohort['rows']);

    $kpis = [
        ['label' => 'Bewerbungen',          'value' => $tiles['bewerbungen'],        'column' => 'ids',    'muted' => false, 'title' => null],
        ['label' => 'In Schulung gebucht',  'value' => $tiles['gebucht'],            'column' => 'gebucht','muted' => false, 'title' => null],
        ['label' => 'Unterschriften',       'value' => $tiles['unterschrieben'],     'column' => 'unterschrieben', 'muted' => false, 'title' => null],
        ['label' => 'Conversion',           'value' => $tiles['conversion'] . ' %',  'column' => null,
         'muted' => $overallCensored, 'title' => $overallCensored ? $this->censorNote() : 'Unterschriften geteilt durch alle Bewerbungen der aktuellen Auswahl.'],
        ['label' => 'Time-to-Hire (Median)','value' => $tiles['tth_median'] !== null ? $tiles['tth_median'] . ' Tage' : '–', 'column' => null,
         'muted' => false, 'title' => 'Median der Tage von Bewerbungseingang bis Unterschrift — Grundlage der Right-Censoring-Schwelle.'],
    ];
    $allToken = $this->drillToken('all', 'Gesamt');
@endphp

<div class="space-y-6 p-6">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Filterleiste                                                       --}}
    {{-- ------------------------------------------------------------------ --}}
    {{-- relative z-30: hebt die Filterleiste (inkl. der absolut positionierten
         Select-Dropdowns) ueber die KPI-Kacheln darunter. Ohne eigenen
         Stacking-Context wird das z-50 der Dropdowns von spaeteren Siblings
         uebermalt (Overlay-Bug, Live-Check 2026-08-04). --}}
    <x-ui-panel title="Statistik" subtitle="Rekonzilierte Kohorten-Sicht — jede Zahl ist anklickbar" class="relative z-30">
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
            <div class="text-xs text-[color:var(--ui-muted)]">
                Zeitraum: {{ $rangeSubtitle }} · Testbewerber sind immer ausgeschlossen ·
                Bewerbungen ohne Datum bleiben trotz Zeitraum-Filter sichtbar (eigene Zeile).
            </div>
            <x-ui-button variant="secondary-outline" size="sm" wire:click="resetFilters">
                Filter zurücksetzen
            </x-ui-button>
        </div>
    </x-ui-panel>

    {{-- ------------------------------------------------------------------ --}}
    {{-- KPI-Kacheln — lesen ausschliesslich aus dem Kohorten-Ergebnis      --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
        @foreach ($kpis as $kpi)
            <x-ui-panel>
                @if ($kpi['column'] !== null)
                    <button
                        type="button"
                        wire:click="drill('{{ $allToken }}', '{{ $kpi['column'] }}', '{{ $kpi['label'] }}')"
                        wire:loading.attr="disabled"
                        class="text-2xl font-semibold text-[color:var(--ui-secondary)] hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] rounded cursor-pointer"
                        title="{{ $kpi['label'] }}: Personen anzeigen"
                    >{{ $kpi['value'] }}</button>
                @else
                    {{-- gleiche Ausgrau-Optik wie die Conversion-Zelle der Tabelle --}}
                    <div class="text-2xl font-semibold {{ $kpi['muted'] ? 'text-gray-400 italic' : 'text-[color:var(--ui-secondary)]' }}"
                         @if ($kpi['title']) title="{{ $kpi['title'] }}" @endif>{{ $kpi['value'] }}</div>
                @endif
                <div class="text-sm text-[color:var(--ui-muted)]">
                    {{ $kpi['label'] }}
                    @if ($kpi['muted'])
                        <span class="cursor-help" title="{{ $kpi['title'] }}">ⓘ</span>
                    @endif
                </div>
            </x-ui-panel>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Kohorten-Tabelle: Ort → Tätigkeit → Zeilen in Anzeige-Reihenfolge   --}}
    {{-- ------------------------------------------------------------------ --}}
    <x-ui-panel title="Kohorten-Tabelle" subtitle="Gruppiert nach Ort und Tätigkeit — die Summen sind die Addition der Zeilen">
        {{-- Snapshot-Hinweis (Spec §6): der Funnel liest den AKTUELLEN Status, nicht
             eine Historie. Wer heute abgesagt wird, verschwindet morgen aus
             „Bestätigt" — ohne diesen Hinweis liest man sinkende Zahlen als Fehler. --}}
        <div class="mb-3 rounded-lg border border-[var(--ui-border)] bg-[var(--ui-muted-5)] px-3 py-2 text-xs text-[color:var(--ui-muted)]">
            <span class="font-semibold text-[color:var(--ui-secondary)]">Snapshot, keine Historie:</span>
            Der Funnel zeigt den aktuellen Status jeder Bewerbung — Werte können zwischen zwei
            Aufrufen auch <em>sinken</em>, wenn sich ein Status ändert. Spaltenköpfe (ⓘ) tragen
            die jeweilige Definition als Tooltip.
        </div>

        @if (count($this->cohort['rows']) === 0)
            <div class="py-10 text-center text-sm text-[color:var(--ui-muted)]">
                Keine Bewerbungen in dieser Auswahl.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3" title="Zeilentyp aus der Präzedenz-Kette (Spec §4): jede Bewerbung steckt in genau einer Zeile.">Zeile</th>
                            @foreach ($colDefs as $col)
                                <th class="px-3 py-3 text-center" title="{{ $col['title'] }}">
                                    {{ $col['label'] }}
                                    <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                                </th>
                            @endforeach
                            <th class="px-3 py-3 text-center" title="Unterschriften geteilt durch Bewerbungen dieser Zeile. Ausgegraut, solange die Kohorte jünger ist als der Median-Durchlauf — dann ist die Quote strukturell zu niedrig (Right-Censoring).">
                                Conversion
                                <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                            </th>
                            <th class="px-3 py-3 text-center" title="Einheit: BEWERBER. Bewerbungen dieser Zeile mit platzbelegender Buchung auf diesem Termin (also innerhalb der aktuellen Filter-Auswahl) — Standby zählt nicht mit.">
                                Kohorte
                                <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                            </th>
                            <th class="px-3 py-3 text-center" title="Einheit: BUCHUNGEN. Platzbelegende Buchungen des Termins insgesamt nach zentraler Zählregel, unabhängig von Filtern und Gruppierung. Unterschied zur Spalte „Kohorte“: eine Person mit zwei aktiven Buchungen auf denselben Termin zählt links 1, hier 2.">
                                Termin gesamt
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
                        @endphp
                        {{-- x-data auf dem tbody: die Aufklapp-Zustände der ohne_schulung-Buckets
                             liegen pro Ort-Gruppe in EINEM Alpine-Scope, weil sich der Scope
                             eines <tr> nicht auf Geschwister-<tr> erstreckt. Schlüssel ist der
                             Tätigkeits-INDEX, nicht der Name — Namen sind freier Nutzertext. --}}
                        <tbody x-data="{ openPhases: {} }" class="divide-y divide-[var(--ui-border)]/60">

                            {{-- Ort-Gruppen-Header --}}
                            <tr class="bg-[var(--ui-muted-5)]">
                                <td colspan="{{ $colSpanAll }}" class="px-4 py-2 text-xs font-bold uppercase tracking-wide text-[color:var(--ui-secondary)]">
                                    {{ $ort }}
                                    @if ($ort === 'ohne Ort' || $ort === 'ohne Ausschreibung')
                                        <span class="ml-2 rounded-full bg-orange-50 px-2 py-0.5 text-[11px] font-medium normal-case text-orange-700">
                                            Befund, kein Standort
                                        </span>
                                    @endif
                                </td>
                            </tr>

                            @foreach ($group['activities'] as $act => $actRows)
                                @php
                                    $actIndex = $loop->index;
                                    $schulungRows = array_values(array_filter($actRows, fn ($r) => $r['type'] === 'schulung'));
                                    $phaseRows = array_values(array_filter($actRows, fn ($r) => $r['type'] === 'ohne_schulung'));
                                    $bucketRows = array_values(array_filter($actRows, fn ($r) => $r['type'] !== 'schulung' && $r['type'] !== 'ohne_schulung'));
                                @endphp

                                {{-- Tätigkeits-Zwischenheader --}}
                                <tr>
                                    <td colspan="{{ $colSpanAll }}" class="px-6 pt-3 pb-1 text-xs font-semibold text-[color:var(--ui-muted)]">
                                        {{ $act }}
                                        @if ($act === 'ohne Tätigkeit' || $act === 'ohne Ausschreibung')
                                            <span class="ml-2 rounded-full bg-orange-50 px-2 py-0.5 text-[11px] font-medium text-orange-700">
                                                Befund, keine Tätigkeit
                                            </span>
                                        @endif
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
                                        // wuerde Standby mitzaehlen, waehrend "Termin gesamt"
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
                                        // Auslastung darf >100 % anzeigen — Überbuchung ist ein
                                        // Befund und wird NICHT geklammert (Spec §4).
                                        $cohortPct = $max ? (int) round($cohortTaken / $max * 100) : null;
                                        $totalPct = ($max && $totalTaken !== null) ? (int) round($totalTaken / $max * 100) : null;
                                    @endphp
                                    <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                        <td class="px-6 py-2 text-[color:var(--ui-secondary)]">
                                            <span class="font-medium">{{ $dateLabel }}</span>
                                            <span class="ml-2 rounded-full bg-purple-50 px-2 py-0.5 text-[11px] font-medium text-purple-700">
                                                {{ $meta['type'] ?? 'ohne Terminart' }}
                                            </span>
                                            @if (!empty($meta['location']))
                                                <span class="ml-2 text-xs text-[color:var(--ui-muted)]" title="Ort des Termins (nur Information — gruppiert wird nach dem Ort der Stelle)">
                                                    Termin-Ort: {{ $meta['location'] }}
                                                </span>
                                            @endif
                                            @include('recruiting::livewire.statistics.markers', ['rows' => [$row], 'token' => $rowToken, 'prefix' => $rowPrefix])
                                        </td>
                                        @include('recruiting::livewire.statistics.cells', ['rows' => [$row], 'token' => $rowToken, 'prefix' => $rowPrefix, 'isTotal' => false])
                                        @include('recruiting::livewire.statistics.conversion', ['rows' => [$row], 'isTotal' => false])

                                        {{-- Kapazität "Kohorte": belegt/max innerhalb dieser Zeile --}}
                                        <td class="px-3 py-2 text-center whitespace-nowrap text-xs">
                                            @if ($max)
                                                <span class="font-medium text-[color:var(--ui-secondary)]">{{ $cohortTaken }} / {{ $max }}</span>
                                                <span class="{{ $cohortPct > 100 ? 'text-red-600 font-semibold' : 'text-[color:var(--ui-muted)]' }}">
                                                    · {{ $cohortPct }} %
                                                </span>
                                            @else
                                                <span class="text-[color:var(--ui-muted)]" title="Kein max_participants gesetzt — unbegrenzt">{{ $cohortTaken }} / ∞</span>
                                            @endif
                                        </td>

                                        {{-- Kapazität "Termin gesamt": zentrale Zählregel des Termins --}}
                                        <td class="px-3 py-2 text-center whitespace-nowrap text-xs">
                                            @if ($totalTaken === null)
                                                <span class="text-[color:var(--ui-muted)]">–</span>
                                            @elseif ($max)
                                                <span class="font-medium text-[color:var(--ui-secondary)]">{{ $totalTaken }} / {{ $max }}</span>
                                                <span class="{{ $totalPct > 100 ? 'text-red-600 font-semibold' : 'text-[color:var(--ui-muted)]' }}">
                                                    · {{ $totalPct }} %
                                                </span>
                                            @else
                                                <span class="text-[color:var(--ui-muted)]" title="Kein max_participants gesetzt — unbegrenzt">{{ $totalTaken }} / ∞</span>
                                            @endif
                                        </td>
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
                                    <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                        <td class="px-6 py-2 text-[color:var(--ui-secondary)]">
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
                                        <td class="px-3 py-2 text-center text-xs text-[color:var(--ui-muted)]">–</td>
                                        <td class="px-3 py-2 text-center text-xs text-[color:var(--ui-muted)]">–</td>
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
                                        <tr x-show="openPhases[{{ $actIndex }}]" style="display: none;" class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                            <td class="px-10 py-2 text-xs text-[color:var(--ui-secondary)]">
                                                Phase: {{ $phaseName }}
                                                @include('recruiting::livewire.statistics.markers', ['rows' => [$row], 'token' => $phaseToken, 'prefix' => $phasePrefix])
                                            </td>
                                            @include('recruiting::livewire.statistics.cells', ['rows' => [$row], 'token' => $phaseToken, 'prefix' => $phasePrefix, 'isTotal' => false])
                                            @include('recruiting::livewire.statistics.conversion', ['rows' => [$row], 'isTotal' => false])
                                            <td class="px-3 py-2 text-center text-xs text-[color:var(--ui-muted)]">–</td>
                                            <td class="px-3 py-2 text-center text-xs text-[color:var(--ui-muted)]">–</td>
                                        </tr>
                                    @endforeach
                                @endif

                                {{-- Übrige Buckets, bereits in Anzeige-Reihenfolge sortiert --}}
                                @foreach ($bucketRows as $row)
                                    @php
                                        $bucketLabel = $typeLabels[$row['type']] ?? $row['type'];
                                        $bRowToken = $this->drillToken('row', $bucketLabel, [
                                            'ort' => $ort, 'act' => $act, 'type' => $row['type'], 'key' => $row['key'],
                                        ]);
                                    @endphp
                                    <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                        <td class="px-6 py-2 text-[color:var(--ui-secondary)]">
                                            <span class="font-medium">{{ $bucketLabel }}</span>
                                            @if ($row['type'] === 'unbekannter_status')
                                                <span class="ml-2 rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-700"
                                                      title="Buchung mit einem Status, den die Zählregel nicht kennt — bewusst sichtbar statt verschluckt">
                                                    prüfen
                                                </span>
                                            @endif
                                            @include('recruiting::livewire.statistics.markers', ['rows' => [$row], 'token' => $bRowToken, 'prefix' => $bucketLabel])
                                        </td>
                                        @include('recruiting::livewire.statistics.cells', ['rows' => [$row], 'token' => $bRowToken, 'prefix' => $bucketLabel, 'isTotal' => false])
                                        @include('recruiting::livewire.statistics.conversion', ['rows' => [$row], 'isTotal' => false])
                                        <td class="px-3 py-2 text-center text-xs text-[color:var(--ui-muted)]">–</td>
                                        <td class="px-3 py-2 text-center text-xs text-[color:var(--ui-muted)]">–</td>
                                    </tr>
                                @endforeach
                            @endforeach

                            {{-- Summe je Ort-Gruppe = Addition der Zeilen dieses Orts --}}
                            <tr class="border-t-2 border-[var(--ui-border)] bg-[var(--ui-muted-5)] font-semibold">
                                <td class="px-4 py-2 text-[color:var(--ui-secondary)]">Summe {{ $ort }}</td>
                                @include('recruiting::livewire.statistics.cells', ['rows' => $ortRows, 'token' => $ortToken, 'prefix' => 'Summe ' . $ort, 'isTotal' => true])
                                @include('recruiting::livewire.statistics.conversion', ['rows' => $ortRows, 'isTotal' => true])
                                <td class="px-3 py-2 text-center text-xs text-[color:var(--ui-muted)]">–</td>
                                <td class="px-3 py-2 text-center text-xs text-[color:var(--ui-muted)]">–</td>
                            </tr>
                        </tbody>
                    @endforeach

                    <tfoot>
                        <tr class="border-t-2 border-[var(--ui-border)] font-bold">
                            <td class="px-4 py-3 text-[color:var(--ui-secondary)]">
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
                            <td class="px-3 py-3 text-center text-xs text-[color:var(--ui-muted)]">–</td>
                            <td class="px-3 py-3 text-center text-xs text-[color:var(--ui-muted)]">–</td>
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
                        <span class="text-xs text-[color:var(--ui-muted)] whitespace-nowrap">
                            {{ $applicant->applied_at?->format('d.m.Y') ?? 'ohne Datum' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui-modal>
</div>
