<div>
    @php
        $zahlen = $this->zahlen;
        $meldungKlasse = match ($meldungStufe) {
            'ok' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
            'hinweis' => 'bg-amber-50 border-amber-200 text-amber-900',
            'fehler' => 'bg-red-50 border-red-200 text-red-800',
            default => '',
        };
        $aktivKlasse = 'bg-[var(--ui-primary)] text-white border-[var(--ui-primary)]';
        $inaktivKlasse = 'bg-white text-[var(--ui-secondary)] border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]';
        $knopf = 'rounded border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50';
    @endphp

    @if ($meldung !== '')
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $meldungKlasse }}">{{ $meldung }}</div>
    @endif

    {{-- Unter-Reiter mit Zahl --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (\Platform\Recruiting\Livewire\HrDesk\Mitarbeiter::LISTEN as $schluessel => $titel)
            <button type="button" wire:click="zeigeListe('{{ $schluessel }}')"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors {{ $liste === $schluessel ? $aktivKlasse : $inaktivKlasse }}">
                {{ $titel }} <span class="ml-1 opacity-70">({{ $zahlen[$schluessel] ?? 0 }})</span>
            </button>
        @endforeach
        <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Name suchen …"
               class="ml-auto w-56 rounded-md border border-[var(--ui-border)] px-3 py-1.5 text-sm">
    </div>

    @if ($liste === 'antraege')
        @php
            $alle = $this->antraege;
            $zeilen = $this->seitenZeilen($alle);
        @endphp
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Kein offener Konto-Antrag.</div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[var(--ui-border)] bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-[var(--ui-muted-5)] text-left text-xs uppercase text-[var(--ui-muted)]">
                        <tr><th class="px-3 py-2">Person</th><th class="px-3 py-2">alt</th><th class="px-3 py-2">neu</th><th class="px-3 py-2">beantragt</th><th class="px-3 py-2">wirksam ab</th><th class="px-3 py-2">Quelle</th><th class="px-3 py-2">Zustand</th><th class="px-3 py-2">Aktion</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($zeilen as $z)
                            <tr wire:key="antrag-{{ $z['person_id'] }}">
                                <td class="px-3 py-2">
                                    @include('recruiting::livewire.hr-desk.partials.mensch', ['z' => $z])
                                </td>
                                <td class="px-3 py-2 tabular-nums">{{ $z['alt'] }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $z['neu'] }}</td>
                                <td class="px-3 py-2">{{ $z['beantragt'] }}</td>
                                <td class="px-3 py-2">{{ $z['wirksam'] }}</td>
                                <td class="px-3 py-2">{{ $z['quelle_text'] }}</td>
                                <td class="px-3 py-2">{{ $z['zustand'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <button type="button" class="{{ $knopf }}" wire:click="oeffne('stoppen', {{ $z['person_id'] }})" wire:loading.attr="disabled" wire:target="oeffne">Stoppen</button>
                                    @if ($z['kann_freigeben'])
                                        <button type="button" class="{{ $knopf }} ml-1 border-red-300 text-red-700" wire:click="oeffne('freigeben', {{ $z['person_id'] }})" wire:loading.attr="disabled" wire:target="oeffne">Freigeben</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($alle)])
        @endif

        @php
            $gestoppte = $this->gestoppte;
        @endphp
        <div class="mt-4">
            <button type="button" wire:click="$toggle('gestoppteZeigen')" class="text-xs font-semibold text-blue-700 hover:underline">
                Gestoppte Anträge (30 Tage) und Notfallsperren ({{ count($gestoppte['gestoppt']) + count($gestoppte['notfallsperren']) }})
            </button>
            @if ($gestoppteZeigen)
                <div class="mt-2 space-y-1 rounded-lg border border-[var(--ui-border)] bg-white p-3 text-sm">
                    @foreach ($gestoppte['gestoppt'] as $g)
                        <div>{{ $g['name'] }} — gestoppt am {{ $g['am'] }} von {{ $g['von'] }}: <span class="text-[var(--ui-muted)]">{{ $g['notiz'] }}</span></div>
                    @endforeach
                    @foreach ($gestoppte['notfallsperren'] as $s)
                        <div>{{ $s['name'] }} — Notfallweg gesperrt bis {{ $s['bis'] }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($liste === 'gesperrt')
        @php
            $alle = $this->gesperrt;
            $zeilen = $this->seitenZeilen($alle);
        @endphp
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Niemand ist gesperrt.</div>
        @else
            <div class="divide-y divide-gray-100 rounded-lg border border-[var(--ui-border)] bg-white">
                @foreach ($zeilen as $z)
                    @php
                        $pid = $z['person_id'] === null ? 'null' : (string) $z['person_id'];
                    @endphp
                    <div wire:key="gesperrt-{{ $z['schluessel'] }}" class="flex flex-wrap items-start gap-3 px-4 py-3">
                        <div class="min-w-[14rem]">@include('recruiting::livewire.hr-desk.partials.mensch', ['z' => $z])</div>
                        <div class="flex flex-1 flex-col gap-1.5">
                            @foreach ($z['gruende'] as $g)
                                @php
                                    $eid = $g['employee_id'] === null ? 'null' : (string) $g['employee_id'];
                                    $ziel = $g['aktion'] === 'portal_entsperren' ? 'null, ' . $eid : $pid;
                                @endphp
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700">{{ $g['text'] }}</span>
                                    @if ($g['aktion'] !== null)
                                        <button type="button" class="{{ $knopf }}" wire:click="oeffne('{{ $g['aktion'] }}', {{ $ziel }})" wire:loading.attr="disabled" wire:target="oeffne">{{ $g['knopf'] }}</button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($alle)])
        @endif
    @endif

    @if ($liste === 'erinnert')
        @php
            $alle = $this->erinnert;
            $zeilen = $this->seitenZeilen($alle);
        @endphp
        <label class="mb-3 inline-flex items-center gap-2 text-sm text-[var(--ui-secondary)]">
            <input type="checkbox" wire:model.live="ruhendeZeigen" class="h-4 w-4 rounded border-[var(--ui-border)]">
            Ruhende zeigen
        </label>
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Niemand wartet auf einen Anruf.</div>
        @else
            <div class="divide-y divide-gray-100 rounded-lg border border-[var(--ui-border)] bg-white">
                @foreach ($zeilen as $z)
                    @php
                        $pid = $z['person_id'] === null ? 'null' : (string) $z['person_id'];
                        $eid = $z['person_id'] === null ? (string) $z['employee_id'] : 'null';
                        $grau = $z['ruht'] ? 'opacity-50' : '';
                    @endphp
                    <div wire:key="erinnert-{{ $z['schluessel'] }}" class="flex flex-wrap items-start gap-3 px-4 py-3 {{ $grau }}">
                        <div class="min-w-[14rem]">
                            @include('recruiting::livewire.hr-desk.partials.mensch', ['z' => $z])
                            <div class="mt-0.5 text-xs text-[var(--ui-muted)]">nächster Einsatz: {{ $z['naechster_text'] }}</div>
                            @if ($z['ruhe_hinweis'] !== null)
                                <div class="mt-0.5 text-xs text-amber-800">{{ $z['ruhe_hinweis'] }}</div>
                            @endif
                        </div>
                        <div class="flex flex-1 flex-col gap-1.5">
                            @foreach ($z['chips'] as $chip)
                                @php
                                    $chipFarbe = $chip['rot'] ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700';
                                @endphp
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $chipFarbe }}" title="{{ $chip['tooltip'] }}">{{ $chip['text'] }}</span>
                                    @if ($chip['kann_erinnern'])
                                        <button type="button" class="{{ $knopf }}" wire:click="oeffne('erneut_erinnern', {{ $pid }}, {{ $eid }}, '{{ $chip['bezug'] }}')" wire:loading.attr="disabled" wire:target="oeffne">Erneut erinnern</button>
                                    @else
                                        <span class="text-xs text-[var(--ui-muted)]">{{ $chip['grau_text'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <button type="button" class="{{ $knopf }}" wire:click="oeffne('kontaktiert', {{ $pid }}, {{ $eid }})" wire:loading.attr="disabled" wire:target="oeffne">Kontaktiert</button>
                    </div>
                @endforeach
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($alle)])
        @endif
    @endif

    @if ($liste === 'pilot')
        @php
            $pilot = $this->pilot;
            $zeilen = $this->seitenZeilen($pilot['zeilen']);
        @endphp
        <div class="mb-3 flex flex-wrap gap-2">
            @foreach (\Platform\Recruiting\Support\KontoStatus::FILTER as $schluessel => $titel)
                <button type="button" wire:click="zeigePilotFilter('{{ $schluessel }}')"
                        class="rounded-lg border px-2.5 py-1 text-xs font-medium {{ $pilotFilter === $schluessel ? $aktivKlasse : $inaktivKlasse }}">
                    {{ $titel }} <span class="opacity-70">({{ $pilot['zahlen'][$schluessel] ?? 0 }})</span>
                </button>
            @endforeach
        </div>
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Keine Einträge.</div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[var(--ui-border)] bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-[var(--ui-muted-5)] text-left text-xs uppercase text-[var(--ui-muted)]">
                        <tr><th class="px-3 py-2">Person</th><th class="px-3 py-2">im Pilot seit</th><th class="px-3 py-2">Konto</th><th class="px-3 py-2">registriert</th><th class="px-3 py-2">letzte Anmeldung</th><th class="px-3 py-2">altes Portal zuletzt</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($zeilen as $z)
                            <tr wire:key="pilot-{{ $z['schluessel'] }}">
                                <td class="px-3 py-2">
                                    <a href="{{ $z['link'] }}" class="font-semibold text-blue-700 hover:underline">{{ $z['name'] }}</a>
                                    @if ($z['ohne_person'])
                                        <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10.5px] text-amber-800">keine Personen-Zeile — recruiting:personen-anlegen</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $z['pilot_seit'] }}</td>
                                <td class="px-3 py-2">{{ $z['status_text'] }}</td>
                                <td class="px-3 py-2">{{ $z['registriert'] }}</td>
                                <td class="px-3 py-2">{{ $z['letzte_anmeldung'] }}</td>
                                <td class="px-3 py-2">{{ $z['altes_portal'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($pilot['zeilen'])])
        @endif
    @endif

    @if ($liste === 'lohn')
        @php
            $alle = $this->lohn;
            $zeilen = $this->seitenZeilen($alle);
        @endphp
        <p class="mb-3 text-sm text-[var(--ui-secondary)]">Diese Menschen haben eine MA-Anstellung, aber wir kennen ihren RG-Zuschlag nicht (keine Bewerbung bei uns). Bis er eingetragen ist, geht die MA-Zeile ohne Zuschlag an ZAS.</p>
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Alle RG-Zuschläge sind bekannt.</div>
        @else
            <div class="divide-y divide-gray-100 rounded-lg border border-[var(--ui-border)] bg-white">
                @foreach ($zeilen as $z)
                    @php
                        $rgId = $z['rg_employee_id'];
                    @endphp
                    <div wire:key="lohn-{{ $z['schluessel'] }}" class="flex flex-wrap items-start gap-3 px-4 py-3">
                        <div class="min-w-[14rem]">
                            @include('recruiting::livewire.hr-desk.partials.mensch', ['z' => $z])
                            <div class="mt-0.5 text-xs text-[var(--ui-muted)]">{{ $z['begruendung'] }}</div>
                        </div>
                        <div class="flex flex-1 flex-wrap items-center gap-2">
                            <label class="text-xs text-[var(--ui-muted)]" for="zuschlag-{{ $rgId }}">RG-Zuschlag €/h</label>
                            <input id="zuschlag-{{ $rgId }}" type="text" inputmode="decimal" placeholder="1,10"
                                   wire:model="zuschlagEingabe.{{ $rgId }}"
                                   class="w-24 rounded-md border border-[var(--ui-border)] px-2 py-1 text-sm">
                            <button type="button" class="{{ $knopf }}" wire:click="speichereZuschlag({{ $rgId }})" wire:loading.attr="disabled" wire:target="speichereZuschlag">Speichern</button>
                        </div>
                    </div>
                @endforeach
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($alle)])
        @endif
    @endif

    {{-- Aktions-Modal: Pflichtnotiz (ausser Erneut erinnern) --}}
    @php
        $titel = \Platform\Recruiting\Livewire\HrDesk\Mitarbeiter::AKTIONEN[$aktion] ?? '';
        $notizLabel = $aktion === 'erneut_erinnern' ? 'Notiz (optional)' : 'Notiz (Pflicht, 5–500 Zeichen)';
        $gefaehrlich = $aktion === 'freigeben';
    @endphp
    <x-ui-modal size="md" model="modalOffen">
        <x-slot name="header">{{ $titel }}</x-slot>
        <div class="space-y-3 p-4">
            @if ($aktionWarnung !== '')
                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $aktionWarnung }}</div>
            @endif
            @if ($modalFehler !== '')
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $modalFehler }}</div>
            @endif
            <label class="block text-sm font-medium text-[var(--ui-secondary)]">{{ $notizLabel }}</label>
            <textarea wire:model="notiz" rows="3" maxlength="500"
                      class="w-full rounded-md border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm"></textarea>
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary-outline" wire:click="schliesse">Abbrechen</x-ui-button>
            @if ($gefaehrlich)
                <x-ui-button variant="danger" wire:click="bestaetige" wire:loading.attr="disabled" wire:target="bestaetige">Freigeben</x-ui-button>
            @else
                <x-ui-button variant="primary" wire:click="bestaetige" wire:loading.attr="disabled" wire:target="bestaetige">Bestätigen</x-ui-button>
            @endif
        </x-slot>
    </x-ui-modal>
</div>
