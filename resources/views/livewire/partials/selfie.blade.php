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
                 Browser ein kaputtes Bild-Icon ohne erkennbaren Grund.
                 Ersetzt wird der UMSCHLIESSENDE Anchor, nicht nur das <img>:
                 feuert onerror, ist die URL abgelaufen — und das href traegt
                 dieselbe abgelaufene URL, der Klick fuehrte also garantiert ins
                 Leere. Im Fallback-Zustand darf nicht geklickt werden koennen. --}}
            onerror="this.onerror=null;var p=document.createElement('span');p.className='inline-flex items-center justify-center w-9 h-9 rounded-full border border-[var(--ui-border)] text-[10px] text-[var(--ui-muted)]';p.textContent='—';p.title='Foto-Link abgelaufen — Seite neu laden';(this.closest('a')||this).replaceWith(p);"
        >
    </a>
@else
    <span class="inline-flex items-center justify-center w-9 h-9 rounded-full border border-[var(--ui-border)] text-[10px] text-[var(--ui-muted)]" title="Kein Foto vorhanden">—</span>
@endif
