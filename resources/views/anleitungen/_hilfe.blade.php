{{-- „?“ zur passenden Anleitung. Erwartet $route (Routenname der Seite, fest uebergeben). Oeffnet in neuem Tab, damit die Arbeit offen bleibt. --}}
@php
    $hilfeAnleitungen = \Platform\Recruiting\Support\Anleitungen\AnleitungKatalog::standard()->fuerRoute($route);
@endphp
@if (count($hilfeAnleitungen) === 1)
    <a href="{{ route('recruiting.anleitungen.show', ['slug' => $hilfeAnleitungen[0]->slug]) }}" target="_blank" rel="noopener"
       class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-sm font-semibold text-gray-500 hover:bg-gray-50 hover:text-blue-600"
       title="Anleitung: {{ $hilfeAnleitungen[0]->titel }}">?</a>
@elseif (count($hilfeAnleitungen) > 1)
    <div x-data="{ offen: false }" class="relative inline-block" @click.outside="offen = false">
        <button type="button" @click="offen = !offen"
                class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-gray-300 bg-white text-sm font-semibold text-gray-500 hover:bg-gray-50 hover:text-blue-600"
                title="Anleitungen zu dieser Seite">?</button>
        <div x-show="offen" x-cloak class="absolute right-0 z-20 mt-1 w-64 rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg">
            @foreach ($hilfeAnleitungen as $hilfeAnleitung)
                <a href="{{ route('recruiting.anleitungen.show', ['slug' => $hilfeAnleitung->slug]) }}" target="_blank" rel="noopener"
                   class="block px-3 py-2 text-gray-700 hover:bg-gray-50">{{ $hilfeAnleitung->titel }}</a>
            @endforeach
        </div>
    </div>
@endif
