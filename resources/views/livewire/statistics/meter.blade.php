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
      $max         ?int    Kapazitaet (null ODER 0 = unbegrenzt → nur Zahl, kein Balken)
      $title       string  Tooltip (Einheit + Zaehlregel)
      $borderLeft  bool    Trennlinie zur vorigen Spaltengruppe
      $pad         string  Zellen-Padding (Summenzeilen sind hoeher)
--}}
@php
    // EINE Lesart fuer die 0: „unbegrenzt“, genau wie null.
    //
    // Warum das hier stehen muss: max_participants ist per Validierung `min:0`, eine
    // gepflegte 0 ist also erreichbar (gemessen). Der Balken hat sie schon immer wie
    // unbegrenzt behandelt (der Nenner-Guard ist falsy bei 0), und
    // CohortViewModel::interviewTotals zaehlt sie ausdruecklich als „ohne
    // Platzbegrenzung“ — nur die ZAHL zeigte „1 / 0“ und behauptete damit eine
    // Ueberbuchung, die niemand nachrechnen kann. Zwei Lesarten fuer denselben Wert,
    // eine davon in derselben Zelle wie der Balken, der es anders sieht.
    $unbegrenzt = $max === null || (int) $max <= 0;
    $maxLabel = $unbegrenzt ? '∞' : $max;
    $pct = (!$unbegrenzt && $taken !== null) ? (int) round($taken / $max * 100) : null;
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
                <span class="text-[color:var(--ui-muted)]">/&nbsp;{{ $maxLabel }}</span>
                @if ($over)
                    <span class="font-semibold text-red-700">{{ $pct }}&nbsp;%</span>
                @endif
            </div>
            @if (!$unbegrenzt)
                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full {{ $over ? 'bg-red-100' : 'bg-sky-100' }}">
                    <div class="h-full rounded-full {{ $over ? 'bg-red-500' : 'bg-sky-500' }}" style="width: {{ $barPct }}%"></div>
                </div>
            @endif
        </div>
    @endif
</td>
