{{--
    Stempel-Kachel im Portal (Spec 2026-11-16 §3). Bekommt ALLES als Variable
    (stempelZeilen, stempelMeldung, duzen) — kein $this-> hier, damit der Vertrag
    mit PortalShell nur die Aktionen stempelKommen/stempelGehen betrifft.
    Uhrzeiten kommen fertig als Ortszeit aus PortalStempeln.
    Die Meldung steht auch ohne Zeilen da (Fenster zwischen Seite und Klick abgelaufen).
--}}
@if (!empty($stempelZeilen) || !empty($stempelMeldung))
    @php
        $alleHeute = !empty($stempelZeilen) && count(array_filter($stempelZeilen, static fn ($z) => !$z['heute'])) === 0;
        $stempelTitel = ($duzen ? 'Dein Einsatz' : 'Ihr Einsatz') . ($alleHeute ? ' heute' : '');
        $stempelHinweis = $duzen
            ? 'Stimmt etwas nicht? Sag deiner Teamleitung oder der Disposition Bescheid.'
            : 'Stimmt etwas nicht? Sagen Sie Ihrer Teamleitung oder der Disposition Bescheid.';
        $stempelVorbei = $duzen
            ? 'Zeitfenster vorbei – bitte melde dich bei der Disposition.'
            : 'Zeitfenster vorbei – bitte melden Sie sich bei der Disposition.';
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
            @elseif ($zeile['fenster_vorbei'])
                <p class="klein">{{ $stempelVorbei }}</p>
            @endif
        </div>
    @endforeach
    @if (!empty($stempelMeldung))
        @if (empty($stempelZeilen))
            <div class="card kachel" style="margin-top:11px"><p>{{ $stempelMeldung }}</p></div>
        @else
            <div class="s" style="margin-top:8px">{{ $stempelMeldung }}</div>
        @endif
    @endif
@endif
