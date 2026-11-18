{{-- Namensfeld des Eventzugangs (Sammelkonto, mehrere Teamleiter — Markus 18.11.2026): wer hier steht,
     steht im Verlauf jeder Buchung. Erwartet $zeitTeamleiter, $zeitTeamleiterFrei und $zeitTlVorschlaege
     (Liste „Vorname Nachname“). Vertragsfrei bis auf die Aktion zeitTeamleiterFreiUmschalten. --}}
@php
    $ztlFrei = $zeitTeamleiterFrei || $zeitTlVorschlaege === [];
    $ztlFehlt = trim($zeitTeamleiter) === '';
@endphp
<div class="flex flex-wrap items-center gap-2 text-sm">
    <span class="font-medium text-gray-700">Ich buche als:</span>
    @if ($ztlFrei)
        <input type="text" wire:model.blur="zeitTeamleiter" maxlength="80" placeholder="Vor- und Nachname"
               class="w-56 rounded-lg border px-3 py-1.5 text-sm focus:border-blue-500 focus:ring-blue-500 {{ $ztlFehlt ? 'border-amber-400 bg-amber-50' : 'border-gray-300' }}">
        @if ($zeitTlVorschlaege !== [])
            <button type="button" wire:click="zeitTeamleiterFreiUmschalten" class="text-xs text-blue-600 hover:underline">aus der Liste wählen</button>
        @endif
    @else
        <select wire:model.live="zeitTeamleiter"
                class="rounded-lg border px-3 py-1.5 text-sm focus:border-blue-500 focus:ring-blue-500 {{ $ztlFehlt ? 'border-amber-400 bg-amber-50' : 'border-gray-300' }}">
            <option value="">Bitte wählen …</option>
            @foreach ($zeitTlVorschlaege as $ztlName)
                <option value="{{ $ztlName }}">{{ $ztlName }}</option>
            @endforeach
        </select>
        <button type="button" wire:click="zeitTeamleiterFreiUmschalten" class="text-xs text-blue-600 hover:underline">anderer Name</button>
    @endif
    @error('zeitTeamleiter') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
</div>
