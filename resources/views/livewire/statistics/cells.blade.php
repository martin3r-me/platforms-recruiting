{{--
    Zahlen-Zellen einer Tabellenzeile. Bewusst als Partial: dasselbe Markup
    dient Schulungs-, Bucket-, Phasen-, Ort-Summen- und Gesamt-Zeilen — eine
    zweite Kopie wuerde beim naechsten Spalten-Wechsel garantiert auseinanderlaufen.

    Erwartet:
      $colDefs  list<array{key,label,on,total}>
      $rows     list<array>  Assigner-Zeilen, die diese Tabellenzeile bilden
      $token    string       Drill-Token der Zeile (einmal pro Zeile gebaut)
      $prefix   string       Label-Praefix, nur fuer den title-Tooltip
      $isTotal  bool         Summen-Zeile (kraeftigere Badge-Farbe)
--}}
@foreach ($colDefs as $col)
    @php
        $count = $this->countIn($rows, $col['key']);
        $badge = $count > 0
            ? ($isTotal ? $col['total'] : $col['on'])
            : 'bg-gray-50 text-gray-400';
        $weight = $isTotal ? 'font-bold' : 'font-medium';
    @endphp
    <td class="px-3 py-2 text-center whitespace-nowrap">
        @if ($count > 0)
            <button
                type="button"
                wire:click="drill('{{ $token }}', '{{ $col['key'] }}', '{{ $col['label'] }}')"
                wire:loading.attr="disabled"
                title="{{ $prefix }} — {{ $col['label'] }}: Personen anzeigen"
                class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs {{ $weight }} {{ $badge }} hover:ring-2 hover:ring-[var(--ui-border)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ui-primary)] transition-all cursor-pointer"
            >{{ $count }}</button>
        @else
            <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs {{ $weight }} bg-gray-50 text-gray-400">0</span>
        @endif
    </td>
@endforeach
