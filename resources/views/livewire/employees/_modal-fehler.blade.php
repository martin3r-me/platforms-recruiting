{{-- Fehlerzeile IM Fenster: die Meldung oben auf der Seite liegt hinter dem Overlay (Review Task 7). Erwartet $fehler (?string). --}}
@if($fehler)
    <div class="p-2 bg-red-50 border border-red-200 rounded text-xs text-red-800">
        {{ $fehler }}
    </div>
@endif
