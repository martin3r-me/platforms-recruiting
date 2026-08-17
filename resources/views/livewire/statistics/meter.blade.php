{{--
    Belegung als Mini-Balken (dataviz: „Meter“). Die Fuellung traegt die
    Severity, die Spur ist ein hellerer Schritt derselben Rampe — damit liest
    man den Zustand ueber die ganze Breite, nicht nur an der Fuellkante.

    Zahl UND Balken: der Balken ist ohne Lesen erfassbar, die Zahl bleibt die
    pruefbare Wahrheit.

    Ueberbuchung ist ein Befund und wird nicht geklammert (Spec §4): der Balken
    laeuft optisch bei 100 % an, wechselt aber auf die Warnfarbe, und die Zahl
    daneben zeigt den echten Prozentwert.

    Erwartet:
      $taken       ?int    belegte Plaetze (null = nicht anwendbar → „–“)
      $max         ?int    Kapazitaet (null = unbegrenzt → nur Zahl, kein Balken)
      $title       string  Tooltip (Einheit + Zaehlregel)
      $borderLeft  bool    Trennlinie zur vorigen Spaltengruppe
      $pad         string  Zellen-Padding (Summenzeilen sind hoeher)
--}}
@php
    $pct = ($max && $taken !== null) ? (int) round($taken / $max * 100) : null;
    $barPct = $pct === null ? 0 : min(100, max(0, $pct));
    $over = $pct !== null && $pct > 100;
    $meterBorder = ($borderLeft ?? false) ? 'border-l border-[var(--ui-border)]/60' : '';
@endphp
<td class="{{ $pad ?? 'px-3 py-2' }} align-middle {{ $meterBorder }}" title="{{ $title }}{{ $pct !== null ? ' — aktuell ' . $pct . ' % belegt' : '' }}">
    @if ($taken === null)
        <div class="text-center text-xs text-[color:var(--ui-muted)]">–</div>
    @else
        <div class="mx-auto w-20">
            <div class="flex items-baseline justify-center gap-1 text-xs tabular-nums">
                <span class="font-medium text-[color:var(--ui-secondary)]">{{ $taken }}</span>
                <span class="text-[color:var(--ui-muted)]">/&nbsp;{{ $max ?? '∞' }}</span>
                @if ($over)
                    <span class="font-semibold text-red-700">{{ $pct }}&nbsp;%</span>
                @endif
            </div>
            @if ($max)
                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full {{ $over ? 'bg-red-100' : 'bg-sky-100' }}">
                    <div class="h-full rounded-full {{ $over ? 'bg-red-500' : 'bg-sky-500' }}" style="width: {{ $barPct }}%"></div>
                </div>
            @endif
        </div>
    @endif
</td>
