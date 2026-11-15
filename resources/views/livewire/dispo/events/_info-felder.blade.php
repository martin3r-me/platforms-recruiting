{{-- Felder von „Info an Crew“: wer, Datei, Hinweis. Steht im Fenster „Info an
     Crew“ UND aufgeklappt im Fenster „Bestätigungen senden“ (Sheran 15.11.) —
     beide teilen sich die info*-Felder der Komponente, offen ist immer nur eines. --}}
@php
    $infoPrev = $this->infoPreview;
    $infoAngehakt = collect($infoPrev['persons'])->where('selected', true)->count();
    $withNote = collect($infoPrev['persons'])->where('selected', true)->where('has_note', true)->count();
@endphp
<label class="block text-sm">
    <span class="mb-1 block font-medium text-gray-700">Wer?</span>
    <select wire:model.live="infoFilter" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
        <option value="">Alle disponierten Mitarbeiter</option>
        {{-- Kunde 30.09.: "Hinweis an die bestaetigten Mitarbeiter" war
             bisher nicht waehlbar. Bestaetigt = ALLE kommenden Tage zugesagt. --}}
        <option value="confirmed">Nur bestätigte</option>
        <option value="open">Nur noch offene</option>
        @if ($this->infoTaetigkeitOptions !== [])
            <optgroup label="Tätigkeit (aus dieser VA)">
                @foreach ($this->infoTaetigkeitOptions as $taetigkeit)
                    <option value="t:{{ $taetigkeit }}">{{ $taetigkeit }}</option>
                @endforeach
            </optgroup>
        @endif
        @if ($this->infoQualOptions !== [])
            <optgroup label="Qualifikation (aus der MA-Akte)">
                @foreach ($this->infoQualOptions as $qualValue => $qualLabel)
                    <option value="q:{{ $qualValue }}">{{ $qualLabel }}</option>
                @endforeach
            </optgroup>
        @endif
    </select>
    <span class="mt-1 block text-xs text-gray-500">Nur kommende Einsatztage; mehrere Personalnummern derselben Person zählen einmal. Einzelne unten abwählbar.</span>
</label>

@if ($infoPrev['persons'] !== [])
    {{-- Sheran 15.11.: ein Ticket an EINE Person — „Keine“, dann die eine anhaken. --}}
    <div class="flex items-center justify-between text-xs text-gray-500">
        <span>{{ $infoAngehakt }} von {{ count($infoPrev['persons']) }} angehakt</span>
        <span class="flex gap-3">
            <button type="button" wire:click="infoAlle" class="text-blue-600 hover:underline">Alle</button>
            <button type="button" wire:click="infoKeine" class="text-blue-600 hover:underline">Keine</button>
        </span>
    </div>
    <div class="max-h-44 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2">
        @foreach ($infoPrev['persons'] as $person)
            <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-gray-50 {{ $person['selected'] ? '' : 'opacity-50' }}">
                <input type="checkbox" wire:click="toggleInfoPerson({{ $person['canonical'] }})"
                       @if ($person['selected']) checked @endif class="rounded border-gray-300">
                <span class="truncate">{{ $person['name'] }}</span>
                @if ($person['phone'] === null)
                    <span class="shrink-0 rounded bg-amber-50 px-1.5 py-0.5 text-[10.5px] text-amber-700">kein Telefon</span>
                @endif
                @if ($person['has_note'])
                    <span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] text-gray-500" title="Hat bereits einen Hinweis — neuer Text wird ergänzt">✎ Hinweis vorhanden</span>
                @endif
            </label>
        @endforeach
    </div>
@endif

<label class="block text-sm">
    <span class="mb-1 block font-medium text-gray-700">Datei anhängen <span class="text-gray-400">(optional, für alle identisch)</span></span>
    <div x-data="{ drag: false }"
         x-on:dragover.prevent="drag = true" x-on:dragleave.prevent="drag = false"
         x-on:drop.prevent="drag = false; $refs.infoIn.files = $event.dataTransfer.files; $refs.infoIn.dispatchEvent(new Event('change'))"
         :class="drag ? 'border-blue-400 bg-blue-50/60' : 'border-gray-300'"
         class="rounded-lg border-2 border-dashed p-3">
        <input type="file" multiple x-ref="infoIn" wire:model="infoUploads" accept=".pdf,.jpg,.jpeg,.png"
               class="w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100">
        <div class="mt-1 text-xs text-gray-400">… oder Dateien hierher ziehen — mehrere auf einmal möglich (Funktion, Einteilung, Tischplan in EINER Nachricht).</div>
    </div>
    <span class="mt-1 block text-xs text-gray-500">PDF/JPG/PNG, je max. 10 MB. Wird zusätzlich zu bestehenden Anhängen abgelegt.</span>
    @if ($infoUploads !== [])
        <span class="mt-1 block text-xs text-gray-500">{{ count($infoUploads) }} Datei(en) ausgewählt</span>
    @endif
    <div wire:loading wire:target="infoUploads" class="mt-1 text-xs text-blue-600">Wird hochgeladen …</div>
</label>

<label class="block text-sm">
    <span class="mb-1 block font-medium text-gray-700">Hinweis <span class="text-gray-400">(optional, für alle identisch)</span></span>
    <textarea wire:model="infoNote" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="z. B. Treffpunkt geändert: Eingang Nord, Tor 3"></textarea>
    <span class="mt-1 block text-xs text-gray-500">Links, z. B. zu einem Video, sind auf der Einsatz-Seite anklickbar.</span>
    @if ($withNote > 0)
        <span class="mt-1 block text-xs text-gray-500">{{ $withNote }} der ausgewählten Personen {{ $withNote === 1 ? 'hat' : 'haben' }} bereits einen Hinweis — der neue Text wird darunter ergänzt.</span>
    @endif
</label>
@error('infoNote') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
@error('infoUploads') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
@error('infoUploads.*') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
