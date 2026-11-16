{{--
    Stempel-Kachel im Portal (Spec 2026-11-16 §3). Bekommt ALLES als Variable
    (stempelZeilen, stempelMeldung, duzen) — kein $this-> hier, damit der Vertrag
    mit PortalShell nur die Aktionen stempelKommen/stempelGehen betrifft.
    Uhrzeiten kommen fertig als Ortszeit aus PortalStempeln.
--}}
@if (!empty($stempelZeilen))
    @php
        $stempelTitel = $duzen ? 'Dein Einsatz heute' : 'Ihr Einsatz heute';
        $stempelHinweis = $duzen
            ? 'Stimmt etwas nicht? Sag deiner Teamleitung oder der Disposition Bescheid.'
            : 'Stimmt etwas nicht? Sagen Sie Ihrer Teamleitung oder der Disposition Bescheid.';
    @endphp
    <div class="sec-label">{{ $stempelTitel }}</div>
    @foreach ($stempelZeilen as $zeile)
        @php
            $zeileId = (int) $zeile['assignment_id'];
            $zeileSoll = $zeile['soll'] !== '' ? ' · ' . $zeile['soll'] : '';
            $zeileZusammen = '';
            if ($zeile['zustand'] === 'ausgecheckt') {
                $zeileZusammen = $zeile['kommen'] . ' – ' . $zeile['gehen']
                    . ', Pause ' . (int) $zeile['pause'] . ' Min, Arbeitszeit ' . $zeile['arbeitszeit'] . ' Std';
            }
        @endphp
        <div class="card kachel" style="margin-top:11px">
            <div class="t">{{ $zeile['name'] }}</div>
            <p class="klein">{{ $zeile['tag'] }}{{ $zeileSoll }}</p>
            @if ($zeile['zustand'] === 'eingecheckt')
                <p>Gekommen um {{ $zeile['kommen'] }}</p>
            @elseif ($zeileZusammen !== '')
                <p>{{ $zeileZusammen }}</p>
                <p class="klein">{{ $stempelHinweis }}</p>
            @endif
            @if ($zeile['kann_kommen'])
                <button type="button" class="btn primary" wire:click="stempelKommen({{ $zeileId }})" wire:loading.attr="disabled">Kommen</button>
            @endif
            @if ($zeile['kann_gehen'])
                <button type="button" class="btn primary" wire:click="stempelGehen({{ $zeileId }})" wire:loading.attr="disabled">Gehen</button>
            @endif
        </div>
    @endforeach
    @if (!empty($stempelMeldung))
        <div class="s" style="margin-top:8px">{{ $stempelMeldung }}</div>
    @endif
@endif
