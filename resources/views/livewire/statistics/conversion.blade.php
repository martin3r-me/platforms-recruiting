{{--
    Conversion-Zelle einer Tabellenzeile (unterschrieben / Bewerbungen).

    Right-Censoring (Spec §6): solange die Kohorte juenger ist als der
    Median-Durchlauf, ist die Quote strukturell zu niedrig — die meisten
    Bewerbungen hatten noch keine Zeit, zur Unterschrift zu kommen. Sie wird
    dann ausgegraut statt versteckt, damit niemand aus einer unfertigen
    Kohorte einen Trend liest.

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
            ? 'text-emerald-600'
            : ($conversion >= 20 ? 'text-amber-600' : 'text-[color:var(--ui-muted)]');
        $convTitle = 'Unterschriften geteilt durch Bewerbungen dieser Zeile.';
    }
@endphp
<td class="px-3 py-2 text-center whitespace-nowrap text-xs {{ $isTotal ? 'font-bold' : 'font-semibold' }} {{ $convClass }}"
    title="{{ $convTitle }}">
    @if ($conversion === null)
        –
    @else
        {{ $conversion }} %
    @endif
</td>
