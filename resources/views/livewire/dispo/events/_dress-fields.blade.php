{{-- Waeschepakete: Auswahl je Taetigkeit, daneben der Text, der dadurch
     fuer die Empfaenger verschwindet. Schlichte Selects mit wire:model —
     x-ui-input-select + @entangle verliert die Auswahl beim Speichern. --}}
@php
    $dressOptions = $this->dressPackages;
    // Fix-Runde 2: NICHT $this->eventTaetigkeiten (frisch berechnet, kann
    // sich zwischen Oeffnen und Senden verschieben) — der Schnappschuss vom
    // Oeffnen ist die einzige Liste, gegen die dressByTaetigkeit-Indizes
    // noch gueltig sind. Das Fenster zeigt damit bewusst den Stand von
    // seinem Oeffnen, nicht live nachgezogene ZAS-Aenderungen.
    $dressTaetigkeiten = $dressTaetigkeitenSnapshot;
    $zasText = trim((string) ($this->event->dresscode ?? ''));
@endphp
<div class="rounded-lg border border-gray-200 p-3 text-sm space-y-3">
    <div class="font-medium text-gray-700">Kleidung</div>

    {{-- Umzug 10/2026 (Auftrag 3): ein leerer Paket-Katalog raeumt nur noch
         die Paket-Auswahlfelder weg — ZAS-Kasten, "Infos fuer alle" und der
         Speichern-Knopf bleiben unabhaengig davon bedienbar. --}}
    @if (count($dressOptions) === 0)
        <p class="text-xs text-gray-500">Noch keine Wäschepakete angelegt (Disposition → Wäschepakete).</p>
    @else
        {{-- Vorschau: unter jeder Auswahl steht der Text, den der Mitarbeiter
             lesen wird. wire:model.live, damit sie der Auswahl sofort folgt. --}}
        @php $dressTexts = $this->dressTexts; @endphp

        <label class="block">
            <span class="mb-1 block text-xs text-gray-600">Alle übrigen</span>
            <select wire:model.live="dressAll" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">— kein Paket —</option>
                @foreach ($dressOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            @php $vorschauAll = $dressTexts[$dressAll] ?? null; @endphp
            @if ($vorschauAll)
                <span class="mt-1 block text-xs text-gray-500">Mitarbeiter liest: {{ $vorschauAll }}</span>
            @endif
        </label>

        {{-- Bindung ueber den numerischen Index, nicht ueber den Taetigkeit-Text:
             Livewire zerlegt wire:model-Pfade am literalen Punkt, und Taetigkeit
             ist ungefilterter ZAS-Freitext ("2.OG" wuerde sonst einen
             verschachtelten Pfad erzeugen). Index i gehoert zu
             dressTaetigkeitenSnapshot[i] — siehe Show::$dressByTaetigkeit. --}}
        @foreach ($dressTaetigkeiten as $index => $taetigkeit)
            @php $vorschauTag = $dressTexts[$dressByTaetigkeit[$index] ?? ''] ?? null; @endphp
            <label class="block">
                <span class="mb-1 block text-xs text-gray-600">{{ $taetigkeit }}</span>
                <select wire:model.live="dressByTaetigkeit.{{ $index }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="">— wie alle übrigen —</option>
                    @foreach ($dressOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                @if ($vorschauTag)
                    <span class="mt-1 block text-xs text-gray-500">Mitarbeiter liest: {{ $vorschauTag }}</span>
                @endif
            </label>
        @endforeach
    @endif

    @if ($zasText !== '')
        <div class="rounded bg-amber-50 p-2">
            <div class="text-xs font-medium text-amber-800">Bisheriger Text aus ZAS — verschwindet für alle mit Paket</div>
            <div class="mt-1 whitespace-pre-line text-xs text-amber-900">{{ $zasText }}</div>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-amber-900">
                    <input type="checkbox" wire:model.live="dressAck" class="rounded border-gray-300">
                    Gesehen — Wichtiges habe ich in den Hinweis übernommen
                </label>
                <button type="button" wire:click="copyZasToHinweis" class="rounded border border-amber-300 px-2 py-1 text-xs text-amber-900 hover:bg-amber-100">
                    Text in den Hinweis übernehmen
                </button>
            </div>
            @error('dressAck') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    @endif

    <label class="block">
        <span class="mb-1 block text-xs text-gray-600">Infos für alle <span class="text-gray-400">(steht auf der Einsatz-Seite unter der Kleidung)</span></span>
        <textarea wire:model="eventHinweis" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
    </label>

    {{-- Eigener Speichern-Knopf (Fix-Runde 3, Befund 1): der Senden-Knopf
         ist deaktiviert, sobald die VA durchbestaetigt ist — ohne diesen
         Weg waere die Auswahl bei jeder Nachbesserung unerreichbar und
         ginge beim Schliessen des Fensters lautlos verloren. Muster:
         „Nur Eskalation speichern". --}}
    <div class="flex items-center justify-end gap-2">
        @if ($dressSaved)
            <span class="text-xs text-green-600">✓ Kleidung gespeichert</span>
        @endif
        <button type="button" wire:click="saveDress"
                wire:loading.attr="disabled" wire:target="saveDress"
                class="rounded border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">Nur Kleidung speichern</button>
    </div>
</div>
