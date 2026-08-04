{{--
    Zeilen-Marker: HR-Schreibtisch und uneindeutige Stellen-Zuordnung (Fall 2
    der Zuordnungsregel, Spec §4). Beides sind KEINE Zeilentypen — die Person
    steckt regulaer in ihrer Zeile und wird hier nur zusaetzlich gekennzeichnet.
    Beide Badges sind anklickbar, damit "uneindeutig" mess- und pruefbar bleibt.

    Erwartet: $rows, $token, $prefix
--}}
@php
    $hrDesk = $this->countIn($rows, 'hr_desk_ids');
    $ambiguous = $this->countIn($rows, 'uneindeutig_ids');
@endphp
@if ($hrDesk > 0)
    <button
        type="button"
        wire:click="drill('{{ $token }}', 'hr_desk_ids', 'HR-Schreibtisch')"
        wire:loading.attr="disabled"
        title="{{ $prefix }}: {{ $hrDesk }} auf dem HR-Schreibtisch — Personen anzeigen"
        class="ml-2 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 hover:ring-2 hover:ring-amber-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 transition-all cursor-pointer align-middle"
    >HR {{ $hrDesk }}</button>
@endif
@if ($ambiguous > 0)
    <button
        type="button"
        wire:click="drill('{{ $token }}', 'uneindeutig_ids', 'Stellen-Zuordnung uneindeutig')"
        wire:loading.attr="disabled"
        title="{{ $prefix }}: {{ $ambiguous }} mit uneindeutiger Stellen-Zuordnung (keine Ausschreibung passte zur aktuellen Phase — Fallback auf die kleinste Ausschreibungs-ID) — Personen anzeigen"
        class="ml-2 inline-flex items-center gap-1 rounded-full bg-orange-50 px-2 py-0.5 text-[11px] font-medium text-orange-700 hover:ring-2 hover:ring-orange-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 transition-all cursor-pointer align-middle"
    >uneindeutig {{ $ambiguous }}</button>
@endif
