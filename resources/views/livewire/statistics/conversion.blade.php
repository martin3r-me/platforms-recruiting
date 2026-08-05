{{--
    Conversion-Zelle einer Tabellenzeile (unterschrieben / Bewerbungen).

    Right-Censoring (Spec §6): solange eine Kohorte juenger ist als der
    Median-Durchlauf, ist ihre Quote strukturell zu niedrig — die meisten
    Bewerbungen hatten noch keine Zeit zur Unterschrift. Sie wird dann
    ausgegraut statt versteckt.

    Ob die Einzelzeilen- oder die Aggregat-Regel greift, entscheidet
    CohortViewModel::isCensoredForRows() anhand der Zeilenmenge — hier wird dazu
    bewusst NICHTS durchgereicht, damit diese Zelle es nicht falsch setzen kann.
    $isTotal steuert nur noch die Schriftstaerke.

    Erwartet: $rows, $isTotal — die Median-Schwelle wird NICHT hereingereicht, sondern
    ueber $this->censorNote() gelesen: isCensored() entscheidet intern mit demselben
    Wert, ein zweiter Pfad auf dieselbe Wahrheit koennte auseinanderlaufen.
--}}
@php
    $conversion = $this->conversionOf($rows);
    $censored = $this->isCensored($rows);

    if ($conversion === null) {
        $convClass = 'text-[color:var(--ui-muted)]';
        $convTitle = 'Keine Bewerbungen in dieser Zeile — keine Quote.';
    } elseif ($censored) {
        $convClass = 'text-gray-400 italic';
        $convTitle = $this->censorNote();
    } else {
        $convClass = $conversion >= 50
            ? 'text-emerald-700'
            : ($conversion >= 20 ? 'text-amber-700' : 'text-[color:var(--ui-muted)]');
        $convTitle = 'Unterschriften geteilt durch Bewerbungen dieser Zeile.';
    }
@endphp
<td class="px-3 py-2 text-center whitespace-nowrap text-xs tabular-nums {{ $isTotal ? 'font-bold' : 'font-semibold' }} {{ $convClass }}"
    title="{{ $convTitle }}">
    @if ($conversion === null)
        –
    @else
        {{ $conversion }} %
    @endif
</td>
