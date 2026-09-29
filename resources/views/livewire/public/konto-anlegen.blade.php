{{--
    Registrierung des Mitarbeiterkontos (Canvas 68, Spec 3).

    Optik und Klassen wie die Anmeldung der Portal-Huelle
    (livewire/public/portal-shell.blade.php) - dasselbe Layout
    (recruiting::layouts.portal) bringt die CSS mit.

    Bewusst KEINE x-ui-Komponenten: das Portal hat seine eigene Stilvorlage.
    Blade-Direktiven immer in Blockform und nie an ein Wortzeichen geklebt,
    Werte vorberechnen statt einer Direktive im Attribut - beides kompiliert
    sonst still nicht, und der falsche Zweig rendert lautlos.

    Der Token steht NICHT auf dieser Seite. Er ist ein Geheimnis und hat im
    Markup nichts verloren; die Seite kennt ihn serverseitig.
--}}
<div>
@php
    // Vorberechnet, nicht im Attribut entschieden (Hausregel).
    $titel = $duzen ? 'Dein Konto einrichten' : 'Ihr Konto einrichten';
    $einleitung = $duzen
        ? 'Damit niemand anders deine Daten sieht, brauchen wir dein Geburtsdatum. Danach legst du dein Passwort fest.'
        : 'Damit niemand anders Ihre Daten sieht, brauchen wir Ihr Geburtsdatum. Danach legen Sie Ihr Passwort fest.';
    $labelGeburt = $duzen ? 'Dein Geburtsdatum' : 'Ihr Geburtsdatum';
    $labelPasswort = $duzen ? 'Dein neues Passwort' : 'Ihr neues Passwort';
    $labelWiederholung = 'Passwort wiederholen';
    $passwortHinweis = sprintf(
        'Mindestens %d Zeichen. Laenge zaehlt mehr als Sonderzeichen.',
        \Platform\Recruiting\Support\PasswortRegeln::MINDESTLAENGE,
    );
    $fertigTitel = 'Das Konto steht';
    $fertigText = $duzen
        ? 'Ab jetzt meldest du dich mit deiner Rufnummer und diesem Passwort an.'
        : 'Ab jetzt melden Sie sich mit Ihrer Rufnummer und diesem Passwort an.';
@endphp

<div class="screen anmeldung">

    <div class="appbar">
        <div class="wordmark">Rhein<span>Gedeck</span></div>
    </div>

    <div class="scroll">
        @if ($state === 'fertig')
            <div class="greet">
                <h2>{{ $fertigTitel }}</h2>
                <p>{{ $fertigText }}</p>
            </div>
        @else
            <div class="greet">
                <h2>{{ $titel }}</h2>
                <p>{{ $einleitung }}</p>
            </div>

            <form class="card login" wire:submit="registriere">
                <label class="feld">
                    <span class="n">{{ $labelGeburt }}</span>
                    {{--
                        Bewusst KEIN Attribut, das am Handy die reine
                        Zahlentastatur erzwingt. Das war am 06.08.2026 schon
                        einmal ein Login-Blocker; der Waechter-Test haelt
                        dieses Blade komplett frei von jenem Attributnamen,
                        deshalb steht er hier nicht einmal in Prosa.

                        Gebunden an eine Y-m-d-Zeichenkette, nie an eine
                        Datums-Umwandlung.
                    --}}
                    <input type="date" wire:model="geburtsdatum" required autocomplete="bday">
                </label>

                <label class="feld">
                    <span class="n">{{ $labelPasswort }}</span>
                    <input type="password" wire:model="passwort" required
                           autocomplete="new-password" autocapitalize="off"
                           autocorrect="off" spellcheck="false">
                    <span class="hint">{{ $passwortHinweis }}</span>
                </label>

                <label class="feld">
                    <span class="n">{{ $labelWiederholung }}</span>
                    <input type="password" wire:model="passwortWiederholung" required
                           autocomplete="new-password" autocapitalize="off"
                           autocorrect="off" spellcheck="false">
                </label>

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Konto einrichten</button>
            </form>
        @endif
    </div>

</div>
</div>
