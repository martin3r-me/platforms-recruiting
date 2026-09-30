{{--
    Anmeldung am Mitarbeiterkonto und die Wege zurueck hinein
    (Canvas 68, Spec 2.1 und 5).

    Optik und Klassen wie die Anmeldung der Portal-Huelle
    (livewire/public/portal-shell.blade.php) - dasselbe Layout
    (recruiting::layouts.portal) bringt die CSS mit.

    Bewusst KEINE x-ui-Komponenten: das Portal hat seine eigene Stilvorlage.
    Blade-Direktiven immer in Blockform und nie an ein Wortzeichen geklebt,
    Werte vorberechnen statt einer Direktive im Attribut - beides kompiliert
    sonst still nicht, und der falsche Zweig rendert lautlos.

    RANGFOLGE: Wer hier landet, will sich anmelden. Deshalb traegt nur der
    Anmeldeblock eine Karte; die Wege zurueck sind leise Textknoepfe und der
    Einladungscode ist eine Fusszeile hinter einem Haarstrich. Vorher stand
    alles gleich laut in eigenen Kaesten, zuletzt sogar ein Rahmen um einen
    Rahmen. Ein Kasten oder keiner, nie beides ineinander.

    Der Bildschirm traegt ausser .anmeldung auch .konto; daran haengen diese
    Regeln (Layout: layouts/portal.blade.php, Abschnitt "Konto-Seiten").
    Die Portal-Huelle traegt .anmeldung ebenfalls und bleibt unberuehrt.
    Die Abstaende sind mit Absicht UNGLEICH - gleiche Abstaende ueberall
    waren der Grund, warum die Seite gedraengt wirkte.

    IM ANMELDEFORMULAR STEHT KEIN GEBURTSDATUM UND KEINE AUSWEISNUMMER. Das
    alte Verfahren darf dort nicht als zweiter Weg danebenstehen: der
    Benutzername ist die Handynummer und kein Geheimnis - eine Nebentuer mit
    Geburtsdatum waere unsicherer als der heutige Token-Weg. Das Geburtsdatum
    kommt erst im ZWEITEN Schritt von "Passwort vergessen" vor, also hinter
    einem Code, der an die hinterlegte Nummer ging.

    DER ZUSTAND 'vergessen-code' NENNT DIE EINGETIPPTE NUMMER NICHT. Das ist
    keine Sparsamkeit, sondern die Begleitregel aus Spec 5: die Antwort auf
    "Passwort vergessen" muss fuer eine unbekannte Nummer zeichengleich sein
    mit der fuer eine bekannte. Alles, was sich zwischen den beiden Faellen
    unterscheiden koennte, darf dort nicht stehen - und der Mensch hat die
    Nummer gerade selbst getippt.

    Die Seite siezt. Sie ist oeffentlich und weiss vor der Anmeldung nicht,
    wer davor sitzt - und damit auch nicht, welches Team welche Anrede
    eingestellt hat. Geduzt wird ab dem Portal, das die Anstellung kennt.
--}}
<div>
@php
    // Vorberechnet, nicht im Attribut entschieden (Hausregel).
    $codeUrl = route('recruiting.public.konto-anlegen-code');
    $ohneZielTitel = 'Sie sind angemeldet';
    $ohneZielText = 'Ihr eigener Bereich ist noch nicht freigeschaltet. '
        . 'Bitte wenden Sie sich an Ihre Ansprechperson bei RheinGedeck.';

    $passwortHinweis = sprintf(
        'Mindestens %d Zeichen. Laenge zaehlt mehr als Sonderzeichen.',
        \Platform\Recruiting\Support\PasswortRegeln::MINDESTLAENGE,
    );

    // Weg 1+2 (Spec 5): alte Nummer, Passwort, neue Nummer.
    $nummerTitel = 'Neue Handynummer eintragen';
    $nummerText = 'Melden Sie sich mit Ihrer bisherigen Nummer und Ihrem Passwort an. '
        . 'Den Bestaetigungscode schicken wir an die neue Nummer.';
    $nummerCodeTitel = 'Code eingeben';
    $nummerCodeText = 'Wir haben einen Code an Ihre neue Handynummer geschickt.';

    // Weg 3 (Spec 5): Passwort vergessen.
    $vergessenTitel = 'Passwort vergessen';
    $vergessenText = 'Wir schicken Ihnen einen Code an Ihre Handynummer.';
    // DIESER TEXT SAGT BEWUSST "Falls": er steht genauso da, wenn wir die
    // Nummer gar nicht kennen. Ein "Wir haben Ihnen einen Code geschickt"
    // waere in diesem Fall gelogen - und die Bestaetigung, dass es die
    // Nummer gibt.
    $vergessenCodeTitel = 'Code und Geburtsdatum';
    $vergessenCodeText = 'Falls wir diese Handynummer kennen, haben wir Ihnen gerade einen Code '
        . 'geschickt. Bitte geben Sie ihn zusammen mit Ihrem Geburtsdatum ein und waehlen Sie '
        . 'ein neues Passwort.';

    // Weg 4 (Spec 5): Nummer weg UND Passwort vergessen.
    $notfallTitel = 'Nummer und Passwort weg';
    $notfallText = 'Damit wir sicher sind, dass Sie es sind, brauchen wir Ihr Geburtsdatum und '
        . 'die letzten vier Ziffern Ihrer Ausweisnummer. Den Bestaetigungscode schicken wir an '
        . 'die neue Nummer.';
    // AUCH DIESER TEXT SAGT "Falls": er steht genauso da, wenn die Nachweise
    // nicht gestimmt haben. Sonst waeren die Ausweisziffern ein Orakel.
    $notfallCodeTitel = 'Code eingeben';
    $notfallCodeText = 'Falls Ihre Angaben stimmen, haben wir gerade einen Code an Ihre neue '
        . 'Handynummer geschickt.';

    $fertigTitel = match ($fertigGrund) {
        'nummer'  => 'Ihre Handynummer ist geaendert',
        'notfall' => 'Wir haben Ihre Meldung',
        default   => 'Ihr Passwort steht',
    };
    $fertigText = match ($fertigGrund) {
        'nummer'  => 'Ab jetzt melden Sie sich mit der neuen Nummer und Ihrem bisherigen Passwort an.',
        // Der Antrag wirkt erst nach der Frist, und danach fehlt immer noch
        // das Passwort - beides gehoert hier gesagt, sonst wartet der Mensch
        // auf etwas anderes, als passiert.
        'notfall' => 'Ihre neue Handynummer wird am ' . $wirksamAb . ' uebernommen. '
            . 'Danach waehlen Sie ueber "Passwort vergessen" ein neues Passwort. '
            . 'Wenn Sie das nicht selbst veranlasst haben, melden Sie sich bitte sofort bei '
            . 'Ihrer Ansprechperson bei RheinGedeck.',
        default   => 'Ab jetzt melden Sie sich mit Ihrer Handynummer und dem neuen Passwort an.',
    };
@endphp

<div class="screen anmeldung konto">

    <div class="appbar">
        <div class="wordmark">Rhein<span>Gedeck</span></div>
    </div>

    <div class="scroll">
        @if ($state === 'ohne-ziel')
            <div class="greet">
                <h2>{{ $ohneZielTitel }}</h2>
                <p>{{ $ohneZielText }}</p>
            </div>

            {{--
                Ohne diesen Knopf waere der Zustand eine Sackgasse:
                angemeldet, kein Weg weiter, und nicht einmal die
                Moeglichkeit, von vorn anzufangen - etwa weil jemand die
                Nummer eines Kollegen getippt hat oder weil das Geraet
                geteilt wird.
            --}}
            <button type="button" class="btn" wire:click="abmelden">Abmelden</button>
        @elseif ($state === 'fertig')
            <div class="greet">
                <h2>{{ $fertigTitel }}</h2>
                <p>{{ $fertigText }}</p>
            </div>

            <button type="button" class="btn primary" wire:click="zurAnmeldung">Zur Anmeldung</button>
        @elseif ($state === 'nummer')
            <div class="greet">
                <h2>{{ $nummerTitel }}</h2>
                <p>{{ $nummerText }}</p>
            </div>

            <form class="card login" wire:submit="nummerAnfordern">
                <label class="feld">
                    <span class="n">Ihre bisherige Handynummer</span>
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

                <label class="feld">
                    <span class="n">Ihre neue Handynummer</span>
                    <input type="tel" wire:model="neueNummer" required
                           autocomplete="off" autocapitalize="off"
                           autocorrect="off" spellcheck="false" placeholder="z.B. 0170 98765432">
                </label>

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Code anfordern</button>
            </form>

            <div class="wege">
                <button type="button" class="weg" wire:click="zurAnmeldung">Abbrechen</button>
            </div>
        @elseif ($state === 'nummer-code')
            <div class="greet">
                <h2>{{ $nummerCodeTitel }}</h2>
                <p>{{ $nummerCodeText }}</p>
            </div>

            <form class="card login" wire:submit="nummerBestaetigen">
                <label class="feld">
                    <span class="n">Ihr Code</span>
                    {{--
                        Bewusst KEIN Attribut, das am Handy die reine
                        Zahlentastatur erzwingt. Das war am 06.08.2026 schon
                        einmal ein Login-Blocker; ein Waechter-Test haelt
                        dieses Blade komplett frei von jenem Attributnamen,
                        deshalb steht er hier nicht einmal in Prosa.
                    --}}
                    <input type="text" wire:model="code" required maxlength="10"
                           autocomplete="one-time-code" autocapitalize="off"
                           autocorrect="off" spellcheck="false">
                </label>

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Nummer aendern</button>
            </form>

            <div class="wege">
                <button type="button" class="weg" wire:click="zurAnmeldung">Abbrechen</button>
            </div>
        @elseif ($state === 'vergessen')
            <div class="greet">
                <h2>{{ $vergessenTitel }}</h2>
                <p>{{ $vergessenText }}</p>
            </div>

            <form class="card login" wire:submit="passwortCodeAnfordern">
                <label class="feld">
                    <span class="n">Ihre Handynummer</span>
                    <input type="tel" wire:model="nummer" required
                           autocomplete="tel" autocapitalize="off"
                           autocorrect="off" spellcheck="false" placeholder="z.B. 0151 23456789">
                </label>

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Code anfordern</button>
            </form>

            <div class="wege">
                <button type="button" class="weg" wire:click="zurAnmeldung">Abbrechen</button>
            </div>
        @elseif ($state === 'vergessen-code')
            <div class="greet">
                <h2>{{ $vergessenCodeTitel }}</h2>
                <p>{{ $vergessenCodeText }}</p>
            </div>

            <form class="card login" wire:submit="passwortSetzen">
                <label class="feld">
                    <span class="n">Ihr Code</span>
                    <input type="text" wire:model="code" required maxlength="10"
                           autocomplete="one-time-code" autocapitalize="off"
                           autocorrect="off" spellcheck="false">
                </label>

                <label class="feld">
                    <span class="n">Ihr Geburtsdatum</span>
                    {{--
                        Gebunden an eine Y-m-d-Zeichenkette, nie an eine
                        Datums-Umwandlung.
                    --}}
                    <input type="date" wire:model="geburtsdatum" required autocomplete="bday">
                </label>

                <label class="feld">
                    <span class="n">Ihr neues Passwort</span>
                    <input type="password" wire:model="neuesPasswort" required
                           autocomplete="new-password" autocapitalize="off"
                           autocorrect="off" spellcheck="false">
                    <span class="hint">{{ $passwortHinweis }}</span>
                </label>

                <label class="feld">
                    <span class="n">Passwort wiederholen</span>
                    <input type="password" wire:model="neuesPasswortWiederholung" required
                           autocomplete="new-password" autocapitalize="off"
                           autocorrect="off" spellcheck="false">
                </label>

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Passwort speichern</button>
            </form>

            <div class="wege">
                <button type="button" class="weg" wire:click="zurAnmeldung">Abbrechen</button>
            </div>
        @elseif ($state === 'notfall')
            <div class="greet">
                <h2>{{ $notfallTitel }}</h2>
                <p>{{ $notfallText }}</p>
            </div>

            <form class="card login" wire:submit="notfallAnfordern">
                <label class="feld">
                    <span class="n">Ihre bisherige Handynummer</span>
                    <input type="tel" wire:model="nummer" required
                           autocomplete="tel" autocapitalize="off"
                           autocorrect="off" spellcheck="false" placeholder="z.B. 0151 23456789">
                </label>

                <label class="feld">
                    <span class="n">Ihr Geburtsdatum</span>
                    {{--
                        Gebunden an eine Y-m-d-Zeichenkette, nie an eine
                        Datums-Umwandlung.
                    --}}
                    <input type="date" wire:model="geburtsdatum" required autocomplete="bday">
                </label>

                <label class="feld">
                    <span class="n">Die letzten vier Ziffern Ihrer Ausweisnummer</span>
                    {{--
                        Bewusst KEIN Attribut, das am Handy die reine
                        Zahlentastatur erzwingt: Ausweisnummern enthalten
                        BUCHSTABEN. Genau dieser Fehler war am 06.08.2026
                        schon einmal ein Login-Blocker.
                    --}}
                    <input type="text" wire:model="ausweis" required maxlength="4"
                           autocomplete="off" autocapitalize="characters"
                           autocorrect="off" spellcheck="false">
                </label>

                <label class="feld">
                    <span class="n">Ihre neue Handynummer</span>
                    <input type="tel" wire:model="neueNummer" required
                           autocomplete="off" autocapitalize="off"
                           autocorrect="off" spellcheck="false" placeholder="z.B. 0170 98765432">
                </label>

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Code anfordern</button>
            </form>

            <div class="wege">
                <button type="button" class="weg" wire:click="zurAnmeldung">Abbrechen</button>
            </div>
        @elseif ($state === 'notfall-code')
            <div class="greet">
                <h2>{{ $notfallCodeTitel }}</h2>
                <p>{{ $notfallCodeText }}</p>
            </div>

            <form class="card login" wire:submit="notfallBestaetigen">
                <label class="feld">
                    <span class="n">Ihr Code</span>
                    <input type="text" wire:model="code" required maxlength="10"
                           autocomplete="one-time-code" autocapitalize="off"
                           autocorrect="off" spellcheck="false">
                </label>

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Absenden</button>
            </form>

            <div class="wege">
                <button type="button" class="weg" wire:click="zurAnmeldung">Abbrechen</button>
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

                {{--
                    HIER STAND EINMAL "Angemeldet bleiben" (Ruling GD-10).
                    Der Haken setzte einen Merker in die Sitzung und bewirkte
                    sonst nichts; eine echte Verlaengerung braucht Middleware
                    im Wirt und wartet auf eine Freigabe. Ein
                    Kontrollkaestchen, das nichts tut, ist ein Versprechen,
                    das nicht gehalten wird.
                --}}

                @if ($fehler !== '')
                    <div class="alert crit">
                        <span class="dot crit" style="margin-top:6px"></span>
                        <div class="txt">{{ $fehler }}</div>
                    </div>
                @endif

                <button type="submit" class="btn primary" wire:loading.attr="disabled">Anmelden</button>
            </form>

            {{--
                Die Wege zurueck (Spec 5). Sie stehen als Knoepfe da und
                nicht als zweites Formular: was hier ein Eingabefeld haette,
                waere ein zweiter Anmeldeweg neben dem oberen.

                Sie sind Ausnahmen und sehen auch so aus: leise Textknoepfe,
                eng untereinander, mit Luft davor - vorher schrien drei
                gleich grosse Umriss-Kaesten so laut wie der Hauptweg.
                Ihre Beschriftungen bleiben die einzige Auskunft: was ein
                Weg an Nachweisen verlangt, steht erst auf der Seite, zu der
                er fuehrt - hier nicht, und auch nicht in Prosa.
            --}}
            <div class="wege">
                <button type="button" class="weg" wire:click="zumPasswortVergessen">Passwort vergessen</button>
                <button type="button" class="weg" wire:click="zumNummernwechsel">Neue Handynummer</button>
                <button type="button" class="weg" wire:click="zumNotfall">Nummer und Passwort weg</button>
            </div>

            {{--
                Der Einladungscode ist der seltenste Fall und steht als
                abgesetzte Fusszeile da - vorher war er ein Rahmen um einen
                Rahmen. Die Adresse bleibt unveraendert am href.
            --}}
            <p class="fuss-konto">
                Sie haben noch kein Konto?
                <a href="{{ $codeUrl }}">Ich habe einen Einladungscode</a>
            </p>
        @endif
    </div>

</div>
</div>
