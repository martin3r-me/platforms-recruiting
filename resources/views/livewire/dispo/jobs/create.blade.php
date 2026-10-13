{{-- Jobs anbieten → Neue Anfrage (Spec 2026-10-12 §4.1–4.5). Alle Texte in Du-Form; eine Zusage ist nie „gebucht“. --}}
@php
    $schritte = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobCreateState::schritte();
    $filialen = $this->filialOptionen;
@endphp
<div class="p-6 space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <a href="{{ route('recruiting.dispo.jobs.index') }}" class="text-sm text-blue-700 hover:underline">← Jobs anbieten</a>
            <h1 class="mt-1 text-xl font-semibold">Neue Anfrage</h1>
        </div>
    </div>

    {{-- Schritt-Leiste: zurueck darf man jederzeit, vorwaerts nur ueber „Weiter“. --}}
    <ol class="flex flex-wrap items-center gap-2 text-sm">
        @foreach ($schritte as $nr => $name)
            @php
                $leisteKlasse = $nr === $schritt
                    ? 'border-blue-600 bg-blue-50 font-semibold text-blue-700'
                    : ($nr < $schritt ? 'border-gray-300 text-gray-700 hover:bg-gray-50' : 'border-gray-200 text-gray-400');
            @endphp
            <li>
                @if ($nr < $schritt)
                    <button type="button" wire:click="zuSchritt({{ $nr }})" class="rounded-full border px-3 py-1 {{ $leisteKlasse }}">{{ $nr }} {{ $name }}</button>
                @else
                    <span class="inline-block rounded-full border px-3 py-1 {{ $leisteKlasse }}">{{ $nr }} {{ $name }}</span>
                @endif
            </li>
        @endforeach
    </ol>

    @if ($fehler !== '')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ $fehler }}</div>
    @endif

    {{-- ============================ 1 Was suchst du? ============================ --}}
    @if ($schritt === 1)
        @php
            $heute = now()->toDateString();
            $vaListe = $this->events;
            $zeitraumOk = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobCreateState::zeitraumProblem(['heute' => $heute, 'von' => $von, 'bis' => $bis]) === null;
        @endphp
        <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-2">
                    <div class="font-medium text-gray-700">Für welche Filiale suchst du Leute?</div>
                    @if ($filialen === [])
                        <p class="text-sm text-gray-500">Es sind noch keine Veranstaltungen aus ZAS da.</p>
                    @else
                        <select wire:model.live="filialNr" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="">– Filiale wählen –</option>
                            @foreach ($filialen as $nr => $label)
                                <option value="{{ $nr }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <div class="space-y-2">
                    <div class="font-medium text-gray-700">In welchem Zeitraum?</div>
                    <p class="text-xs text-gray-500">Vorgeschlagen sind die nächsten vier Wochen. Vergangene Tage gehen nicht.</p>
                    <div class="flex flex-wrap items-end gap-4">
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs font-medium text-gray-500">von</span>
                            <input type="date" min="{{ $heute }}" wire:model.live="von" class="rounded border border-gray-300 px-2 py-1 text-sm">
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs font-medium text-gray-500">bis</span>
                            <input type="date" min="{{ $heute }}" wire:model.live="bis" class="rounded border border-gray-300 px-2 py-1 text-sm">
                        </label>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-100 pt-3 space-y-2">
                <div class="font-medium text-gray-700">Für welche Veranstaltungen?</div>
                @if (!ctype_digit($filialNr))
                    <p class="text-sm text-gray-500">Wähle zuerst eine Filiale.</p>
                @elseif (!$zeitraumOk)
                    <p class="text-sm text-gray-500">Gib einen gültigen Zeitraum an.</p>
                @elseif ($vaListe->isEmpty())
                    <p class="text-sm text-gray-500">In diesem Zeitraum hat die Filiale keine Veranstaltung mit Einsatztagen.</p>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach ($vaListe as $va)
                            @php
                                $vaDatum = $va->starts_on ? $va->starts_on->format('d.m.Y') : '–';
                                if ($va->ends_on && $va->starts_on && !$va->ends_on->equalTo($va->starts_on)) {
                                    $vaDatum = $va->starts_on->format('d.m.') . '–' . $va->ends_on->format('d.m.Y');
                                }
                                $vaOrt = $va->ort ?: $va->venue_text;
                                $vaZeile = implode(' · ', array_filter([$vaDatum, $vaOrt, $va->einbuchungen . ' ' . ($va->einbuchungen === 1 ? 'Einbuchung' : 'Einbuchungen')]));
                            @endphp
                            <label wire:key="va-{{ $va->id }}" class="flex cursor-pointer items-start gap-3 py-2 text-sm">
                                <input type="checkbox" value="{{ $va->id }}" wire:model="eventIds" class="mt-0.5 rounded border-gray-300">
                                <span class="flex-1">
                                    <span class="font-medium text-gray-800">{{ $va->name }}</span>
                                    <span class="block text-xs text-gray-500">{{ $vaZeile }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============================ 2 Schichten ============================ --}}
    @if ($schritt === 2)
        @php
            $optionen = $this->schichtOptionen;
            $gewaehlt = $this->gewaehlteEvents;
            $eventTage = $this->eventTage;
            $katalog = $this->taetigkeitKatalog;
        @endphp
        <p class="text-sm text-gray-500">Trag bei jeder Schicht ein, wie viele Leute du suchst. Leer heißt: diese Schicht wird nicht angeboten.</p>
        @forelse ($gewaehlt as $vaId => $va)
            @php
                $vaTage = $optionen[(int) $vaId] ?? [];
                $aktiveTage = $tage[(int) $vaId] ?? [];
            @endphp
            <div wire:key="schichten-{{ $vaId }}" class="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
                <div class="font-medium text-gray-800">{{ $va->name }}</div>
                @if ($vaTage === [])
                    <p class="text-sm text-gray-500">In ZAS stehen für diese Veranstaltung im Zeitraum noch keine Schichten. Du kannst unten eine andere Schicht hinzufügen.</p>
                @else
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (array_keys($vaTage) as $tag)
                            @php
                                $pilleAktiv = in_array($tag, $aktiveTage, true);
                                $pilleKlasse = $pilleAktiv ? 'border-blue-600 bg-blue-50 font-semibold text-blue-700' : 'border-gray-200 text-gray-500 hover:bg-gray-50';
                            @endphp
                            <button type="button" wire:click="tagUmschalten({{ (int) $vaId }}, '{{ $tag }}')"
                                    class="rounded-full border px-2 py-0.5 text-xs {{ $pilleKlasse }}">{{ \Illuminate\Support\Carbon::parse($tag)->locale('de')->isoFormat('dd DD.MM.') }}</button>
                        @endforeach
                    </div>
                    @foreach ($vaTage as $tag => $schichten)
                        @if (in_array($tag, $aktiveTage, true))
                            <div wire:key="tag-{{ $vaId }}-{{ $tag }}">
                                <div class="mb-1 text-xs font-semibold text-gray-600">{{ \Illuminate\Support\Carbon::parse($tag)->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}</div>
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-xs text-gray-500">
                                            <th class="py-1 pr-3 font-medium">Zeit</th>
                                            <th class="py-1 pr-3 font-medium">Tätigkeit</th>
                                            <th class="py-1 pr-3 font-medium">Zeilen</th>
                                            <th class="py-1 pr-3 font-medium">laut ZAS unbesetzt</th>
                                            <th class="py-1 font-medium">gesucht</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($schichten as $s)
                                            @php
                                                $spanne = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit::spanne($s['von'], $s['bis'] ?? '');
                                            @endphp
                                            <tr wire:key="s-{{ $s['key'] }}" class="border-t border-gray-100">
                                                <td class="py-1 pr-3 tabular-nums">{{ $spanne !== '' ? $spanne : '–' }}</td>
                                                <td class="py-1 pr-3">{{ $s['taetigkeit'] !== '' ? $s['taetigkeit'] : '–' }}</td>
                                                <td class="py-1 pr-3 tabular-nums">{{ $s['zeilen'] }}</td>
                                                <td class="py-1 pr-3 text-xs text-amber-700">
                                                    @if ($s['platzhalter'] > 0)
                                                        laut ZAS {{ $s['platzhalter'] }} unbesetzt
                                                    @endif
                                                </td>
                                                <td class="py-1">
                                                    <input type="number" min="0" max="999" inputmode="numeric" wire:model="gesucht.{{ $s['key'] }}"
                                                           class="w-20 rounded border border-gray-300 px-2 py-1 text-sm">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endforeach
                @endif
            </div>
        @empty
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500">Keine Veranstaltung gewählt — geh einen Schritt zurück.</div>
        @endforelse

        {{-- Andere Schicht: Schicht, die in ZAS noch gar nicht angelegt ist. --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
            <div class="font-medium text-gray-700">Andere Schicht hinzufügen</div>
            <p class="text-xs text-gray-500">Für Schichten, die in ZAS noch nicht angelegt sind.</p>
            @if ($andere !== [])
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500">
                            <th class="py-1 pr-3 font-medium">Veranstaltung</th>
                            <th class="py-1 pr-3 font-medium">Tag</th>
                            <th class="py-1 pr-3 font-medium">Zeit</th>
                            <th class="py-1 pr-3 font-medium">Tätigkeit</th>
                            <th class="py-1 pr-3 font-medium">gesucht</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($andere as $i => $a)
                            @php
                                $andereVa = $gewaehlt->get((int) $a['event']);
                                $andereSpanne = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit::spanne($a['von'], $a['bis'] ?? '');
                            @endphp
                            <tr wire:key="andere-{{ $i }}" class="border-t border-gray-100">
                                <td class="py-1 pr-3">{{ $andereVa?->name ?? '–' }}</td>
                                <td class="py-1 pr-3 tabular-nums">{{ \Illuminate\Support\Carbon::parse($a['datum'])->format('d.m.Y') }}</td>
                                <td class="py-1 pr-3 tabular-nums">{{ $andereSpanne !== '' ? $andereSpanne : '–' }}</td>
                                <td class="py-1 pr-3">{{ $a['taetigkeit'] }}</td>
                                <td class="py-1 pr-3">
                                    <input type="number" min="0" max="999" inputmode="numeric" wire:model="andere.{{ $i }}.gesucht" class="w-20 rounded border border-gray-300 px-2 py-1 text-sm">
                                </td>
                                <td class="py-1 text-right">
                                    <button type="button" wire:click="andereEntfernen({{ $i }})" class="text-xs text-red-600 hover:underline">entfernen</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if ($katalog === [])
                <p class="text-xs text-amber-700">Die Tätigkeiten aus ZAS fehlen noch — eine andere Schicht geht erst, wenn ZAS sie geliefert hat.</p>
            @else
                <div class="flex flex-wrap items-end gap-3">
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-xs font-medium text-gray-500">Veranstaltung und Tag</span>
                        <select wire:model="neuEventTag" class="rounded border border-gray-300 px-2 py-1 text-sm">
                            <option value="">– wählen –</option>
                            @foreach ($gewaehlt as $vaId => $va)
                                @foreach ($eventTage[(int) $vaId] ?? [] as $tag)
                                    <option value="{{ (int) $vaId }}|{{ $tag }}">{{ $va->name }} · {{ \Illuminate\Support\Carbon::parse($tag)->format('d.m.Y') }}</option>
                                @endforeach
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-xs font-medium text-gray-500">Beginn</span>
                        <input type="time" wire:model="neuVon" class="rounded border border-gray-300 px-2 py-1 text-sm">
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-xs font-medium text-gray-500">Ende <span class="font-normal">(optional)</span></span>
                        <input type="time" wire:model="neuBis" class="rounded border border-gray-300 px-2 py-1 text-sm">
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-xs font-medium text-gray-500">Tätigkeit</span>
                        <select wire:model="neuTaetigkeit" class="rounded border border-gray-300 px-2 py-1 text-sm">
                            <option value="">– wählen –</option>
                            @foreach ($katalog as $t)
                                <option value="{{ $t }}">{{ $t }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-xs font-medium text-gray-500">gesucht</span>
                        <input type="number" min="1" max="999" inputmode="numeric" wire:model="neuGesucht" class="w-20 rounded border border-gray-300 px-2 py-1 text-sm">
                    </label>
                    <button type="button" wire:click="andereHinzufuegen" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Hinzufügen</button>
                </div>
            @endif
        </div>
    @endif

    {{-- ============================ 3 Senden ============================ --}}
    @if ($schritt === 3)
        @php
            $bloecke = $this->blockOptionen;
            $baender = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoEinsatzZaehler::BAENDER;
            $zahlen = $this->zahlen ?? ['empfaenger' => 0, 'namen' => [], 'toepfe' => [], 'mit_passend' => 0];
            $vorlagen = $this->vorlagen;
            $vorschau = $this->vorschau;
            $problem = $this->vorlagenProblem;
            // Anlass-Pillen: ohne hinterlegte Vorlage gesperrt (Hinweis + Link zu den Einstellungen).
            $anlassIds = $this->anlassVorlagen;
            $anlaesse = [];
            foreach (\Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnlass::ALLE as $anlassKey => $anlassLabel) {
                $frei = ($anlassIds[$anlassKey] ?? null) !== null;
                $aktiv = $frei && $anlass === $anlassKey;
                $anlaesse[] = [
                    'key'    => $anlassKey,
                    'label'  => $anlassLabel,
                    'frei'   => $frei,
                    'klasse' => $aktiv
                        ? 'rounded-full border border-blue-600 bg-blue-600 px-3 py-1 text-sm font-medium text-white'
                        : ($frei
                            ? 'rounded-full border border-gray-300 bg-white px-3 py-1 text-sm text-gray-700 hover:border-blue-400'
                            : 'cursor-not-allowed rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-sm text-gray-400'),
                ];
            }
            $personen = $zahlen['empfaenger'] === 1 ? 'Person' : 'Personen';
            // Startzeit steht bewusst nicht im Text: Startart/Minuten sind deferred gebunden (kein Neurechnen je Tastendruck).
            $bestaetigung = 'Anfrage anlegen und Welle 1 starten? Laut Vorschau werden ' . $zahlen['empfaenger'] . ' ' . $personen . ' angeschrieben — die genaue Zahl wird beim Start neu berechnet.';
            $sendenGesperrt = $problem !== null;
        @endphp

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="space-y-4">
                <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2">
                    <div class="font-medium text-gray-700">Mitarbeiterfiliale</div>
                    <p class="text-xs text-gray-500">Wen schreibst du an? Mehrfachauswahl möglich.</p>
                    @foreach ($bloecke as $b)
                        @php $blockWert = $b['block'] === null ? \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZahlen::OHNE : (string) $b['block']; @endphp
                        <label wire:key="block-{{ $blockWert }}" class="flex cursor-pointer items-start gap-2 text-sm">
                            <input type="checkbox" value="{{ $blockWert }}" wire:model.live="maBloecke" class="mt-0.5 rounded border-gray-300">
                            <span>
                                <span class="font-medium text-gray-800">{{ $b['label'] }}</span>
                                <span class="text-gray-500">· {{ $b['count'] }} Mitarbeiter</span>
                                @if ($b['nummern'] !== [])
                                    <span class="block text-xs text-gray-400">Kostenstellen {{ implode(', ', $b['nummern']) }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2">
                    <div class="font-medium text-gray-700">Gruppen nach Einsätzen in diesem Monat</div>
                    @foreach ($baender as $bandKey => $bandLabel)
                        <label wire:key="band-{{ $bandKey }}" class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="checkbox" value="{{ $bandKey }}" wire:model.live="gruppen" class="rounded border-gray-300">
                            <span>{{ $bandLabel }}</span>
                        </label>
                    @endforeach
                    <label class="mt-2 flex cursor-pointer items-start gap-2 border-t border-gray-100 pt-2 text-sm">
                        <input type="checkbox" wire:model.live="nurPassend" class="mt-0.5 rounded border-gray-300">
                        <span>
                            nur Mitarbeiter, die die gesuchte Tätigkeit laut ZAS haben
                            <span class="text-gray-500">· {{ $zahlen['mit_passend'] }} {{ $zahlen['mit_passend'] === 1 ? 'Person' : 'Personen' }}</span>
                            @if ($zahlen['mit_passend'] === 0)
                                <span class="block text-xs text-amber-700">Niemand passt — vielleicht fehlen die Tätigkeiten aus ZAS noch.</span>
                            @endif
                        </span>
                    </label>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2">
                    <div class="font-medium text-gray-700">Wie zählen die Zusagen?</div>
                    <label class="flex cursor-pointer items-start gap-2 text-sm">
                        <input type="radio" value="sammeln" wire:model="modus" class="mt-0.5 border-gray-300">
                        <span><span class="font-medium">Alle Zusagen sammeln</span>
                            <span class="block text-xs text-gray-500">Jeder kann zusagen, auch wenn schon genug da sind. Du suchst danach aus, wen du in ZAS einträgst.</span></span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-2 text-sm">
                        <input type="radio" value="zuerst" wire:model="modus" class="mt-0.5 border-gray-300">
                        <span><span class="font-medium">Wer zuerst kommt</span>
                            <span class="block text-xs text-gray-500">Ist eine Schicht voll, kann niemand mehr dafür zusagen.</span></span>
                    </label>
                </div>
            </div>

            <div class="space-y-4">
                {{-- Anlass als Pillen, darunter die Vorlage des Anlasses als Sprechblase (Kampagnen-Muster). --}}
                <div wire:key="vorlagen-karte" class="rounded-lg border border-gray-200 bg-white p-4 space-y-2 text-sm">
                    <div class="font-medium text-gray-700">Anlass</div>
                    @if ($vorlagen === [])
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                            {{ $problem }}
                            <a href="{{ route('recruiting.dispo.settings') }}" class="ml-1 font-medium underline">Zu den Einstellungen</a>
                        </div>
                    @else
                        <div class="flex flex-wrap items-start gap-2">
                            @foreach ($anlaesse as $a)
                                <div wire:key="anlass-{{ $a['key'] }}" class="flex flex-col items-start">
                                    @if ($a['frei'])
                                        <button type="button" wire:click="anlassWaehlen('{{ $a['key'] }}')" class="{{ $a['klasse'] }}">{{ $a['label'] }}</button>
                                    @else
                                        <button type="button" disabled class="{{ $a['klasse'] }}">{{ $a['label'] }}</button>
                                        <a href="{{ route('recruiting.dispo.settings') }}" class="mt-0.5 text-[11px] text-gray-500 underline">in den Einstellungen hinterlegen</a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="pt-1 font-medium text-gray-700">Nachricht</div>
                        @if ($problem === null && $vorschau !== null)
                            <div class="rounded-lg bg-gray-50 px-3 py-2 whitespace-pre-line text-gray-800">{{ $vorschau['text'] }}</div>
                            @foreach ($vorschau['buttons'] as $buttonText)
                                <div class="mt-1 rounded-lg border border-gray-200 px-3 py-1 text-center font-medium text-blue-700">{{ $buttonText }}</div>
                            @endforeach
                            <div class="text-[11px] text-gray-500">Beispiel mit „{{ \Platform\Recruiting\Support\CampaignTemplatePreview::BEISPIEL_VORNAME }}“ — beim Senden steht der Vorname der Person.</div>
                        @else
                            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-800">{{ $problem }}</div>
                        @endif
                    @endif
                </div>

                {{-- Zahlen vor dem Senden: jeder Ausschluss mit Namen aufklappbar. --}}
                <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2 text-sm">
                    <div class="text-base">Sendet an <strong>{{ $zahlen['empfaenger'] }}</strong> {{ $personen }}</div>
                    @if ($zahlen['namen'] !== [])
                        <details class="text-xs text-gray-600">
                            <summary class="cursor-pointer">Namen zeigen</summary>
                            <div class="mt-1">{{ implode(', ', $zahlen['namen']) }}</div>
                        </details>
                    @endif
                    @foreach ($zahlen['toepfe'] as $topf)
                        <details wire:key="topf-{{ $topf['key'] }}" class="text-xs text-gray-600">
                            <summary class="cursor-pointer">{{ $topf['anzahl'] }} {{ $topf['label'] }}</summary>
                            <div class="mt-1">{{ implode(', ', $topf['namen']) }}</div>
                        </details>
                    @endforeach
                    <p class="text-[11px] text-gray-400">Beim Start wird neu gerechnet — wer bis dahin eingeteilt ist, fällt heraus.</p>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2 text-sm">
                    <div class="font-medium text-gray-700">Wann senden?</div>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="radio" value="jetzt" wire:model="startModus" class="border-gray-300">
                        <span>Jetzt senden</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="radio" value="spaeter" wire:model="startModus" class="border-gray-300">
                        <span>In</span>
                        <input type="number" min="1" max="1440" inputmode="numeric" wire:model="minuten" class="w-20 rounded border border-gray-300 px-2 py-1 text-sm">
                        <span>Minuten senden</span>
                    </label>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Navigation ============================ --}}
    <div class="flex items-center justify-between">
        <div>
            @if ($schritt > 1)
                <button type="button" wire:click="zurueck" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Zurück</button>
            @endif
        </div>
        <div>
            @if ($schritt < 3)
                <button type="button" wire:click="weiter" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Weiter</button>
            @else
                @if ($sendenGesperrt)
                    <button type="button" disabled class="cursor-not-allowed rounded-lg bg-gray-300 px-4 py-2 text-sm font-medium text-white">Anfrage anlegen und Welle 1 starten</button>
                @else
                    <button type="button" wire:click="anlegenUndSenden" wire:confirm="{{ $bestaetigung }}"
                            wire:loading.attr="disabled" wire:target="anlegenUndSenden"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Anfrage anlegen und Welle 1 starten</button>
                @endif
            @endif
        </div>
    </div>
</div>
