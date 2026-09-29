{{--
    Anmeldung am Mitarbeiterkonto (Canvas 68, Spec 2.1).

    Optik und Klassen wie die Anmeldung der Portal-Huelle
    (livewire/public/portal-shell.blade.php) - dasselbe Layout
    (recruiting::layouts.portal) bringt die CSS mit.

    Bewusst KEINE x-ui-Komponenten: das Portal hat seine eigene Stilvorlage.
    Blade-Direktiven immer in Blockform und nie an ein Wortzeichen geklebt,
    Werte vorberechnen statt einer Direktive im Attribut - beides kompiliert
    sonst still nicht, und der falsche Zweig rendert lautlos.

    HIER STEHT KEIN GEBURTSDATUM UND KEINE AUSWEISNUMMER. Das alte Verfahren
    darf auf dieser Seite nicht als zweiter Weg danebenstehen: der
    Benutzername ist die Handynummer und kein Geheimnis - eine Nebentuer mit
    Geburtsdatum waere unsicherer als der heutige Token-Weg.

    Die Seite siezt. Sie ist oeffentlich und weiss vor der Anmeldung nicht,
    wer davor sitzt - und damit auch nicht, welches Team welche Anrede
    eingestellt hat. Geduzt wird ab dem Portal, das die Anstellung kennt.
--}}
<div>
@php
    // Vorberechnet, nicht im Attribut entschieden (Hausregel).
    $codeUrl = route('recruiting.public.konto-anlegen-code');
    $ohneZielTitel = 'Sie sind angemeldet';
    $ohneZielText = 'Ihr Zugang zum Mitarbeiterportal ist noch nicht freigeschaltet. '
        . 'Bitte wenden Sie sich an Ihre Ansprechperson bei RheinGedeck.';
@endphp

<div class="screen anmeldung">

    <div class="appbar">
        <div class="wordmark">Rhein<span>Gedeck</span></div>
    </div>

    <div class="scroll">
        @if ($state === 'ohne-ziel')
            <div class="greet">
                <h2>{{ $ohneZielTitel }}</h2>
                <p>{{ $ohneZielText }}</p>
            </div>
        @else
            <div class="greet">
                <h2>Anmelden</h2>
                <p>Mit Ihrer Handynummer und Ihrem Passwort.</p>
            </div>

            <form class="card login" wire:submit="anmelden">
                <label class="feld">
                    <span class="n">Ihre Handynummer</span>
                    {{--
                        type="tel" ist hier erlaubt und anderswo nicht: eine
                        Rufnummer kann keine Buchstaben enthalten. Das
                        Attribut, das am Handy die reine Zahlentastatur
                        erzwingt, steht trotzdem nirgends in diesem Blade -
                        es war am 06.08.2026 schon einmal ein Login-Blocker,
                        und ein Waechter-Test haelt die Datei komplett davon
                        frei. Deshalb wird es hier nicht einmal benannt.
                    --}}
                    <input type="tel" wire:model="nummer" required
                           autocomplete="tel" autocapitalize="off"
                           autocorrect="off" spellcheck="false" placeholder="z.B. 0151 23456789">
                </label>

                <label class="feld">
                    <span class="n">Ihr Passwort</span>
                    <input type="password" wire:model="passwort" required
                           autocomplete="current-password" autocapitalize="off"
                           autocorrect="off" spellcheck="false">
                </label>

                <label class="feld haken">
                    {{--
                        "Angemeldet bleiben" verlaengert die Sitzung. Es legt
                        KEIN dauerhaftes Geheimnis in einen Cookie ab - das
                        waere ein zweiter Anmeldeweg ohne Passwort.
                    --}}
                    <input type="checkbox" wire:model="angemeldetBleiben">
                    <span class="n">Angemeldet bleiben</span>
                </label>

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Anmelden</button>
            </form>

            <div class="card">
                <p>Sie haben noch kein Konto?</p>
                <a class="btn" href="{{ $codeUrl }}">Ich habe einen Einladungscode</a>
            </div>
        @endif
    </div>

</div>
</div>
