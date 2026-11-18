{{-- Stempel-Anzeige einer Einbuchung (Tabelle Desktop + Karte mobil nutzen denselben Aufbau).
     Erwartet $assignment, $zelle (DispoZeiten::zelle) und $eventOnly. Vertragsfrei: keine $this-Aufrufe. --}}
@php
    $zelleFarbe = match ($zelle['zustand']) {
        'eingecheckt' => 'text-green-700',
        'ausgecheckt', 'erledigt' => 'text-gray-800',
        default => 'text-gray-400',
    };
@endphp
@if ($assignment->rec_employee_id)
    <button type="button" wire:click="openZeit({{ $assignment->id }})" class="text-left text-xs {{ $zelleFarbe }} hover:underline" title="Zeit ansehen und korrigieren">
        @if ($zelle['gesperrt'])<span title="Erledigt und gesperrt">🔒</span> @endif{{ $zelle['text'] }}@if ($zelle['verspaetung'] > 0) <span class="ml-0.5 rounded bg-amber-100 px-1 text-[11px] font-medium text-amber-800" title="Verspätung gegenüber dem Schichtbeginn">+{{ $zelle['verspaetung'] }}</span>@endif
    </button>
@endif
