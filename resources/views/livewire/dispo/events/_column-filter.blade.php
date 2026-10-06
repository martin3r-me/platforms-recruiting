{{-- Spaltenfilter wie in Excel (Kunde 05.10.): Trichter am Spaltenkopf, dahinter
     die Werte DIESER Veranstaltung mit Anzahl, Mehrfachauswahl per Haken.
     Erwartet: $label, $prop (Livewire-Array-Property), $options (value/label/count),
     optional $selected (aktuelle Auswahl) und $align ('left'|'right').

     Oeffnen/Schliessen laeuft in Alpine — ein Serveraufruf nur beim Anhaken. --}}
@php
    $selected = $selected ?? [];
    $align = ($align ?? 'left') === 'right' ? 'right-0' : 'left-0';
    $aktiv = count($selected) > 0;
@endphp
@if (count($options) > 1)
    <span class="relative inline-block" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
        <button type="button" x-on:click="open = !open" x-on:click.outside="open = false"
                class="ml-1 inline-flex items-center rounded px-1 py-0.5 align-middle {{ $aktiv ? 'bg-blue-50 text-blue-700' : 'text-gray-400 hover:text-gray-700' }}"
                title="{{ $aktiv ? count($selected) . ' von ' . count($options) . ' gewählt' : $label . ' filtern' }}">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 5h18l-7 8v6l-4 2v-8z"/></svg>
            @if ($aktiv)
                <span class="ml-0.5 text-[10px] font-bold tabular-nums">{{ count($selected) }}</span>
            @endif
        </button>
        <div x-show="open" x-cloak
             class="absolute {{ $align }} z-30 mt-1 w-56 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
            <div class="max-h-64 overflow-y-auto px-1">
                @foreach ($options as $opt)
                    <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-xs font-normal normal-case tracking-normal text-gray-700 hover:bg-gray-50">
                        <input type="checkbox" wire:model.live="{{ $prop }}" value="{{ $opt['value'] }}" class="rounded border-gray-300">
                        <span class="min-w-0 flex-1 truncate">{{ $opt['label'] }}</span>
                        <span class="shrink-0 tabular-nums text-gray-400">{{ $opt['count'] }}</span>
                    </label>
                @endforeach
            </div>
            @if ($aktiv)
                <div class="mt-1 border-t border-gray-100 px-2 pt-1">
                    <button type="button" wire:click="$set('{{ $prop }}', [])"
                            class="w-full rounded px-1 py-1 text-left text-xs font-medium text-blue-600 hover:bg-blue-50">Auswahl aufheben</button>
                </div>
            @endif
        </div>
    </span>
@endif
