{{--
    Einsatz-Quote eines Termins: „im Einsatz / teilgenommen“ als EINE Zelle —
    die drei Toepfe (im Einsatz / ohne Einsatz / nicht pruefbar) stehen nicht
    mehr als Spalten in der Tabelle, sondern hinter dem Klick (Schulungs-
    Detailansicht). Kundenwunsch 09.09.2026: Tiefe per Klick statt per Spalte.

    Farbe an der Quote der PRUEFBAREN (im Einsatz gegen im+ohne): „nicht
    pruefbar“ fehlt im Nenner, weil dort mangels ZAS-Personalnummer keine
    Aussage moeglich ist — eine frische Schulung waere sonst automatisch rot.

    Erwartet:
      $rows         list<array>  Assigner-Zeilen des Termins
      $interviewId  ?int         oeffnet die Detailansicht (null = kein Klick)
      $drillToken   ?string      alternativ: Drill auf 'im_einsatz' (Gesamt-Zeile)
      $isTotal      ?bool        kraeftigere Darstellung der Summen-Zeile
--}}
@php
    $teilgenommen = $this->countIn($rows, 'teilgenommen');
    $imEinsatz = $this->countIn($rows, 'im_einsatz');
    $ohneEinsatz = $this->countIn($rows, 'ohne_einsatz');
    $unpruefbar = $this->countIn($rows, 'einsatz_unpruefbar');

    $pruefbar = $imEinsatz + $ohneEinsatz;
    $farbe = 'text-[color:var(--ui-muted)]';
    if ($pruefbar > 0) {
        $quote = $imEinsatz / $pruefbar;
        $farbe = $quote >= 0.7 ? 'text-emerald-700' : ($quote >= 0.4 ? 'text-amber-700' : 'text-red-700');
    }

    $quoteTitle = $teilgenommen === 0
        ? 'Noch niemand hat teilgenommen — der Dispo-Abgleich beginnt mit der Schulung.'
        : $imEinsatz . ' von ' . $teilgenommen . ' Teilgenommenen im Einsatz'
            . ' (' . $ohneEinsatz . ' ohne Einsatz, ' . $unpruefbar . ' nicht prüfbar — ohne Mitarbeiter oder ZAS-Personalnummer keine Aussage).'
            . ($interviewId !== null ? ' Klick: Detailansicht mit Schulungsleiter und Personenliste.' : '');
@endphp
<td class="px-3 py-2 text-center whitespace-nowrap tabular-nums border-l border-[var(--ui-border)]/60" title="{{ $quoteTitle }}">
    @if ($teilgenommen === 0)
        <span class="text-xs text-[color:var(--ui-muted)]">–</span>
    @elseif ($interviewId !== null)
        <button type="button" wire:click="openTerminDetail({{ $interviewId }})" wire:loading.attr="disabled"
                class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $farbe }} bg-[var(--ui-muted-5)] ring-1 ring-[var(--ui-border)]/60 hover:ring-2 hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer">
            {{ $imEinsatz }}&nbsp;/&nbsp;{{ $teilgenommen }}
        </button>
    @elseif (($drillToken ?? null) !== null)
        <button type="button" wire:click="drill(@js($drillToken), @js('im_einsatz'), @js('Im Einsatz'))" wire:loading.attr="disabled"
                class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs {{ ($isTotal ?? false) ? 'font-bold' : 'font-semibold' }} {{ $farbe }} bg-[var(--ui-muted-5)] ring-1 ring-[var(--ui-border)]/60 hover:ring-2 hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer">
            {{ $imEinsatz }}&nbsp;/&nbsp;{{ $teilgenommen }}
        </button>
    @else
        <span class="text-xs font-medium {{ $farbe }}">{{ $imEinsatz }}&nbsp;/&nbsp;{{ $teilgenommen }}</span>
    @endif
</td>
