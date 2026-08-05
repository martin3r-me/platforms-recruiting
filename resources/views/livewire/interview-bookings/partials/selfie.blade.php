@php
    $selfie = $applicantId ? ($this->selfies[$applicantId] ?? null) : null;
@endphp

@if($selfie)
    <a href="{{ $selfie['url'] }}" target="_blank" rel="noopener" title="Foto vergrößern">
        <img
            src="{{ $selfie['url'] }}"
            alt="Foto"
            class="w-9 h-9 rounded-full object-cover border border-[var(--ui-border)]"
            {{-- Signierte URLs laufen nach 60 Min ab; ohne Fallback zeigt der
                 Browser ein kaputtes Bild-Icon ohne erkennbaren Grund. --}}
            onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'inline-flex items-center justify-center w-9 h-9 rounded-full border border-[var(--ui-border)] text-[10px] text-[var(--ui-muted)]',textContent:'—'}));"
        >
    </a>
@else
    <span class="inline-flex items-center justify-center w-9 h-9 rounded-full border border-[var(--ui-border)] text-[10px] text-[var(--ui-muted)]" title="Kein Foto vorhanden">—</span>
@endif
