{{--
    TABELLE 2 der Statistik-Seite: eine Zeile je SCHULUNGSTERMIN mit Belegung,
    Trichter und aufklappbarer HERKUNFT der Teilnehmer. Sie beantwortet die
    Frage, die Tabelle 1 offen laesst — „wer sitzt in diesem Termin, und aus
    welchen Ausschreibungen kommen die Leute?“.

    ZWEI QUELLEN, und die Trennung ist der wichtigste Satz dieser Datei:
      - die TRICHTER-Zahlen kommen aus denselben Assigner-Zeilen wie Tabelle 1
        (CohortViewModel::interviewCohorts), also aus EINER Zaehlung. Zwei
        Tabellen, die dieselben Menschen unterschiedlich zaehlen, waeren derselbe
        Fehler, wegen dem diese Seite gebaut wird;
      - die BELEGUNG (IST/SOLL) kommt aus der Termin-Query, weil Plaetze eine
        Eigenschaft des TERMINS sind und nicht der Kohorte. Sie ignoriert alle
        Filter der Seite.
    Die beiden Zahlen koennen deshalb auseinandergehen — ein Testbewerber belegt
    einen Platz und steckt in keiner Kohorte — und duerfen NICHT gegeneinander
    gerechnet werden. Der Spaltenkopf sagt das auch dem Leser.

    HERKUNFT: die Unterzeilen sind genau die Assigner-Zeilen des Termins, nach
    Ausschreibung gruppiert (dieselbe Methode, die Tabelle 1 fuer ihre Zeilen
    nimmt). Ihre Summe ist die Zeile des Termins, per Konstruktion. Sie tragen
    KEINE Kapazitaet — die Plaetze gehoeren dem Termin, nicht der Ausschreibung.

    Dieses Partial bringt seinen eigenen x-ui-panel mit und ist damit ein
    fertiger Seitenabschnitt (per @include eingebunden, ohne weitere Huelle).

    Erwartet: keine Variablen — alles kommt aus der Livewire-Komponente
    ($this->interviewTable, $this->phaseLabels).
--}}
@php
    // Farbrampe der Phasen-Spalten wie in der Ausschreibungs-Tabelle: der
    // Trichter ist eine Abfolge, also EINE Farbe in zunehmender Saettigung.
    // Literale, weil Tailwinds JIT zusammengesetzte Klassennamen nicht findet.
    $phaseTints = [
        ['on' => 'bg-sky-50 text-sky-900',  'total' => 'bg-sky-100 text-sky-950'],
        ['on' => 'bg-sky-100 text-sky-900', 'total' => 'bg-sky-200 text-sky-950'],
        ['on' => 'bg-sky-200 text-sky-900', 'total' => 'bg-sky-300 text-sky-950'],
        ['on' => 'bg-sky-300 text-sky-950', 'total' => 'bg-sky-400 text-sky-950'],
        ['on' => 'bg-sky-400 text-sky-950', 'total' => 'bg-sky-500 text-sky-950'],
    ];

    // Phasen-Spalten aus dem Phasensatz der gefilterten Filiale. Der Zugriff ist
    // ORDER-QUALIFIZIERT (phaseColumnKey) — die Spalte `phase_reached` ist
    // verschachtelt, und ein flaches count() darauf zaehlt Phasen statt
    // Bewerbungen. CohortViewModel wirft bei flachem Zugriff absichtlich.
    $phaseDefs = [];
    $phaseIndex = 0;
    foreach ($this->phaseLabels as $phaseOrder => $phaseName) {
        $tint = $phaseTints[min($phaseIndex, count($phaseTints) - 1)];
        // Name UNVERAENDERT: Kopf, Tooltip und Modal-Titel muessen zeigen, was HR
        // eingetragen hat. Das Quoting im wire:click loest @js in cells.blade.php.
        $phaseName = (string) $phaseName;
        $phaseDefs[] = [
            'key' => $this->phaseColumnKey((int) $phaseOrder),
            'label' => $phaseName,
            'on' => $tint['on'],
            'total' => $tint['total'],
            'title' => 'Teilnehmer dieses Termins, die Phase ' . $phaseOrder . ' („' . $phaseName . '“) erreicht haben — '
                . 'kumulativ: wer weiter ist, zählt hier mit. NETTO, also nur laufende Kohorten.',
        ];
        $phaseIndex++;
    }

    // Spaltendefinition an EINER Stelle — thead, Datenzeilen, Herkunfts-Zeilen
    // und Gesamt-Zeile lesen daraus. Farbsystem wie in den anderen Tabellen:
    // Trichter = eine Farbe in zunehmender Saettigung, Abzweige = Status-Farben,
    // Vertrag = eigene Rampe, Restfeld = Grau. 'gstart' zieht die Trennlinie zur
    // vorigen Spaltengruppe.
    $colDefs = array_merge(
        [
            // Standby steht DIREKT neben der Belegung und in ihrer Spaltengruppe:
            // es ist eine Eigenschaft der Buchung an diesem Termin („war gebucht,
            // belegt aber keinen Platz mehr“) und damit die Fussnote zur Belegung,
            // auch wenn es NICHT in ihren Balken einfliesst. Sieben bis elf Spalten
            // weiter rechts (je nach Zahl der Phasen-Spalten) las es sich nicht als
            // das „(+Standby)“ des Mockups.
            //
            // Name und Vokabular bleiben unveraendert: die Spalte heisst „Standby“
            // wie in V1 und Tabelle 1, und die Gruppe „Abzweige“ existiert weiter
            // (mit „Nicht erschienen“). Verschoben wird die Nachbarschaft, nicht
            // die Benennung — drei Vokabulare fuer dieselbe Sache waeren teurer
            // als eine Gruppe mit einer Spalte.
            ['key' => 'standby', 'label' => 'Standby',
             'on' => 'bg-amber-100 text-amber-900', 'total' => 'bg-amber-200 text-amber-900',
             'title' => 'Buchung besteht, belegt aber keinen Platz mehr (booked + seat_released_at) — zählt in der Belegung links NICHT mit. Kohorten-Zahl, also gefiltert; die Belegung daneben ist es nicht.'],
            ['key' => 'ids', 'label' => 'Teilnehmer', 'gstart' => true,
             'on' => 'bg-sky-50 text-sky-900', 'total' => 'bg-sky-100 text-sky-950',
             'title' => 'Bewerbungen, deren Kohorten-Zeile an diesem Termin hängt (Präzedenz-Kette Stufe 6) — Testbewerber sind immer ausgeschlossen. Bezugsgröße der anderen Spalten. NICHT dasselbe wie „Belegt“: das zählt Buchungen am Termin, unabhängig von den Filtern dieser Seite.'],
            ['key' => 'kontaktiert', 'label' => 'Kontaktiert',
             'on' => 'bg-sky-100 text-sky-900', 'total' => 'bg-sky-200 text-sky-950',
             'title' => 'Anreicherungs-Proxy (enrichment_status), kein Kontaktnachweis'],
            ['key' => 'gebucht', 'label' => 'Gebucht',
             'on' => 'bg-sky-200 text-sky-900', 'total' => 'bg-sky-300 text-sky-950',
             'title' => 'Hat eine kohorten-relevante Buchung auf diesem Termin (Rang ≥ 1). Storno zählt nicht.'],
            ['key' => 'bestaetigt', 'label' => 'Bestätigt',
             'on' => 'bg-sky-300 text-sky-950', 'total' => 'bg-sky-400 text-sky-950',
             'title' => 'confirmed/attended/no_show — registered zählt bewusst nicht (mehrdeutig).'],
            ['key' => 'teilgenommen', 'label' => 'Teilgenommen',
             'on' => 'bg-sky-400 text-sky-950', 'total' => 'bg-sky-500 text-sky-950',
             'title' => 'Status attended. „Nicht erschienen“ ist ein Abzweig und zählt hier NICHT mit.'],
        ],
        $phaseDefs,
        [
            ['key' => 'no_show', 'label' => 'Nicht erschienen', 'gstart' => true,
             'on' => 'bg-red-100 text-red-900', 'total' => 'bg-red-200 text-red-900',
             'title' => 'Status no_show — gebucht und bestätigt, aber nicht erschienen. Gilt als abgeschlossen.'],
            ['key' => 'vertrag_verschickt', 'label' => 'Vertrag verschickt', 'gstart' => true,
             'on' => 'bg-emerald-50 text-emerald-900', 'total' => 'bg-emerald-100 text-emerald-900',
             'title' => 'Mindestens ein Vertrag mit sent_at. Stornierte Verträge sind ausgeschlossen.'],
            ['key' => 'unterschrieben', 'label' => 'Unterschrieben',
             'on' => 'bg-emerald-200 text-emerald-900', 'total' => 'bg-emerald-300 text-emerald-950',
             'title' => 'Mindestens ein Vertrag mit signed_at — das Ziel des Trichters.'],
            ['key' => 'offen_ids', 'label' => 'Noch offen', 'gstart' => true,
             'on' => 'bg-gray-100 text-gray-700', 'total' => 'bg-gray-200 text-gray-800',
             'onlyRunning' => true,
             'title' => 'Weder unterschrieben noch „nicht erschienen“ (Teilnehmer − Unterschrieben − Nicht erschienen).'],
        ],
    );

    $colGroups = [
        ['label' => 'Termin', 'span' => 3, 'title' => 'Wann, wo und für welche Ausschreibung.'],
        // Belegung + Standby: beide beschreiben die Plätze dieses Termins. Die
        // Zahlen stammen aus zwei Quellen (Belegung aus der Termin-Query, Standby
        // aus der Kohorte) und werden deshalb nicht verrechnet — sie stehen
        // nebeneinander, weil man sie zusammen liest.
        ['label' => 'Belegung', 'span' => 2,
         'title' => 'Plätze des Termins: belegt von allen platzbelegenden Buchungen (unabhängig von den Filtern dieser Seite), daneben die Standby-Buchungen, die keinen Platz mehr belegen.'],
        ['label' => 'Trichter', 'span' => 5 + count($phaseDefs),
         'title' => 'Der Weg durch den Prozess — jede Stufe ist eine Teilmenge der vorigen, die Farbe wird dabei dunkler. Die Phasen-Spalten kommen aus dem Phasensatz der gewählten Filiale.'],
        ['label' => 'Abzweige', 'span' => 1,
         'title' => 'Wege aus dem Trichter heraus, die keine Stufe sind.'],
        ['label' => 'Vertrag', 'span' => 2,
         'title' => 'Das Ziel: Vertrag verschickt und unterschrieben.'],
        ['label' => 'Stand', 'span' => 2,
         'title' => 'Was noch offen ist und was daraus geworden ist.'],
    ];

    $table = $this->interviewTable;
    $interviewRows = $table['rows'];
    $outside = $table['outside'];

    // Gesamt-Zeile: Σ über die TERMINE DIESER AUSWAHL — bewusst nicht über die
    // ganze Kohorte. Sie ist deshalb kleiner als die Gesamt-Zeile von Tabelle 1,
    // sobald Teilnehmer an Terminen außerhalb der Auswahl hängen; die Fußnote
    // unter der Tabelle benennt genau diese Differenz.
    $allRows = [];
    $visibleInterviewIds = [];
    foreach ($interviewRows as $interviewRow) {
        $allRows = array_merge($allRows, $interviewRow['rows']);
        $visibleInterviewIds[] = $interviewRow['interview_id'];
    }

    // Σ IST / Σ SOLL kommt aus belegungTotals() und NICHT aus einer Schleife hier:
    // die Regel „Zähler und Nenner zählen dieselben Termine“ ist die Stelle, an
    // der diese Zeile schon einmal falsch war (12 / 8 → 150 % roter
    // Überbuchungs-Balken, ohne dass ein einzelner Termin überbucht war). In der
    // Komponente ist sie testbar, in einem Blade-Skriptblock nicht.
    $belegung = $this->belegungTotals($interviewRows);

    // Das Token der Gesamt-Zeile trägt die SICHTBAREN Termine, damit das
    // Drill-down genau die Menge auflöst, die die Zeile anzeigt. Ein Token über
    // „alle Schulungszeilen“ (scope type_all) träfe auch Termine außerhalb dieser
    // Auswahl — die Modal-Länge passte dann nicht zur Zahl daneben.
    $allToken = $this->drillToken('interviews', 'Gesamt (Termine dieser Auswahl)', [
        'interviews' => $visibleInterviewIds,
    ]);

    $belegungTitle = 'Einheit: BUCHUNGEN. Platzbelegende Buchungen des Termins nach zentraler Zählregel (Standby zählt nicht), gegen die Kapazität des Termins — unabhängig von Zeitraum-, Orts- und Ausschreibungs-Filter. Kann darum höher sein als die Trichter-Spalten daneben, die nur die gefilterte Kohorte zählen. Die beiden Zahlen sind zwei Einheiten und werden nicht gegeneinander gerechnet.';
@endphp

<x-ui-panel title="Schulungstermine" subtitle="Eine Zeile je Termin — Belegung, Trichter und Herkunft der Teilnehmer">
    <div class="mb-2 text-xs text-[color:var(--ui-muted)]">
        Momentaufnahme des aktuellen Status, keine Historie
        <span class="ml-1 cursor-help"
              title="Die Zahlen zeigen den aktuellen Stand jeder Bewerbung, keine Historie — sie können zwischen zwei Aufrufen auch sinken. Inaktive Termine sind ausgeschlossen (Termine haben kein Test-Kennzeichen; inaktiv ist der einzige Weg, einen Test-Termin aus der Statistik zu nehmen). Jeder Spaltenkopf trägt seine Definition als Tooltip.">ⓘ</span>
    </div>

    @if (count($interviewRows) === 0)
        <div class="py-10 text-center text-sm text-[color:var(--ui-muted)]">
            Keine Schulungstermine in dieser Auswahl.
        </div>
    @else
        {{-- Eigener Scroll-Container: sticky braucht einen Scroll-Vorfahren. --}}
        <div class="max-h-[75vh] overflow-auto rounded-lg border border-[var(--ui-border)]/60">
            <table class="w-full table-auto border-collapse text-sm">
                <thead>
                    {{-- Gruppenzeile: feste Hoehe h-7, weil die zweite Kopfzeile mit top-7 darunter klebt. --}}
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
                            title="Beginn des Termins und seine Terminart. Aufklappen zeigt, aus welchen Ausschreibungen die Teilnehmer kommen.">Datum / Uhrzeit</th>
                        <th class="sticky top-7 z-20 bg-[var(--ui-surface)] px-3 py-3"
                            title="Veranstaltungsort des Termins (freies Textfeld, z. B. eine Treffpunktbeschreibung). NUR Information: gefiltert und gruppiert wird nach dem Ort der STELLE, der davon abweichen kann.">
                            Ort
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
                        <th class="sticky top-7 z-20 bg-[var(--ui-surface)] px-3 py-3"
                            title="Die am Termin hinterlegte Ausschreibung. Ist keine hinterlegt, steht hier der Titel des Termins — die Teilnehmer können trotzdem aus mehreren Ausschreibungen kommen (siehe Herkunft beim Aufklappen).">
                            Ausschreibung
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
                        <th class="sticky top-7 z-20 border-l border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom"
                            title="{{ $belegungTitle }}">
                            Belegt
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
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
                            title="Unterschriften geteilt durch Teilnehmer dieses Termins. Ausgegraut, solange die Kohorte jünger ist als der Median-Durchlauf (Right-Censoring).">
                            Conversion
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
                    </tr>
                </thead>

                {{-- x-data auf dem tbody: der Aufklapp-Zustand aller Termine liegt in
                     EINEM Alpine-Scope, weil sich der Scope eines <tr> nicht auf
                     Geschwister-<tr> erstreckt. Schluessel ist die Termin-ID — eine
                     ganze Zahl, also im JS-Ausdruck unbedenklich (Terminnamen und
                     Ortsangaben sind freier Nutzertext und haben dort nichts zu
                     suchen). --}}
                <tbody x-data="{ open: {} }" class="divide-y divide-[var(--ui-border)]/60">
                    @foreach ($interviewRows as $interviewRow)
                        @php
                            $interviewId = $interviewRow['interview_id'];
                            // rec_interviews.starts_at ist NOT NULL — kein
                            // „ohne Datum“-Zweig, der nie laufen kann.
                            $dateLabel = $interviewRow['starts_at']->format('d.m.Y H:i');
                            $rowPrefix = $dateLabel . ' · ' . $interviewRow['type'];
                            // Die Termin-Liste ist PFLICHT im Token: die Zeile summiert
                            // die Assigner-Zeilen ALLER Ausschreibungen dieses Termins.
                            // Ohne die Angabe trifft der Scope fail-closed nichts
                            // (leeres Modal statt vermischter IDs). Eine Termin-Zeile
                            // ist die Liste mit einem Eintrag — dieselbe Tür, die die
                            // Gesamt-Zeile mit allen sichtbaren Terminen benutzt.
                            $rowToken = $this->drillToken('interviews', $rowPrefix, ['interviews' => [$interviewId]]);
                            $origins = $interviewRow['origins'];
                            $postingTitle = $interviewRow['posting_title'];
                        @endphp
                        <tr class="group transition-colors hover:bg-[var(--ui-muted-5)]">
                            {{-- Erste Spalte klebt links: die Tabelle scrollt horizontal,
                                 ohne Anker weiss man nach wenigen Spalten nicht mehr,
                                 welchen Termin man liest. --}}
                            <td class="sticky left-0 z-10 border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-4 py-2 text-[color:var(--ui-secondary)] group-hover:bg-[var(--ui-muted-5)]">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    @if (count($origins) > 0)
                                        <button
                                            type="button"
                                            x-on:click="open[{{ $interviewId }}] = !open[{{ $interviewId }}]"
                                            class="inline-flex items-center gap-1 rounded font-semibold tabular-nums hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] cursor-pointer"
                                            title="Herkunft der Teilnehmer ein-/ausklappen: eine Unterzeile pro Ausschreibung, in der Summe genau diese Zeile."
                                        >
                                            <span x-text="open[{{ $interviewId }}] ? '▾' : '▸'" class="text-[color:var(--ui-muted)]">▸</span>
                                            {{ $dateLabel }}
                                        </button>
                                        <span class="text-xs text-[color:var(--ui-muted)]">
                                            ({{ count($origins) }} {{ count($origins) === 1 ? 'Ausschreibung' : 'Ausschreibungen' }})
                                        </span>
                                    @else
                                        {{-- Kein Aufklapp-Pfeil ohne Inhalt: ein Pfeil, der
                                             nichts oeffnet, liest sich als Defekt. --}}
                                        <span class="font-semibold tabular-nums">{{ $dateLabel }}</span>
                                    @endif
                                    <span class="rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60">
                                        {{ $interviewRow['type'] }}
                                    </span>
                                    @include('recruiting::livewire.statistics.markers', ['rows' => $interviewRow['rows'], 'token' => $rowToken, 'prefix' => $rowPrefix])
                                </div>
                            </td>
                            <td class="px-3 py-2 text-xs text-[color:var(--ui-muted)]">
                                @if (($interviewRow['location'] ?? '') !== '')
                                    {{-- Gekuerzt: die vollen Strings enthalten
                                         Treffpunktbeschreibungen und schoben die Zahlen
                                         aus dem Blick. --}}
                                    <span class="block max-w-[14rem] truncate"
                                          title="Veranstaltungsort (nur Information, nicht die Filter-Dimension): {{ $interviewRow['location'] }}">
                                        {{ $interviewRow['location'] }}
                                    </span>
                                @else
                                    <span class="cursor-help" title="Am Termin ist kein Veranstaltungsort gepflegt.">–</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-[color:var(--ui-secondary)]">
                                @if ($postingTitle !== '')
                                    <span class="block max-w-[16rem] truncate {{ $interviewRow['has_posting'] ? 'font-medium' : 'italic' }}"
                                          title="{{ $interviewRow['has_posting'] ? 'Ausschreibung des Termins: ' . $postingTitle : 'Am Termin ist keine Ausschreibung hinterlegt — angezeigt wird der Titel des Termins: ' . $postingTitle }}">
                                        {{ $postingTitle }}
                                    </span>
                                @else
                                    <span class="cursor-help text-xs text-[color:var(--ui-muted)]"
                                          title="Am Termin ist weder eine Ausschreibung hinterlegt noch ein Titel gepflegt.">–</span>
                                @endif
                            </td>
                            @include('recruiting::livewire.statistics.meter', [
                                'taken' => $interviewRow['seat_taking'], 'max' => $interviewRow['max'],
                                'borderLeft' => true, 'title' => $belegungTitle,
                            ])
                            @include('recruiting::livewire.statistics.cells', ['rows' => $interviewRow['rows'], 'token' => $rowToken, 'prefix' => $rowPrefix, 'isTotal' => false])
                            @include('recruiting::livewire.statistics.conversion', ['rows' => $interviewRow['rows'], 'isTotal' => false])
                        </tr>

                        {{-- HERKUNFT: eine Unterzeile pro Ausschreibung der Teilnehmer.
                             Dieselben Assigner-Zeilen wie die Termin-Zeile darueber, nur
                             nach Ausschreibung gruppiert — die Summe dieser Unterzeilen
                             IST die Zeile darueber. Ohne eigene Kapazitaet: die Plaetze
                             gehoeren dem Termin. --}}
                        @foreach ($origins as $origin)
                            @php
                                $originTitle = $origin['posting_title'] !== '' ? $origin['posting_title'] : 'ohne Titel';
                                $originPrefix = $rowPrefix . ' · '
                                    . ($origin['posting_id'] === null ? 'ohne Ausschreibung' : $originTitle);
                                // Termin UND Ausschreibung sind Pflicht: ohne den Termin
                                // zaehlte die Ausschreibung ueber alle Termine mit, ohne
                                // die Ausschreibung der ganze Termin. Beides waeren
                                // Zahlen, die zur angeklickten Unterzeile nicht passen.
                                $originToken = $this->drillToken('interviews_posting', $originPrefix, [
                                    'interviews' => [$interviewId],
                                    'posting' => $origin['posting_id'],
                                ]);
                            @endphp
                            {{-- style statt x-cloak: im Projekt ist keine [x-cloak]-CSS-Regel
                                 definiert (vgl. modal.blade.php, das denselben Weg geht) --}}
                            <tr x-show="open[{{ $interviewId }}]" style="display: none;" class="group transition-colors hover:bg-[var(--ui-muted-5)]">
                                <td colspan="3" class="sticky left-0 z-10 border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-10 py-2 text-xs text-[color:var(--ui-muted)] group-hover:bg-[var(--ui-muted-5)]">
                                    <div class="flex items-center gap-2 whitespace-nowrap">
                                        <span class="shrink-0">Herkunft:</span>
                                        @if ($origin['posting_id'] === null)
                                            <span class="font-medium">ohne Ausschreibung</span>
                                            <span class="rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60"
                                                  title="An diesen Bewerbungen hängt keine Ausschreibung (Fall 3 der Zuordnungsregel). Sie sitzen trotzdem in diesem Termin und sind in der Zeile darüber enthalten.">
                                                kein Ziel ⓘ
                                            </span>
                                        @else
                                            <span class="max-w-[20rem] truncate text-[color:var(--ui-secondary)]"
                                                  title="Ausschreibung der Teilnehmer: {{ $originTitle }}">{{ $originTitle }}</span>
                                            @if ($origin['posting_closed'])
                                                <span class="rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60"
                                                      title="Nicht online: die Ausschreibung ist nicht veröffentlicht oder nicht aktiv. Ein abgelaufenes Laufzeitende allein gilt nicht als geschlossen.">
                                                    geschlossen
                                                </span>
                                            @endif
                                        @endif
                                        @include('recruiting::livewire.statistics.markers', ['rows' => $origin['rows'], 'token' => $originToken, 'prefix' => $originPrefix])
                                    </div>
                                </td>
                                @include('recruiting::livewire.statistics.meter', [
                                    'taken' => null, 'max' => null, 'borderLeft' => true,
                                    'title' => 'Plätze gehören dem Termin, nicht der Ausschreibung — eine Herkunft hat keine eigene Kapazität.',
                                ])
                                @include('recruiting::livewire.statistics.cells', ['rows' => $origin['rows'], 'token' => $originToken, 'prefix' => $originPrefix, 'isTotal' => false])
                                @include('recruiting::livewire.statistics.conversion', ['rows' => $origin['rows'], 'isTotal' => false])
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>

                {{-- Gesamt klebt am unteren Rand: die Bezugsgroesse bleibt beim
                     Scrollen sichtbar. Summiert wird ueber die ASSIGNER-Zeilen der
                     sichtbaren Termine — dieselbe Menge, die das Drill-down der
                     Zeile aufloest. --}}
                <tfoot>
                    <tr class="border-t-2 border-[var(--ui-border)] font-bold">
                        <td colspan="3" class="sticky bottom-0 left-0 z-30 bg-[var(--ui-surface)] px-4 py-3 text-[color:var(--ui-secondary)]">
                            Gesamt
                            <span class="ml-1 text-xs font-normal text-[color:var(--ui-muted)]">
                                ({{ count($interviewRows) }} {{ count($interviewRows) === 1 ? 'Termin' : 'Termine' }} dieser Auswahl)
                                <span class="cursor-help"
                                      title="Summe über die Termine dieser Auswahl — nicht über die ganze Kohorte. Teilnehmer an Terminen außerhalb der Auswahl fehlen hier bewusst (Gründe siehe Fußnote unter der Tabelle); sie stehen in der Ausschreibungs-Tabelle.">ⓘ</span>
                            </span>
                        </td>
                        {{-- Σ IST / Σ SOLL über DIESELBE Auswahl (nur Termine MIT
                             Platzbegrenzung — unbegrenzte haben keinen Nenner, den
                             man addieren könnte, und die Datenzeile zeigt für sie
                             „∞“). Hat kein Termin eine Begrenzung, ist `taken` null
                             und die Zelle zeigt „–“: kein Nenner, also keine
                             Belegungs-Quote. Die belegten Plätze der ausgelassenen
                             Termine gehen nicht verloren, sie stehen im Text
                             darunter — derselbe Text wie im Tooltip, damit die
                             Differenz nur an EINER Stelle formuliert ist. --}}
                        @include('recruiting::livewire.statistics.meter', [
                            'taken' => $belegung['taken'], 'max' => $belegung['max'],
                            'borderLeft' => true, 'pad' => 'px-3 py-3',
                            'title' => 'Σ belegte Plätze / Σ Plätze der Termine dieser Auswahl. ' . $belegung['reason'],
                        ])
                        @include('recruiting::livewire.statistics.cells', ['rows' => $allRows, 'token' => $allToken, 'prefix' => 'Gesamt (Termine dieser Auswahl)', 'isTotal' => true])
                        @include('recruiting::livewire.statistics.conversion', ['rows' => $allRows, 'isTotal' => true])
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Fussnote zur Summen-Belegung: sie zaehlt nur Termine MIT
             Platzbegrenzung (Zaehler UND Nenner). Was dadurch nicht mitzaehlt, wird
             benannt — sonst ist die Zelle aus den Zeilen darueber nicht
             nachrechenbar, weil dort Belegungen stehen, die hier fehlen. Der Text
             kommt aus belegungTotals() und ist derselbe wie im Tooltip; er nennt
             „kleiner als die Zeilen darueber“ nur, wenn an den unbegrenzten
             Terminen wirklich Plaetze belegt sind. --}}
        @if ($belegung['unlimited_interviews'] > 0)
            <div class="mt-2 text-xs text-[color:var(--ui-muted)]">
                Belegung gesamt: {{ $belegung['reason'] }}
            </div>
        @endif
    @endif

    {{-- Fussnote statt stiller Differenz: Tabelle 2 zeigt nur die Termine dieser
         Auswahl. Teilnehmer an anderen Terminen fehlen hier — ohne diesen Satz
         liest man die kleinere Gesamt-Summe als Rechenfehler.

         BEWUSST AUSSERHALB des Leer-Zweigs dieser Tabelle: bei NULL sichtbaren
         Terminen ist die Erklaerung am noetigsten, und genau dort fehlte sie
         vorher (die Seite verschwieg gemessen drei Termine mit fuenf
         Bewerbungen).

         Die Gruende sind eine AUSWAHL, keine vollstaendige Liste, und der Text
         sagt das auch („zum Beispiel“). Es gibt mehr als die zwei naheliegenden:
         ein Termin ohne Stelle oder an einer Stelle einer anderen Filiale faellt
         durch den Ort-Filter, obwohl der Teilnehmer zur gewaehlten Filiale
         gehoert (der Assigner bildet die Schulungszeile allein ueber die
         Buchung), und geloeschte Termine tauchen ohnehin nicht auf. Eine
         Aufzaehlung, die sich vollstaendig gibt und es nicht ist, erklaert die
         Differenz falsch. --}}
    @if ($outside['interviews'] > 0)
        <div class="mt-2 text-xs text-[color:var(--ui-muted)]">
            Nicht in dieser Tabelle:
            {{ $outside['applications'] }}
            {{ $outside['applications'] === 1 ? 'Bewerbung' : 'Bewerbungen' }}
            an {{ $outside['interviews'] }}
            {{ $outside['interviews'] === 1 ? 'Termin' : 'Terminen' }},
            {{ $outside['interviews'] === 1 ? 'der' : 'die' }} nicht in dieser Auswahl
            {{ $outside['interviews'] === 1 ? 'liegt' : 'liegen' }} — zum Beispiel inaktiv gesetzt,
            außerhalb des Termin-Zeitraums oder ohne Stelle bzw. an einer anderen Filiale
            <span class="cursor-help"
                  title="Ein Termin ohne Stelle oder mit einer Stelle einer anderen Filiale fällt durch den Ort-Filter, obwohl der Teilnehmer zur gewählten Filiale gehört: die Kohorten-Zeile hängt allein an der Buchung, ihr Ort kommt von der Ausschreibung des Bewerbers. Auch gelöschte Termine sind hier nicht dabei. Die Aufzählung ist deshalb eine Auswahl, keine vollständige Liste.">ⓘ</span>.
            In der Ausschreibungs-Tabelle sind sie enthalten.
        </div>
    @endif
</x-ui-panel>
