{{--
    Zahlen-Zellen einer Tabellenzeile. Bewusst als Partial: dasselbe Markup
    dient Schulungs-, Bucket-, Phasen-, Ort-Summen- und Gesamt-Zeilen — eine
    zweite Kopie wuerde beim naechsten Spalten-Wechsel garantiert auseinanderlaufen.

    Erwartet:
      $colDefs  list<array{key,label,on,total,onlyRunning?,gstart?}>
      $rows     list<array>  Assigner-Zeilen, die diese Tabellenzeile bilden
      $token    string       Drill-Token der Zeile (einmal pro Zeile gebaut)
      $prefix   string       Label-Praefix, nur fuer den title-Tooltip
      $isTotal  bool         Summen-Zeile (kraeftigere Badge-Farbe)

    'onlyRunning' => true markiert Spalten, die nur fuer laufende Kohorten eine
    Aussage sind ("noch offen"). Auf ausgeschlossenen Buckets steht dort "–" wie
    bei den Kapazitaetsspalten — eine 0 saehe wie ein Messwert aus.

    'gstart' => true zieht die Trennlinie zur vorigen Spaltengruppe (Trichter /
    Abzweige / Vertrag / Stand). Die Gruppen sind der Grund, warum 14 Spalten
    lesbar bleiben: der Blick bekommt Absaetze.

    Nullen tragen KEINE Pille: eine gefuellte Pille ist die Markierung fuer
    „hier ist etwas passiert". Bei ueber der Haelfte leerer Zellen zog die
    graue Null vorher genauso viel Aufmerksamkeit wie ein echter Wert.
--}}
@foreach ($colDefs as $col)
    @php
        $applicable = empty($col['onlyRunning']) || $this->hasRunningRow($rows);
        $count = $applicable ? $this->countIn($rows, $col['key']) : 0;
        $badge = $isTotal ? $col['total'] : $col['on'];
        // Bewerbungen ist die Bezugsgroesse aller anderen Spalten und wird
        // deshalb schwerer gesetzt.
        $weight = $isTotal ? 'font-bold' : ($col['key'] === 'ids' ? 'font-semibold' : 'font-medium');
        $groupBorder = empty($col['gstart']) ? '' : 'border-l border-[var(--ui-border)]/60';
    @endphp
    <td class="px-3 py-2 text-center whitespace-nowrap tabular-nums {{ $groupBorder }}">
        @if (!$applicable)
            <span class="text-xs text-[color:var(--ui-muted)]"
                  title="{{ $col['label'] }} gilt nur für laufende Kohorten (Schulung / ohne Schulung) — dieser Zeilentyp ist ein ausgeschlossener Bucket.">–</span>
        @elseif ($count > 0)
            <button
                type="button"
                {{-- @js statt nackter Anfuehrungszeichen: die Spalten-Labels sind
                     seit der Ausschreibungs-Tabelle nicht mehr nur Konstanten —
                     die Phasen-Spalten tragen FREIEN NUTZERTEXT. In
                     '{{ $label }}' zerlegte ein Apostroph den Ausdruck, ein
                     abschliessender Backslash oder ein Zeilenumbruch ergab einen
                     SyntaxError (der Drill-Button dieser Spalte war dann in
                     ALLEN Zeilen tot), und 'A\B' parste still zum falschen Label
                     'AB'. @js kodiert den Wert als JS-Literal mit hex-escapten
                     Quotes/Ampersands — attribut- wie parser-sicher, ohne den
                     angezeigten Text zu verfremden. --}}
                wire:click="drill(@js($token), @js($col['key']), @js($col['label']))"
                wire:loading.attr="disabled"
                title="{{ $prefix }} — {{ $col['label'] }}: Personen anzeigen"
                class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs {{ $weight }} {{ $badge }} hover:ring-2 hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer"
            >{{ $count }}</button>
        @else
            <span class="text-xs text-[color:var(--ui-muted)]">0</span>
        @endif
    </td>
@endforeach
