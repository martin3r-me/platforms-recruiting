{{--
    Einsatz-Quote eines Termins: „im Einsatz / geklärt / teilgenommen“ als EINE
    Zelle — die Toepfe stehen nicht als Spalten in der Tabelle, sondern hinter
    dem Klick (Schulungs-Detailansicht). Kundenwunsch 09.09.2026: Tiefe per
    Klick statt per Spalte.

    Die MITTLERE Zahl (Kundenwunsch 21.09.2026) sind die von Hand geklaerten
    Faelle: „faengt erst naechsten Monat an“, „mit der Dispo geklaert“. Sie
    steht IMMER da, auch als 0 — eine Spalte, die mal zwei und mal drei Zahlen
    zeigt, laesst den Leser raten, welche er gerade sieht.

    Farbe an der Quote der OFFENEN (im Einsatz gegen im+ohne): „nicht pruefbar“
    fehlt im Nenner, weil dort mangels ZAS-Personalnummer keine Aussage
    moeglich ist — eine frische Schulung waere sonst automatisch rot. „Geklaert“
    fehlt aus dem umgekehrten Grund: dort IST die Aussage getroffen, nur eben
    von einem Menschen. Stuende es im Nenner, bliebe das Abhaken folgenlos rot.

    Erwartet:
      $rows         list<array>  Assigner-Zeilen des Termins
      $interviewId  ?int         oeffnet die Detailansicht (null = kein Klick)
      $drillToken   ?string      alternativ: Drill auf 'im_einsatz' (Gesamt-Zeile)
      $isTotal      ?bool        kraeftigere Darstellung der Summen-Zeile
--}}
@php
    $teilgenommen = $this->countIn($rows, 'teilgenommen');
    $imEinsatz = $this->countIn($rows, 'im_einsatz');
    $geklaert = $this->countIn($rows, 'geklaert');
    $ohneEinsatz = $this->countIn($rows, 'ohne_einsatz');
    $unpruefbar = $this->countIn($rows, 'einsatz_unpruefbar');

    $offen = $imEinsatz + $ohneEinsatz;
    $farbe = 'text-[color:var(--ui-muted)]';
    if ($offen > 0) {
        $quote = $imEinsatz / $offen;
        $farbe = $quote >= 0.7 ? 'text-emerald-700' : ($quote >= 0.4 ? 'text-amber-700' : 'text-red-700');
    } elseif ($geklaert > 0) {
        // Nichts offen, aber geklaert: das Grau bedeutet auf dieser Seite
        // „noch nichts passiert“ und waere hier eine falsche Aussage — hier
        // ist jeder Fall beantwortet, nur eben von Hand.
        $farbe = 'text-teal-700';
    }

    $quoteText = $imEinsatz . '&nbsp;/&nbsp;' . $geklaert . '&nbsp;/&nbsp;' . $teilgenommen;

    $quoteTitle = $teilgenommen === 0
        ? 'Noch niemand hat teilgenommen — der Dispo-Abgleich beginnt mit der Schulung.'
        : 'Im Einsatz / geklärt / teilgenommen: ' . $imEinsatz . ' von ' . $teilgenommen
            . ' Teilgenommenen im Einsatz, ' . $geklaert . ' von Hand geklärt (kommt später, mit der Dispo besprochen)'
            . ' — offen bleiben ' . $ohneEinsatz . ', nicht prüfbar sind ' . $unpruefbar
            . ' (ohne Mitarbeiter oder ZAS-Personalnummer keine Aussage).'
            . ($interviewId !== null ? ' Klick: Detailansicht mit Schulungsleiter und Personenliste.' : '');
@endphp
<td class="px-3 py-2 text-center whitespace-nowrap tabular-nums border-l border-[var(--ui-border)]/60" title="{{ $quoteTitle }}">
    @if ($teilgenommen === 0)
        <span class="text-xs text-[color:var(--ui-muted)]">–</span>
    @elseif ($interviewId !== null)
        <button type="button" wire:click="openTerminDetail({{ $interviewId }})" wire:loading.attr="disabled"
                class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $farbe }} bg-[var(--ui-muted-5)] ring-1 ring-[var(--ui-border)]/60 hover:ring-2 hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer">
            {!! $quoteText !!}
        </button>
    @elseif (($drillToken ?? null) !== null)
        <button type="button" wire:click="drill(@js($drillToken), @js('im_einsatz'), @js('Im Einsatz'))" wire:loading.attr="disabled"
                class="inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs {{ ($isTotal ?? false) ? 'font-bold' : 'font-semibold' }} {{ $farbe }} bg-[var(--ui-muted-5)] ring-1 ring-[var(--ui-border)]/60 hover:ring-2 hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer">
            {!! $quoteText !!}
        </button>
    @else
        <span class="text-xs font-medium {{ $farbe }}">{!! $quoteText !!}</span>
    @endif
</td>
