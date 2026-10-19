<div class="rounded-lg border border-[var(--ui-border)] bg-white p-4">
    @php
        $k = $this->kasten;
        $knopf = 'rounded border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50';
        $knopfRot = 'rounded border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50';
        $meldungKlasse = match ($meldungStufe) {
            'ok' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
            'hinweis' => 'bg-amber-50 border-amber-200 text-amber-900',
            'fehler' => 'bg-red-50 border-red-200 text-red-800',
            default => '',
        };
        $titel = \Platform\Recruiting\Livewire\Employees\KontoKasten::AKTIONEN[$aktion] ?? '';
        $antragLink = route('recruiting.dashboard.hr-desk') . '?tab=mitarbeiter&liste=antraege';
        $gruende = $k !== null ? $k['gruende'] : [];
        $verlauf = $k !== null ? $k['verlauf'] : [];
        $knoepfeAn = $k !== null && !$k['knoepfe_aus'];
    @endphp

    <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)]">Konto</h3>
        @if ($k !== null)
            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">{{ $k['status_text'] }}</span>
        @endif
    </div>

    @if ($meldung !== '')
        <div class="mt-3 rounded-lg border px-3 py-2 text-sm {{ $meldungKlasse }}">{{ $meldung }}</div>
    @endif

    @if ($k === null)
        <p class="mt-2 text-sm text-[var(--ui-muted)]">Keine Konto-Daten.</p>
    @elseif ($k['ohne_person'])
        <p class="mt-2 text-sm text-amber-900">Kein Konto in diesem Team. Falls die Akte noch keine Personen-Zeile hat: <code>recruiting:personen-anlegen</code>.</p>
    @else
        @if ($k['stillgelegt_ziel'] !== null)
            <p class="mt-2 text-sm text-amber-900">Diese Personen-Zeile ist stillgelegt; die Anstellungen hängen jetzt an Personen-Zeile #{{ $k['stillgelegt_ziel'] }}.</p>
        @endif
        <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-1 text-sm md:grid-cols-2">
            <div><dt class="inline text-[var(--ui-muted)]">Benutzername:</dt> <dd class="inline tabular-nums">{{ $k['nummer'] }}</dd></div>
            <div><dt class="inline text-[var(--ui-muted)]">Letzte Anmeldung (Konto):</dt> <dd class="inline">{{ $k['letzte_anmeldung'] }}</dd></div>
            <div><dt class="inline text-[var(--ui-muted)]">Altes Portal zuletzt:</dt> <dd class="inline">{{ $k['altes_portal'] }}</dd></div>
            <div><dt class="inline text-[var(--ui-muted)]">Portal:</dt> <dd class="inline">{{ $k['pilot'] }}</dd></div>
        </dl>
        @if ($k['antrag_offen'])
            <p class="mt-2 text-sm text-amber-900">Es liegt ein offener Nummernwechsel-Antrag vor — <a href="{{ $antragLink }}" class="font-semibold underline">HR-Schreibtisch → Mitarbeiter → Konto-Anträge</a>.</p>
        @endif
    @endif

    @if ($gruende !== [])
        <div class="mt-3 flex flex-col gap-1.5">
            @foreach ($gruende as $g)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700">{{ $g['text'] }}</span>
                    @if ($g['aktion'] !== null && $knoepfeAn)
                        <button type="button" class="{{ $knopf }}" wire:click="oeffne('{{ $g['aktion'] }}')" wire:loading.attr="disabled" wire:target="oeffne">{{ $g['knopf'] }}</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($knoepfeAn)
        <div class="mt-3 flex flex-wrap gap-2">
            @if ($k['kann_einladen'])
                <button type="button" class="{{ $knopf }}" wire:click="oeffne('einladung_neu')" wire:loading.attr="disabled" wire:target="oeffne">Einladung neu</button>
            @endif
            @if ($k['kann_zuruecksetzen'])
                <button type="button" class="{{ $knopfRot }}" wire:click="oeffne('konto_zuruecksetzen')" wire:loading.attr="disabled" wire:target="oeffne">Konto zurücksetzen</button>
            @endif
        </div>
        {{-- Pilot-Sperre: statt „Einladung neu“ / „Konto zurücksetzen“ steht der Grund da (HrMitarbeiterListen::kasten). --}}
        @if ($k['einladung_riegel'] !== null)
            <p class="mt-2 text-sm text-amber-900">{{ $k['einladung_riegel'] }}</p>
        @endif
    @endif

    @if ($verlauf !== [])
        <div class="mt-4">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)]">Verlauf</h4>
            <ul class="mt-1 space-y-1 text-sm">
                @foreach ($verlauf as $v)
                    <li>
                        <span class="text-[var(--ui-muted)]">{{ $v['wann'] }} · {{ $v['wer'] }}:</span> {{ $v['was'] }}
                        @if ($v['notiz'] !== null)
                            — {{ $v['notiz'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui-modal size="md" model="modalOffen">
        <x-slot name="header">{{ $titel }}</x-slot>
        <div class="space-y-3 p-4">
            @if ($aktionWarnung !== '')
                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $aktionWarnung }}</div>
            @endif
            @if ($modalFehler !== '')
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $modalFehler }}</div>
            @endif
            <label class="block text-sm font-medium text-[var(--ui-secondary)]">Notiz (Pflicht, 5–500 Zeichen)</label>
            <textarea wire:model="notiz" rows="3" maxlength="500" class="w-full rounded-md border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm"></textarea>
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary-outline" wire:click="schliesse" wire:loading.attr="disabled" wire:target="bestaetige">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="bestaetige" wire:loading.attr="disabled" wire:target="bestaetige">Bestätigen</x-ui-button>
        </x-slot>
    </x-ui-modal>

    {{-- Einmal-Anzeige: Link und Code kommen nur in DIESER Antwort mit (private Felder, nie im Schnappschuss). --}}
    <x-ui-modal size="md" model="einladungOffen" :persistent="true">
        <x-slot name="header">Einladung — nur jetzt sichtbar</x-slot>
        <div class="space-y-3 p-4 text-sm">
            @if ($einladungsCode !== null)
                {{-- Ob die WhatsApp rausging (EinladungSender) — dieselbe Meldung wie im Kasten dahinter. --}}
                @if ($meldung !== '')
                    <div class="rounded-lg border px-3 py-2 {{ $meldungKlasse }}">{{ $meldung }}</div>
                @endif
                <div><span class="text-[var(--ui-muted)]">Link:</span> <code class="break-all">{{ $einladungsLink }}</code></div>
                <div><span class="text-[var(--ui-muted)]">Code:</span> <code class="text-lg font-semibold tracking-widest">{{ $einladungsCode }}</code></div>
                <p class="text-amber-900">Der Code ist ein Geheimnis: nur an den Menschen selbst, nicht in eine Gruppen-Unterhaltung. Er gilt sieben Tage und wird nicht noch einmal angezeigt.</p>
            @else
                <p class="text-[var(--ui-muted)]">Link und Code wurden bereits angezeigt und sind nicht mehr abrufbar. Bei Bedarf eine neue Einladung erstellen.</p>
            @endif
        </div>
        <x-slot name="footer">
            <x-ui-button variant="primary" wire:click="schliesseEinladung">Schließen</x-ui-button>
        </x-slot>
    </x-ui-modal>
</div>
