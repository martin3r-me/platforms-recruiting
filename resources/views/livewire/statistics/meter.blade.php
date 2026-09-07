{{--
    Belegung als Mini-Balken (dataviz: „Meter“). Die Fuellung traegt die
    Severity, die Spur ist ein hellerer Schritt derselben Rampe — damit liest
    man den Zustand ueber die ganze Breite, nicht nur an der Fuellkante.

    Zahl UND Balken: der Balken ist ohne Lesen erfassbar, die Zahl bleibt die
    pruefbare Wahrheit.

    ROT ist die UNTERBELEGUNG (Claras Liste, Default 07.09.: unter der
    Mindestteilnehmerzahl des Termins — nur wenn min_participants gepflegt ist,
    keine Prozent-Heuristik). Ueberbuchung ist weiterhin ein Befund und wird
    nicht geklammert, aber nur noch amber: „eine Ueberbuchung ist sowieso nur
    manuell von uns moeglich“ (Clara).

    Erwartet:
      $taken       ?int    belegte Plaetze (null = nicht anwendbar → „–“)
      $max         ?int    Kapazitaet (null ODER 0 = unbegrenzt → nur Zahl, kein Balken)
      $min         ?int    Mindestteilnehmer (null/0 = keine Untergrenze)
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
    // Unterbelegung auch bei unbegrenzter Kapazitaet moeglich — die Untergrenze
    // haengt an min_participants, nicht an max.
    $minWert = (int) ($min ?? 0);
    $unter = $minWert > 0 && $taken !== null && $taken < $minWert;
    $meterBorder = ($borderLeft ?? false) ? 'border-l border-[var(--ui-border)]/60' : '';
    $titleZusatz = ($pct !== null ? ' — aktuell ' . $pct . ' % belegt' : '')
        . ($unter ? ' — UNTER der Mindestteilnehmerzahl (' . $minWert . ')' : '');
@endphp
<td class="{{ $pad ?? 'px-3 py-2' }} align-middle {{ $meterBorder }}" title="{{ $title }}{{ $titleZusatz }}">
    @if ($taken === null)
        <div class="text-center text-xs text-[color:var(--ui-muted)]">–</div>
    @else
        <div class="mx-auto w-20">
            <div class="flex items-baseline justify-center gap-1 text-xs tabular-nums">
                <span class="font-medium {{ $unter ? 'text-red-700 font-semibold' : 'text-[color:var(--ui-secondary)]' }}">{{ $taken }}</span>
                <span class="text-[color:var(--ui-muted)]">/&nbsp;{{ $maxLabel }}</span>
                @if ($over)
                    <span class="font-semibold text-amber-700">{{ $pct }}&nbsp;%</span>
                @endif
            </div>
            @if (!$unbegrenzt)
                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full {{ $unter ? 'bg-red-100' : ($over ? 'bg-amber-100' : 'bg-sky-100') }}">
                    <div class="h-full rounded-full {{ $unter ? 'bg-red-500' : ($over ? 'bg-amber-500' : 'bg-sky-500') }}" style="width: {{ $barPct }}%"></div>
                </div>
            @elseif ($unter)
                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-red-100">
                    <div class="h-full rounded-full bg-red-500" style="width: {{ $minWert > 0 ? min(100, (int) round($taken / $minWert * 100)) : 0 }}%"></div>
                </div>
            @endif
        </div>
    @endif
</td>
