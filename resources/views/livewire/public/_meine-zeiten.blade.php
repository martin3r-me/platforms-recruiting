{{--
    „Meine Zeiten“ im Portal (Spec 2026-11-16 §3). Bekommt ALLES als Variable
    (meineZeiten, duzen) — kein $this-> hier. Uhrzeiten kommen fertig als
    Ortszeit aus PortalStempeln::meineZeiten(). Ohne Zeilen keine Ausgabe.
--}}
@if (!empty($meineZeiten))
    <div class="sec-label">Meine Zeiten</div>
    @foreach ($meineZeiten as $zeit)
        @php
            $zeitTitel = $zeit['name'] !== null ? $zeit['tag'] . ' · ' . $zeit['name'] : $zeit['tag'];
            $zeitSpanne = $zeit['kommen'] . ' – ' . ($zeit['gehen'] ?? '…');
            $zeitDetail = '';
            if ($zeit['gehen'] !== null) {
                $zeitDetail = 'Pause ' . (int) $zeit['pause'] . ' Min, Arbeitszeit ' . $zeit['arbeitszeit'] . ' Std';
            }
        @endphp
        <div class="card kachel" style="margin-top:11px">
            <div class="t">{{ $zeitTitel }}</div>
            <p>{{ $zeitSpanne }} · {{ $zeit['zustand'] }}</p>
            @if ($zeitDetail !== '')
                <p class="klein">{{ $zeitDetail }}</p>
            @endif
            @if ($zeit['vermerk'] !== null)
                <p class="klein">{{ $zeit['vermerk'] }}</p>
            @endif
        </div>
    @endforeach
@endif
