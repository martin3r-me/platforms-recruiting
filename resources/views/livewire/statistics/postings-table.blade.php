{{--
    TABELLE 1 der Statistik-Seite: eine Zeile je AUSSCHREIBUNG mit Bedarf,
    Erfuellungsquote und zwei Ampeln. Sie beantwortet die Frage, mit der der
    Kunde auf diese Seite kommt — „laeuft diese Ausschreibung auf Ziel?" —, und
    zwar in einer Zeile pro Ausschreibung statt in einem Baum ueber Ort,
    Taetigkeit und Zeilentyp.

    Die Zeilen-Einheit ist die Ausschreibung: alle Assigner-Zeilen einer
    Ausschreibung (Schulungen, Phasen, geparkt, abgesagt, Buckets) sind in EINER
    Tabellenzeile summiert (CohortViewModel::postingGroups). Gezaehlt wird
    trotzdem ueber die Assigner-Zeilen selbst ($group['rows']) — derselbe Weg,
    den auch das Drill-down nimmt, damit angezeigte Zahl und Modal-Laenge nicht
    auseinanderlaufen koennen.

    Dieses Partial bringt seinen eigenen x-ui-panel mit und ist damit ein
    fertiger Seitenabschnitt: es wird per @include eingebunden, ohne es noch
    einmal zu umwickeln.

    Erwartet: keine Variablen — alles kommt aus der Livewire-Komponente
    ($this->postingGroups, $this->phaseLabels, $this->cohort).
--}}
@php
    // Farbrampe der Phasen-Spalten. Literale, weil Tailwinds JIT
    // zusammengesetzte Klassennamen nicht findet — und als RAMPE, weil der
    // Phasen-Trichter eine Abfolge ist: jede Stufe ist Teilmenge der vorigen,
    // die Saettigung nimmt zu. Mehr Phasen als Toene bleiben auf dem letzten
    // Ton stehen (lieber gleichfarbig als eine zweite Buntfarbe, die wie eine
    // eigene Kategorie aussieht).
    $phaseTints = [
        ['on' => 'bg-sky-50 text-sky-900',  'total' => 'bg-sky-100 text-sky-950'],
        ['on' => 'bg-sky-100 text-sky-900', 'total' => 'bg-sky-200 text-sky-950'],
        ['on' => 'bg-sky-200 text-sky-900', 'total' => 'bg-sky-300 text-sky-950'],
        ['on' => 'bg-sky-300 text-sky-950', 'total' => 'bg-sky-400 text-sky-950'],
        ['on' => 'bg-sky-400 text-sky-950', 'total' => 'bg-sky-500 text-sky-950'],
    ];

    // Phasen-Spalten aus dem Phasensatz der gefilterten Filiale. NICHT fest
    // verdrahtet: Phasen sind pro Stelle geklont und frei benannt.
    //
    // Ohne Ort-Filter ist die Liste NICHT leer: Laravel macht aus
    // where('location', null) ein whereNull, die Koepfe kommen dann aus dem
    // Phasensatz ORTLOSER Stellen, waehrend die Zahlen darunter alle Orte
    // enthalten. Ein kosmetisch schiefer Zwischenzustand (die Spalten zaehlen
    // korrekt ueber die order), den Task 10 beseitigt, indem der Ort zur
    // Pflichtauswahl wird.
    //
    // Bewusst OHNE Fallback: ein „nimm halt alle Phasen" waere nach Task 10
    // toter Code und wuerde bis dahin Spalten aus fremden Phasensaetzen mischen.
    $phaseDefs = [];
    $phaseIndex = 0;
    foreach ($this->phaseLabels as $phaseOrder => $phaseName) {
        $tint = $phaseTints[min($phaseIndex, count($phaseTints) - 1)];
        // Der Name wird UNVERAENDERT uebernommen — Kopf, Tooltip und
        // Modal-Titel muessen zeigen, was HR eingetragen hat. Das Quoting im
        // wire:click-Ausdruck loest @js in cells.blade.php, nicht eine
        // Verfremdung der Stammdaten.
        $phaseName = (string) $phaseName;
        $phaseDefs[] = [
            'key' => $this->phaseColumnKey((int) $phaseOrder),
            'label' => $phaseName,
            'on' => $tint['on'],
            'total' => $tint['total'],
            'title' => 'Bewerbungen, die Phase ' . $phaseOrder . ' („' . $phaseName . '") erreicht haben — '
                . 'kumulativ: wer weiter ist, zählt hier mit. NETTO, also nur laufende Kohorten: '
                . 'Geparkte, Abgesagte und ausgeschlossene Buckets tauchen im Phasen-Trichter nicht auf, '
                . 'sind aber in „Bewerbungen" enthalten.',
        ];
        $phaseIndex++;
    }

    // Spaltendefinition an EINER Stelle — thead, Datenzeilen und Gesamt-Zeile
    // lesen daraus (Muster und Farbsystem wie in der Kohorten-Tabelle: der
    // Trichter traegt EINE Farbe in zunehmender Saettigung, Abzweige tragen
    // Status-Farben, das Vertragsziel eine eigene Rampe, das Restfeld Grau).
    $colDefs = array_merge(
        [
            ['key' => 'ids', 'label' => 'Bewerbungen', 'gstart' => true,
             'on' => 'bg-sky-50 text-sky-900', 'total' => 'bg-sky-100 text-sky-950',
             'title' => 'Alle Bewerbungen dieser Ausschreibung — Testbewerber sind immer ausgeschlossen. Bezugsgröße der Pipeline-Ampel.'],
            ['key' => 'kontaktiert', 'label' => 'Kontaktiert',
             'on' => 'bg-sky-100 text-sky-900', 'total' => 'bg-sky-200 text-sky-950',
             'title' => 'Anreicherungs-Proxy (enrichment_status), kein Kontaktnachweis'],
            ['key' => 'gebucht', 'label' => 'Gebucht',
             'on' => 'bg-sky-200 text-sky-900', 'total' => 'bg-sky-300 text-sky-950',
             'title' => 'Hat eine kohorten-relevante Buchung auf einem Schulungstermin (Rang ≥ 1). Storno zählt nicht.'],
            ['key' => 'bestaetigt', 'label' => 'Bestätigt',
             'on' => 'bg-sky-300 text-sky-950', 'total' => 'bg-sky-400 text-sky-950',
             'title' => 'confirmed/attended/no_show — registered zählt bewusst nicht (mehrdeutig).'],
            ['key' => 'teilgenommen', 'label' => 'Teilgenommen',
             'on' => 'bg-sky-400 text-sky-950', 'total' => 'bg-sky-500 text-sky-950',
             'title' => 'Status attended. „Nicht erschienen" ist ein Abzweig und zählt hier NICHT mit.'],
        ],
        $phaseDefs,
        [
            ['key' => 'standby', 'label' => 'Standby', 'gstart' => true,
             'on' => 'bg-amber-100 text-amber-900', 'total' => 'bg-amber-200 text-amber-900',
             'title' => 'Buchung besteht, belegt aber keinen Platz mehr (booked + seat_released_at).'],
            ['key' => 'no_show', 'label' => 'Nicht erschienen',
             'on' => 'bg-red-100 text-red-900', 'total' => 'bg-red-200 text-red-900',
             'title' => 'Status no_show — gebucht und bestätigt, aber nicht erschienen. Gilt als abgeschlossen.'],
            ['key' => 'vertrag_verschickt', 'label' => 'Vertrag verschickt', 'gstart' => true,
             'on' => 'bg-emerald-50 text-emerald-900', 'total' => 'bg-emerald-100 text-emerald-900',
             'title' => 'Mindestens ein Vertrag mit sent_at. Stornierte Verträge sind ausgeschlossen.'],
            ['key' => 'unterschrieben', 'label' => 'Unterschrieben',
             'on' => 'bg-emerald-200 text-emerald-900', 'total' => 'bg-emerald-300 text-emerald-950',
             'title' => 'Mindestens ein Vertrag mit signed_at — der Zähler der Erfüllungsquote.'],
            ['key' => 'offen_ids', 'label' => 'Noch offen', 'gstart' => true,
             'on' => 'bg-gray-100 text-gray-700', 'total' => 'bg-gray-200 text-gray-800',
             'onlyRunning' => true,
             'title' => 'Weder unterschrieben noch „nicht erschienen" (Bewerbungen − Unterschrieben − Nicht erschienen). Nur für laufende Kohorten.'],
        ],
    );

    $colGroups = [
        ['label' => '', 'span' => 1, 'title' => ''],
        ['label' => 'Trichter', 'span' => 5 + count($phaseDefs),
         'title' => 'Der Weg durch den Prozess — jede Stufe ist eine Teilmenge der vorigen, die Farbe wird dabei dunkler. Die Phasen-Spalten kommen aus dem Phasensatz der gewählten Filiale.'],
        ['label' => 'Abzweige', 'span' => 2,
         'title' => 'Wege aus dem Trichter heraus, die keine Stufe sind.'],
        ['label' => 'Vertrag', 'span' => 2,
         'title' => 'Das Ziel: Vertrag verschickt und unterschrieben.'],
        ['label' => 'Stand', 'span' => 2,
         'title' => 'Was noch offen ist und was daraus geworden ist.'],
        ['label' => 'Ziel', 'span' => 3,
         'title' => 'Bedarf der Ausschreibung und die beiden Ampeln dazu. Nichts wird geraten: fehlt Bedarf oder Faktor, ist die Ampel grau.'],
        ['label' => 'Einsatz', 'span' => 1,
         'title' => 'Wann die Eingestellten das erste Mal arbeiten — kommt mit der Dispo.'],
    ];

    $groups = $this->postingGroups;
    $allToken = $this->drillToken('all', 'Gesamt');

    // Gesamt-Zeile: alles vorab, damit die Fusszeile nur noch ausgibt.
    $rowSum = $this->countIn($this->cohort['rows'], 'ids');
    $totalIds = count($this->cohort['total_ids']);
    // Erfuellung der Gesamt-Zeile: Prozentwert aus sumPercent() (Σ/Σ, nie der
    // Mittelwert der Zeilen-Prozente), Ampelpunkt und Begruendung aus TargetLight
    // — arithmetisch dieselbe Rechnung. $totalFulfilment traegt zusaetzlich die
    // absoluten Bezugsgroessen, damit die Zelle nachrechenbar ist.
    $totalFulfilment = $this->fulfilmentTotalLight($groups);
    $totalBedarf = $totalFulfilment['bedarf'];
    $totalPipeline = $this->pipelineTotalLight($groups);

    $bedarfTitle = 'Benötigte Einstellungen (Feld „Bedarf" an der Ausschreibung). „–" heißt NICHT null, sondern nicht gepflegt — dann bleiben beide Ampeln grau.';
    $einsatzTitle = 'kommt mit der Dispo';
@endphp

<x-ui-panel title="Ausschreibungen" subtitle="Eine Zeile je Ausschreibung — läuft sie auf Ziel?">
    <div class="mb-2 text-xs text-[color:var(--ui-muted)]">
        Momentaufnahme des aktuellen Status, keine Historie
        <span class="ml-1 cursor-help"
              title="Die Zahlen zeigen den aktuellen Stand jeder Bewerbung, keine Historie — sie können zwischen zwei Aufrufen auch sinken. Der Phasen-Trichter ist kumulativ und netto (nur laufende Kohorten). Jeder Spaltenkopf trägt seine Definition als Tooltip.">ⓘ</span>
    </div>

    @if (count($groups) === 0)
        <div class="py-10 text-center text-sm text-[color:var(--ui-muted)]">
            Keine Bewerbungen in dieser Auswahl.
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
                            title="Die Ausschreibung, an der die Bewerbung hängt (Zuordnungsregel Spec §4). Bewerbungen ohne Zuordnung stehen in einer eigenen Zeile.">Ausschreibung</th>
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
                            title="Unterschriften geteilt durch Bewerbungen dieser Ausschreibung. Ausgegraut, solange die Kohorte jünger ist als der Median-Durchlauf (Right-Censoring).">
                            Conversion
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
                        <th class="sticky top-7 z-20 border-l border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom"
                            title="{{ $bedarfTitle }}">
                            Bedarf
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
                        <th class="sticky top-7 z-20 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom"
                            title="Unterschriften gegen Bedarf, ABSOLUT (keine Hochrechnung — Unterschriften kommen schubweise nach jeder Schulung). Grün ab 90 %, gelb ab 60 %.">
                            Erfüllung
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
                        <th class="sticky top-7 z-20 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom"
                            title="Bewerbungen gegen Bedarf × Faktor, HOCHGERECHNET auf das Laufzeitende: dieselbe Zahl heißt bei drei Wochen Restlaufzeit Alarm und bei sechs Monaten Plan. Grün ab 90 %, gelb ab 60 %. Grau in den ersten sieben Tagen und ohne gepflegte Werte.">
                            Pipeline
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
                        <th class="sticky top-7 z-20 border-l border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-3 py-3 text-center align-bottom"
                            title="{{ $einsatzTitle }}">
                            Erster Einsatz
                            <span class="cursor-help text-[color:var(--ui-muted)]">ⓘ</span>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-[var(--ui-border)]/60">
                    @foreach ($groups as $group)
                        @php
                            $groupRows = $group['rows'];
                            $hasPosting = $group['posting_id'] !== null;
                            $title = $group['posting_title'] !== '' ? $group['posting_title'] : 'ohne Titel';
                            $rowPrefix = $hasPosting ? $title : 'ohne Ausschreibung';
                            // 'posting' ist PFLICHT im Token: die Zeilen sind je
                            // Ausschreibung, ohne die Angabe trifft der Scope
                            // fail-closed nichts (leeres Modal statt vermischter IDs).
                            $rowToken = $this->drillToken('posting', $rowPrefix, ['posting' => $group['posting_id']]);

                            $fulfilment = $this->fulfilmentLight($group);
                            $pipeline = $this->pipelineLight($group);

                            // Zeilen-Tint nach dem PIPELINE-Status: er ist die
                            // vorwaertsgerichtete Frage („kommt genug rein?") und
                            // damit die, auf die man handeln kann. Nur ein Tint,
                            // damit die Trichter-Farben lesbar bleiben.
                            $tint = match ($pipeline['status']) {
                                'red' => 'bg-red-50/40',
                                'yellow' => 'bg-amber-50/40',
                                'green' => 'bg-emerald-50/40',
                                default => '',
                            };
                            $taetigkeiten = implode(', ', $group['taetigkeiten']);
                        @endphp
                        <tr class="group transition-colors hover:bg-[var(--ui-muted-5)] {{ $tint }}">
                            {{-- Erste Spalte klebt links: die Tabelle scrollt horizontal,
                                 ohne Anker weiss man nach wenigen Spalten nicht mehr,
                                 welche Ausschreibung man liest. --}}
                            <td class="sticky left-0 z-10 border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)] px-4 py-2 text-[color:var(--ui-secondary)] group-hover:bg-[var(--ui-muted-5)]">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    @if ($hasPosting)
                                        <span class="max-w-[18rem] truncate font-semibold" title="{{ $title }}">{{ $title }}</span>
                                    @else
                                        <span class="font-medium text-[color:var(--ui-muted)]">ohne Ausschreibung</span>
                                        <span class="rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60"
                                              title="An diesen Bewerbungen hängt keine Ausschreibung (Fall 3 der Zuordnungsregel). Sie sind vollständig enthalten, nur keinem Ziel zuzuordnen — deshalb ohne Bedarf und ohne Ampel.">
                                            kein Ziel ⓘ
                                        </span>
                                    @endif
                                    @if ($group['posting_closed'])
                                        {{-- Neutral, nicht warnend: geschlossen ist ein Zustand,
                                             keine Handlungsaufforderung. Definition = exaktes
                                             Gegenteil von „online" (published + aktiv); ein
                                             abgelaufenes closes_at gehoert NICHT dazu. --}}
                                        <span class="rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60"
                                              title="Nicht online: die Ausschreibung ist nicht veröffentlicht oder nicht aktiv. Ein abgelaufenes Laufzeitende allein gilt nicht als geschlossen.">
                                            geschlossen
                                        </span>
                                    @endif
                                    @include('recruiting::livewire.statistics.markers', ['rows' => $groupRows, 'token' => $rowToken, 'prefix' => $rowPrefix])
                                </div>
                                @if ($taetigkeiten !== '')
                                    <div class="mt-0.5 max-w-[18rem] truncate text-xs text-[color:var(--ui-muted)]"
                                         title="Tätigkeit der Ausschreibung: {{ $taetigkeiten }}">
                                        {{ $taetigkeiten }}
                                    </div>
                                @endif
                            </td>
                            @include('recruiting::livewire.statistics.cells', ['rows' => $groupRows, 'token' => $rowToken, 'prefix' => $rowPrefix, 'isTotal' => false])
                            @include('recruiting::livewire.statistics.conversion', ['rows' => $groupRows, 'isTotal' => false])
                            <td class="border-l border-[var(--ui-border)]/60 px-3 py-2 text-center whitespace-nowrap tabular-nums font-semibold text-[color:var(--ui-secondary)]"
                                title="{{ $bedarfTitle }}">
                                {{-- „0" wird NICHT angezeigt: ein Bedarf von 0 ist
                                     kein Nenner und zaehlt weder in der Quote noch
                                     in der Summe mit. Eine sichtbare 0, die
                                     nirgends mitrechnet, waere derselbe
                                     Widerspruch wie eine Quote mit stillem
                                     anderem Bezug. --}}
                                @if ($group['bedarf'] === null || $group['bedarf'] <= 0)
                                    <span class="text-xs font-normal text-[color:var(--ui-muted)]">–</span>
                                @else
                                    {{ $group['bedarf'] }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center">
                                @include('recruiting::livewire.statistics.light', ['light' => $fulfilment, 'label' => 'Erfüllung'])
                            </td>
                            <td class="px-3 py-2 text-center">
                                @include('recruiting::livewire.statistics.light', ['light' => $pipeline, 'label' => 'Pipeline'])
                            </td>
                            <td class="border-l border-[var(--ui-border)]/60 px-3 py-2 text-center whitespace-nowrap">
                                <span class="cursor-help text-xs text-[color:var(--ui-muted)]" title="{{ $einsatzTitle }}">–</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                {{-- Gesamt klebt am unteren Rand: die Bezugsgroesse bleibt beim
                     Scrollen sichtbar. Summiert wird ueber die ASSIGNER-Zeilen, nicht
                     ueber die Anzeige-Zeilen — damit Gesamt-Summe und Rekonziliation
                     per Konstruktion zusammenpassen. --}}
                <tfoot>
                    <tr class="border-t-2 border-[var(--ui-border)] font-bold">
                        <td class="sticky bottom-0 left-0 z-30 bg-[var(--ui-surface)] px-4 py-3 text-[color:var(--ui-secondary)]">
                            Gesamt
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
                        <td class="border-l border-[var(--ui-border)]/60 px-3 py-3 text-center whitespace-nowrap tabular-nums text-[color:var(--ui-secondary)]"
                            title="Summe der gepflegten Bedarfe. Ausschreibungen ohne Bedarf zählen hier nicht mit — genau wie im Nenner der Erfüllungsquote daneben.">
                            @if ($totalBedarf === null)
                                <span class="text-xs font-normal text-[color:var(--ui-muted)]">–</span>
                            @else
                                {{ $totalBedarf }}
                            @endif
                        </td>
                        {{-- Erfuellung der Gesamt-Zeile: derselbe Ampelpunkt wie in
                             jeder Zeile darueber, DARUNTER der Bruch in absoluten
                             Zahlen. Ohne den Bruch war die Zelle aus ihren eigenen
                             Nachbarn nicht nachrechenbar: die Spalte
                             „Unterschrieben" zaehlt ALLE Ausschreibungen, der
                             Zaehler hier nur die mit gepflegtem Bedarf — bei
                             „Unterschrieben 9 / Bedarf 10" liest man 90 %, richtig
                             sind 50 %. Die Differenz steht als Fussnote unter der
                             Tabelle, damit sie nicht nur im Tooltip lebt. --}}
                        <td class="px-3 py-3 text-center whitespace-nowrap"
                            title="Σ Unterschriften geteilt durch Σ Bedarf, neu gerechnet aus den absoluten Summen (nicht der Mittelwert der Zeilen-Prozente). {{ $totalFulfilment['reason'] }}">
                            @include('recruiting::livewire.statistics.light', ['light' => $totalFulfilment, 'label' => 'Erfüllung gesamt'])
                            @if ($totalFulfilment['bedarf'] !== null)
                                <div class="text-[11px] font-normal tabular-nums text-[color:var(--ui-muted)]">
                                    {{ $totalFulfilment['signed'] }} von {{ $totalFulfilment['bedarf'] }}
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center">
                            @include('recruiting::livewire.statistics.light', ['light' => $totalPipeline, 'label' => 'Pipeline gesamt'])
                        </td>
                        <td class="border-l border-[var(--ui-border)]/60 px-3 py-3 text-center whitespace-nowrap">
                            <span class="cursor-help text-xs font-normal text-[color:var(--ui-muted)]" title="{{ $einsatzTitle }}">–</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Fussnote statt stillem Topf: die Erfuellungsquote der Gesamt-Zeile
             laesst Ausschreibungen ohne gepflegten Bedarf aus (sonst wuerden sie
             die Quote verwaessern). Damit die Zeile nachrechenbar bleibt, wird die
             Differenz zur Spalte „Unterschrieben" hier BENANNT — die Zahlen
             gehen auf: Zähler + hier genannte Unterschriften = Spaltenwert. --}}
        @if ($totalFulfilment['excluded_groups'] > 0)
            <div class="mt-2 text-xs text-[color:var(--ui-muted)]">
                Erfüllung gesamt: {{ $totalFulfilment['signed'] }} von
                {{ $totalFulfilment['bedarf'] ?? 0 }} benötigten Einstellungen.
                Nicht in dieser Quote:
                {{ $totalFulfilment['excluded_groups'] }}
                {{ $totalFulfilment['excluded_groups'] === 1 ? 'Ausschreibung' : 'Ausschreibungen' }}
                ohne gepflegten Bedarf mit {{ $totalFulfilment['excluded_signed'] }}
                {{ $totalFulfilment['excluded_signed'] === 1 ? 'Unterschrift' : 'Unterschriften' }} —
                in der Spalte „Unterschrieben" sind sie enthalten.
            </div>
        @endif
    @endif
</x-ui-panel>
