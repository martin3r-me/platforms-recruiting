{{-- Jobs anbieten → Seite einer Anfrage (Spec 2026-10-12 §4.6–4.7). Du-Form; eine Zusage ist nie „gebucht“. --}}
@php
    $anfrage = $this->vorgang;
    $schichten = $this->schichten;
    $fortschritt = $this->fortschritt;
    $wellen = $this->wellen;
    $rueck = $this->rueckmeldungen;
    $filialLabel = \Platform\Recruiting\Support\Filialen::code($anfrage->filial_nr) ?? ('#' . $anfrage->filial_nr);
    $anlassLabel = ($anfrage->anlass ?? '') !== '' ? \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnlass::label((string) $anfrage->anlass) : '—';
    $modusLabel = $anfrage->modus === \Platform\Recruiting\Models\RecDispoJobRequest::MODUS_ZUERST ? 'Wer zuerst kommt' : 'Alle Zusagen sammeln';
    $statusLabel = match (true) {
        !$offen => 'abgeschlossen' . ($anfrage->closed_at ? ' am ' . $anfrage->closed_at->format('d.m.Y') : ''),
        $anfrage->status === \Platform\Recruiting\Models\RecDispoJobRequest::STATUS_LAEUFT => 'läuft',
        default => 'wartet auf den Start',
    };
    $statusKlasse = !$offen ? 'border-gray-200 bg-gray-50 text-gray-600' : 'border-green-200 bg-green-50 text-green-700';
    // Haengende Wellen pollen nicht im 3-s-Takt — da kommt so schnell nichts.
    $irgendwasLaeuft = collect($wellen)->contains(fn ($w) => $w['laeuft'] || ($w['faellig'] && !$w['haengt']));
    // Laufende/startende Welle: alle 3 s. Sonst (auch bei geplanten Wellen) leise alle 30 s, damit neue Zusagen erscheinen.
    $poll = !$offen ? '' : ($irgendwasLaeuft ? 'wire:poll.3s' : 'wire:poll.visible.30s');
    $schliessenText = 'Anfrage abschließen? Danach kann niemand mehr zusagen und geplante Wellen starten nicht mehr.';
@endphp
<div class="p-6 space-y-6" {!! $poll !!}>
    {{-- ============================ Kopf ============================ --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('recruiting.dispo.jobs.index') }}" class="text-sm text-blue-700 hover:underline">← Jobs anbieten</a>
            <h1 class="mt-1 text-xl font-semibold">Anfrage {{ $filialLabel }} · {{ $anfrage->zeitraum_von->format('d.m.') }}–{{ $anfrage->zeitraum_bis->format('d.m.Y') }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                <span class="rounded-full border px-2 py-0.5 text-xs {{ $statusKlasse }}">{{ $statusLabel }}</span>
                <span>{{ $modusLabel }}</span>
                <span>· Anlass: {{ $anlassLabel }}</span>
            </div>
            <div class="mt-0.5 text-xs text-gray-400">Vorlage {{ $this->vorlageName }}</div>
        </div>
        @if ($offen)
            <button type="button" wire:click="abschliessen" wire:confirm="{{ $schliessenText }}"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Anfrage abschließen</button>
        @endif
    </div>

    @if ($fehler !== '')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ $fehler }}</div>
    @endif
    @if ($hinweis !== '')
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">{{ $hinweis }}</div>
    @endif

    {{-- ============================ Wellen ============================ --}}
    <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
        <div class="flex items-center justify-between">
            <div class="font-medium text-gray-700">Wellen</div>
            @if ($offen)
                <button type="button" wire:click="neueWelleUmschalten" class="text-sm text-blue-700 hover:underline">{{ $neueWelleOffen ? 'Schließen' : 'Neue Welle' }}</button>
            @endif
        </div>
        @forelse ($wellen as $w)
            @php
                $stand = $w['stand'];
                // Fehlerzeilen der Welle (Vorlagen-Stopp, Abbruch, einzelne Empfaenger) — sonst nirgends sichtbar.
                $standFehler = array_values(array_filter(array_map('strval', (array) ($stand['errors'] ?? [])), fn ($z) => $z !== ''));
                $standFertig = (bool) ($stand['done'] ?? false);
                $standLeer = (int) ($stand['total'] ?? 0) === 0 && $standFertig;
                $welleGestoppt = $standLeer && $standFehler !== [];
                $standToepfe = [];
                foreach ((array) ($stand['skipped_toepfe'] ?? []) as $topfKey => $topfAnzahl) {
                    if ((int) $topfAnzahl > 0) {
                        $standToepfe[] = (int) $topfAnzahl . ' ' . ($toepfeLabels[$topfKey] ?? $topfKey);
                    }
                }
                if ($w['laeuft']) {
                    $wZeit = 'läuft seit ' . $w['gestartet']->format('H:i') . ' Uhr';
                } elseif ($w['fertig'] !== null && $w['gestartet'] !== null) {
                    $wZeit = 'gestartet ' . $w['gestartet']->format('d.m. H:i') . ' · fertig ' . $w['fertig']->format('H:i') . ' Uhr';
                } elseif ($w['fertig'] !== null) {
                    $wZeit = 'beendet ' . $w['fertig']->format('d.m. H:i') . ' Uhr';
                } elseif (!$offen && $w['wartet']) {
                    // Der Job beansprucht Wellen geschlossener Anfragen nicht mehr — kein Fall fuer den Hintergrunddienst.
                    $wZeit = 'Startet nicht mehr — die Anfrage ist abgeschlossen.';
                } elseif ($w['geplant'] !== null && $w['geplant']->isFuture()) {
                    $wZeit = 'geplant für ' . $w['geplant']->format('d.m. H:i') . ' Uhr';
                } elseif ($w['haengt']) {
                    $wZeit = 'Die Welle wurde noch nicht gestartet — läuft der Hintergrunddienst?';
                } else {
                    $wZeit = 'startet gleich';
                }
                $wHaengtHinweis = $offen && $w['haengt'];
                $abbrechenText = 'Welle ' . $w['nr'] . ' abbrechen? Sie wird gelöscht, niemand wird angeschrieben.';
            @endphp
            <div wire:key="welle-{{ $w['id'] }}" class="rounded-lg border border-gray-100 p-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-semibold">Welle {{ $w['nr'] }}</span>
                        <span class="text-gray-600">· {{ implode(', ', $w['gruppen']) }}</span>
                        <span class="text-gray-500">· {{ implode(', ', $w['bloecke']) }}</span>
                        <span class="text-gray-500">· {{ $w['stati'] }}</span>
                        @if ($w['nur_passend'])
                            <span class="text-gray-500">· nur passende Tätigkeit</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-3 text-xs text-gray-500">
                        <span @class(['font-medium text-amber-700' => $wHaengtHinweis])>{{ $wZeit }}</span>
                        @if ($w['wartet'])
                            <button type="button" wire:click="abbrechen({{ $w['id'] }})" wire:confirm="{{ $abbrechenText }}" class="text-red-600 hover:underline">Abbrechen</button>
                        @endif
                    </div>
                </div>
                @if ($stand !== null)
                    <div class="mt-1 text-xs text-gray-600">
                        @if ($welleGestoppt)
                            <div class="text-red-700">
                                <div class="font-medium">Diese Welle konnte nicht gesendet werden:</div>
                                @foreach ($standFehler as $fehlerZeile)
                                    <div>{{ $fehlerZeile }}</div>
                                @endforeach
                            </div>
                        @elseif ($standLeer)
                            Niemand übrig — alle aus der Auswahl sind schon angeschrieben oder fallen heraus.
                        @else
                            <strong>{{ (int) ($stand['sent'] ?? 0) }}</strong> von {{ (int) ($stand['total'] ?? 0) }} angeschrieben
                            @if ((int) ($stand['failed'] ?? 0) > 0)
                                · <span class="text-red-700">{{ (int) $stand['failed'] }} nicht zugestellt</span>
                            @endif
                            @if (!$standFertig)
                                · läuft …
                            @endif
                            @if ($standFehler !== [])
                                <div class="text-red-700">
                                    @foreach ($standFehler as $fehlerZeile)
                                        <div>{{ $fehlerZeile }}</div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @if ($standToepfe !== [])
                            <div class="text-gray-500">Nicht angeschrieben: {{ implode(' · ', $standToepfe) }}</div>
                        @endif
                    </div>
                @elseif ($w['laeuft'])
                    <div class="mt-1 text-xs text-gray-500">läuft …</div>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Noch keine Welle.</p>
        @endforelse

        {{-- Neue Welle: gleiche Auswahl wie Schritt 3; wer in dieser Anfrage schon angeschrieben ist, fällt heraus. --}}
        @if ($neueWelleOffen && $offen)
            @php
                $bloecke = $this->blockOptionen;
                $baender = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoEinsatzZaehler::BAENDER;
                $wz = $wZahlen ?? ['empfaenger' => 0, 'namen' => [], 'toepfe' => [], 'mit_passend' => 0, 'ohne_vertrag' => 0];
                $wPersonen = $wz['empfaenger'] === 1 ? 'Person' : 'Personen';
                $wStartText = $wStartModus === 'spaeter' ? 'in ' . (ctype_digit(trim($wMinuten)) ? (int) trim($wMinuten) : '?') . ' Minuten' : 'jetzt';
                $wBestaetigung = 'Neue Welle ' . $wStartText . ' starten? Laut Vorschau werden ' . $wz['empfaenger'] . ' ' . $wPersonen . ' angeschrieben — die genaue Zahl wird beim Start neu berechnet.';
            @endphp
            <div class="rounded-lg border border-blue-100 bg-blue-50/40 p-3 space-y-3">
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 text-sm">
                    <div class="space-y-1">
                        <div class="font-medium text-gray-700">Mitarbeiterfiliale</div>
                        @foreach ($bloecke as $b)
                            @php $blockWert = $b['block'] === null ? \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZahlen::OHNE : (string) $b['block']; @endphp
                            <label wire:key="wblock-{{ $blockWert }}" class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" value="{{ $blockWert }}" wire:model.live="wBloecke" class="rounded border-gray-300">
                                <span>{{ $b['label'] }} <span class="text-gray-500">· {{ $b['count'] }}</span>
                                    @if ($b['inaktiv'] > 0)
                                        <span class="text-gray-400">· {{ number_format($b['inaktiv'], 0, ',', '.') }} inaktiv (werden nicht angeschrieben)</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                        <div class="pt-2 font-medium text-gray-700">Status in ZAS</div>
                        @foreach (\Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobRecipientPlanner::STATI as $statusKey => $statusLabel)
                            @php $statusAnzahl = (int) ($wz['je_status'][$statusKey] ?? 0); @endphp
                            <label wire:key="wstatus-{{ $statusKey }}" class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" value="{{ $statusKey }}" wire:model.live="wStati" class="rounded border-gray-300">
                                <span>{{ $statusLabel }} <span class="text-gray-500">· {{ $statusAnzahl }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    <div class="space-y-1">
                        <div class="font-medium text-gray-700">Gruppen nach Einsätzen in diesem Monat</div>
                        @foreach ($baender as $bandKey => $bandLabel)
                            <label wire:key="wband-{{ $bandKey }}" class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" value="{{ $bandKey }}" wire:model.live="wGruppen" class="rounded border-gray-300">
                                <span>{{ $bandLabel }}</span>
                            </label>
                        @endforeach
                        <label class="mt-2 flex cursor-pointer items-start gap-2">
                            <input type="checkbox" wire:model.live="wNurPassend" class="mt-0.5 rounded border-gray-300">
                            <span>nur mit passender Tätigkeit <span class="text-gray-500">· {{ $wz['mit_passend'] }}</span></span>
                        </label>
                    </div>
                    <div class="space-y-2">
                        <div>Sendet an <strong>{{ $wz['empfaenger'] }}</strong> {{ $wPersonen }}</div>
                        @if ($wz['ohne_vertrag'] > 0)
                            <div class="text-xs text-gray-500">davon {{ $wz['ohne_vertrag'] }} mit Status MA (brauchen neuen Vertrag)</div>
                        @endif
                        @if ($wz['namen'] !== [])
                            <details class="text-xs text-gray-600">
                                <summary class="cursor-pointer">Namen zeigen</summary>
                                <div class="mt-1">{{ implode(', ', $wz['namen']) }}</div>
                            </details>
                        @endif
                        @foreach ($wz['toepfe'] as $topf)
                            <details wire:key="wtopf-{{ $topf['key'] }}" class="text-xs text-gray-600">
                                <summary class="cursor-pointer">{{ $topf['anzahl'] }} {{ $topf['label'] }}</summary>
                                <div class="mt-1">{{ implode(', ', $topf['namen']) }}</div>
                            </details>
                        @endforeach
                        <div class="space-y-1 border-t border-gray-200 pt-2">
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="radio" value="jetzt" wire:model.live="wStartModus" class="border-gray-300">
                                <span>Jetzt senden</span>
                            </label>
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="radio" value="spaeter" wire:model.live="wStartModus" class="border-gray-300">
                                <span>In</span>
                                <input type="number" min="1" max="1440" inputmode="numeric" wire:model.live.debounce.500ms="wMinuten" class="w-20 rounded border border-gray-300 px-2 py-1 text-sm">
                                <span>Minuten senden</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="button" wire:click="neueWelle" wire:confirm="{{ $wBestaetigung }}" wire:loading.attr="disabled" wire:target="neueWelle"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Welle starten</button>
                </div>
            </div>
        @endif
    </div>

    {{-- ============================ Schichten + Rückmeldungen ============================ --}}
    <div class="space-y-4">
        <div class="font-medium text-gray-700">Schichten und Rückmeldungen</div>
        @php
            $plaetze = $fortschritt['gesucht'] === 1 ? 'Platz' : 'Plätzen';
            $balkenKlasse = $fortschritt['prozent'] >= 100 ? 'bg-green-500' : 'bg-blue-500';
        @endphp
        <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2">
            <div class="text-sm text-gray-800">
                <strong>{{ $fortschritt['besetzt'] }}</strong> von {{ $fortschritt['gesucht'] }} {{ $plaetze }} besetzt
                @if ($fortschritt['zugesagt'] > $fortschritt['besetzt'])
                    · {{ $fortschritt['zugesagt'] }} Zusagen insgesamt
                @endif
                · {{ $fortschritt['eingetragen'] }} in ZAS eingetragen
                · {{ $fortschritt['angekommen'] }} angekommen
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-2 rounded-full {{ $balkenKlasse }}" style="width: {{ $fortschritt['prozent'] }}%"></div>
            </div>
        </div>
        @foreach (array_merge($fortschritt['offen'], $fortschritt['voll']) as $s)
            @php
                $zeilen = $rueck['zeilen'][$s->id] ?? [];
                $schichtTitel = implode(' · ', array_filter([
                    $s->event?->name,
                    $s->datum->locale('de')->isoFormat('dd DD.MM.YYYY'),
                    $s->von !== '' ? \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit::spanne($s->von, $s->bis) . ' Uhr' : null,
                    $s->taetigkeit,
                ]));
                $voll = (int) $s->zugesagt >= (int) $s->gesucht;
                $kopfKlasse = $voll ? '-mx-4 -mt-4 rounded-t-lg bg-green-50 px-4 pt-4 pb-2' : '';
                $offeneHaken = count(array_filter($zeilen, fn ($z) => $z->zas_entered_at === null));
                $listeText = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasListe::text(array_map(
                    fn ($z) => $rueck['info'][(int) ($z->offer?->rec_employee_id ?? 0)] ?? ['name' => '#' . (int) ($z->offer?->rec_employee_id ?? 0), 'personnel_number' => '', 'phone' => ''],
                    $zeilen
                ));
                $alleText = $offeneHaken . ' ' . ($offeneHaken === 1 ? 'Zusage' : 'Zusagen') . ' als in ZAS eingetragen markieren?';
            @endphp
            <div wire:key="schicht-{{ $s->id }}" class="rounded-lg border bg-white p-4 space-y-2 {{ $voll ? 'border-green-200' : 'border-gray-200' }}">
                <div class="flex flex-wrap items-center justify-between gap-2 {{ $kopfKlasse }}">
                    <div class="font-medium {{ $voll ? 'text-green-800' : 'text-gray-800' }}">{{ $schichtTitel }}</div>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        <span class="rounded-full border border-gray-200 px-2 py-0.5 text-gray-700">gesucht {{ $s->gesucht }}</span>
                        <span class="rounded-full border px-2 py-0.5 {{ $voll ? 'border-green-200 bg-green-50 text-green-700' : 'border-gray-200 text-gray-700' }}">zugesagt {{ $s->zugesagt }}</span>
                        <span class="rounded-full border border-gray-200 px-2 py-0.5 text-gray-700">in ZAS eingetragen {{ $s->eingetragen }}</span>
                        <span class="rounded-full border border-gray-200 px-2 py-0.5 text-gray-700">angekommen {{ $s->angekommen }}</span>
                    </div>
                    @if ($offen && $zeilen !== [])
                        <div class="flex items-center gap-2" x-data="{ ok: false, zeigen: false, kopieren() {
                                const text = $refs.liste.value;
                                if (navigator.clipboard && window.isSecureContext) {
                                    navigator.clipboard.writeText(text).then(() => { this.ok = true; setTimeout(() => this.ok = false, 2000); });
                                } else {
                                    this.zeigen = !this.zeigen;
                                }
                            } }">
                            <button type="button" x-on:click="kopieren()" class="rounded-lg border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                <span x-show="!ok">Liste kopieren</span><span x-show="ok" x-cloak>kopiert</span>
                            </button>
                            <textarea x-ref="liste" x-show="zeigen" x-cloak readonly rows="3" class="w-72 rounded border border-gray-300 px-2 py-1 text-xs">{{ $listeText }}</textarea>
                            @if ($offeneHaken > 0)
                                <button type="button" wire:click="alleEintragen({{ (int) $s->id }})" wire:confirm="{{ $alleText }}"
                                        class="rounded-lg border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">Alle als in ZAS eingetragen markieren</button>
                            @endif
                        </div>
                    @endif
                </div>
                @if ($zeilen === [])
                    <p class="text-xs text-gray-400">Noch keine Zusage.</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500">
                                <th class="py-1 pr-3 font-medium">Name</th>
                                <th class="py-1 pr-3 font-medium">Zusage</th>
                                <th class="py-1 pr-3 font-medium">Handynummer</th>
                                <th class="py-1 pr-3 font-medium" title="Auftrags-Einsatztage im laufenden Monat">Einsätze</th>
                                <th class="py-1 pr-3 font-medium">Tätigkeit</th>
                                <th class="py-1 pr-3 font-medium">Bewertung</th>
                                <th class="py-1 font-medium">ZAS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zeilen as $z)
                                @php
                                    $maId = (int) ($z->offer?->rec_employee_id ?? 0);
                                    $info = $rueck['info'][$maId] ?? null;
                                    $maName = $info['name'] ?? ('#' . $maId);
                                    $taetPasst = $info !== null && \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusagenInfo::passt((string) $s->taetigkeit, $info['taetigkeiten']);
                                    $taetListe = $info !== null && $info['taetigkeiten'] !== [] ? implode(', ', $info['taetigkeiten']) : 'keine Tätigkeiten aus ZAS';
                                    $bewertung = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusagenInfo::bewertung($info['ratings'] ?? []);
                                @endphp
                                <tr wire:key="zusage-{{ $z->id }}" class="border-t border-gray-100">
                                    <td class="py-1 pr-3">
                                        @if ($maId > 0)
                                            <a href="{{ route('recruiting.employees.show', ['employee' => $maId]) }}" class="text-blue-700 hover:underline">{{ $maName }}</a>
                                        @else
                                            {{ $maName }}
                                        @endif
                                        @if ($info !== null && $info['ohne_vertrag'])
                                            <span class="ml-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs text-amber-700">Status MA – braucht neuen Vertrag</span>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-3 tabular-nums text-gray-600">{{ $z->accepted_at->format('d.m. H:i') }}</td>
                                    <td class="py-1 pr-3 tabular-nums text-gray-600">{{ ($info['phone'] ?? '') !== '' ? $info['phone'] : '–' }}</td>
                                    <td class="py-1 pr-3 tabular-nums text-gray-700">{{ $info['einsaetze'] ?? 0 }}</td>
                                    <td class="py-1 pr-3">
                                        @if ($info === null || $info['taetigkeiten'] === [])
                                            <span class="rounded-full border border-gray-200 px-2 py-0.5 text-xs text-gray-400">keine aus ZAS</span>
                                        @elseif ($taetPasst)
                                            <span class="rounded-full border border-green-200 bg-green-50 px-2 py-0.5 text-xs text-green-700" title="{{ $taetListe }}">passt</span>
                                        @else
                                            <span class="rounded-full border border-gray-200 px-2 py-0.5 text-xs text-gray-600" title="{{ $taetListe }}">andere</span>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-3 tabular-nums text-gray-700" title="{{ $bewertung['title'] }}">{{ $bewertung['text'] }}</td>
                                    <td class="py-1">
                                        <div class="flex flex-wrap items-center gap-3">
                                            <label class="flex cursor-pointer items-center gap-1.5 text-xs">
                                                <input type="checkbox" wire:click="zasHaken({{ $z->id }})" @checked($z->zas_entered_at !== null) class="rounded border-gray-300">
                                                <span>in ZAS eingetragen</span>
                                            </label>
                                            @if ($z->zas_assignment_id !== null)
                                                <span class="rounded-full border border-green-200 bg-green-50 px-2 py-0.5 text-xs text-green-700">✓ in ZAS angekommen</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach
    </div>
</div>
