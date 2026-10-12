{{-- Eine Folie. Erwartet $anleitung (Anleitung) und $folie (Folie). HTML-Felder sind im Parser sicher gerendert. --}}
<section id="{{ $folie->anker() }}" class="rg-folie">
    <div class="rg-folie__balken"></div>
    <div class="rg-folie__kicker">{{ $folie->kicker }}</div>
    <h2 class="rg-folie__titel">{{ $folie->titel }}</h2>
    <div class="rg-folie__linie"></div>

    @if ($folie->einleitungHtml)
        <div class="rg-folie__einleitung">{!! $folie->einleitungHtml !!}</div>
    @endif

    @if ($folie->bild)
        <div class="rg-folie__zweispaltig">
            <div>
                @if ($folie->schritteHtml)
                    <div class="rg-folie__schritte">{!! $folie->schritteHtml !!}</div>
                @endif
                @if ($folie->merksatzHtml)
                    <div class="rg-folie__merksatz">{!! $folie->merksatzHtml !!}</div>
                @endif
            </div>
            <div class="rg-folie__bild">
                @if (\Platform\Recruiting\Support\Anleitungen\AnleitungKatalog::standard()->bildPfad($anleitung->slug, $folie->bild->datei) !== null)
                    <img src="{{ route('recruiting.anleitungen.bild', ['slug' => $anleitung->slug, 'datei' => $folie->bild->datei]) }}" alt="{{ $folie->bild->alt }}">
                @else
                    <div class="rg-folie__platzhalter">{{ $folie->bild->alt }} — Screenshot folgt</div>
                @endif
            </div>
        </div>
    @else
        @if ($folie->schritteHtml)
            <div class="rg-folie__schritte {{ $folie->schritteAnzahl >= 4 ? 'rg-folie__schritte--breit' : '' }}">{!! $folie->schritteHtml !!}</div>
        @endif
        @if ($folie->merksatzHtml)
            <div class="rg-folie__merksatz">{!! $folie->merksatzHtml !!}</div>
        @endif
    @endif

    @if ($folie->tabelleHtml)
        <div class="rg-folie__tabelle">{!! $folie->tabelleHtml !!}</div>
    @endif

    <div class="rg-folie__fuss">
        <span>RheinGedeck · Intern</span>
        <span>{{ sprintf('%02d', $folie->nr) }}</span>
    </div>
</section>
