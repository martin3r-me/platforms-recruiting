{{--
    Zeilen-Marker: HR-Schreibtisch und uneindeutige Stellen-Zuordnung (Fall 2
    der Zuordnungsregel, Spec §4). Beides sind KEINE Zeilentypen — die Person
    steckt regulaer in ihrer Zeile und wird hier nur zusaetzlich gekennzeichnet.
    Beide Badges sind anklickbar, damit "uneindeutig" mess- und pruefbar bleibt.

    Bewusst neutral-grau statt Warnfarbe: es sind Pruef-Hinweise fuer uns, keine
    Handlungsaufforderung an den Kunden. In Rot/Orange zogen sie den Blick vor
    den Kennzahlen an und lasen sich wie ein Alarm ohne Handlungsoption. Das ⓘ
    signalisiert die Erklaerung, der Tooltip liefert sie.

    Erwartet: $rows, $token, $prefix
--}}
@php
    $hrDesk = $this->countIn($rows, 'hr_desk_ids');
    $ambiguous = $this->countIn($rows, 'uneindeutig_ids');
    $markerClass = 'ml-2 inline-flex items-center gap-1 rounded-full bg-[var(--ui-muted-5)] px-2 py-0.5 text-[11px] font-medium text-[color:var(--ui-muted)] ring-1 ring-[var(--ui-border)]/60 hover:text-[color:var(--ui-secondary)] hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer align-middle tabular-nums';
@endphp
{{-- @js statt nackter Anfuehrungszeichen — wie in cells.blade.php und aus
     demselben Grund: heute steht im Token base64 (immer [A-Za-z0-9+/=]) und in
     den beiden anderen Argumenten eine Konstante, die Ausnahme von der Regel ist
     aber genau die Stelle, an der beim naechsten Umbau Nutzertext hineinrutscht.
     Ein Apostroph zerlegte dann den Ausdruck, und der Marker waere in ALLEN
     Zeilen tot (in Task 8 gemessen, an derselben Fehlerklasse). --}}
@if ($hrDesk > 0)
    <button
        type="button"
        wire:click="drill(@js($token), @js('hr_desk_ids'), @js('HR-Schreibtisch'))"
        wire:loading.attr="disabled"
        title="{{ $prefix }}: {{ $hrDesk }} auf dem HR-Schreibtisch — Personen anzeigen"
        class="{{ $markerClass }}"
    >HR-Schreibtisch {{ $hrDesk }} ⓘ</button>
@endif
@if ($ambiguous > 0)
    <button
        type="button"
        wire:click="drill(@js($token), @js('uneindeutig_ids'), @js('Stellen-Zuordnung uneindeutig'))"
        wire:loading.attr="disabled"
        title="{{ $prefix }}: {{ $ambiguous }} mit uneindeutiger Stellen-Zuordnung (keine Ausschreibung passte zur aktuellen Phase — Fallback auf die kleinste Ausschreibungs-ID) — Personen anzeigen"
        class="{{ $markerClass }}"
    >Zuordnung unklar {{ $ambiguous }} ⓘ</button>
@endif
