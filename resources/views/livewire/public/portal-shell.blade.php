{{--
    Das neue Mitarbeiterportal. Markup folgt dem abgenommenen Entwurf
    (resources/mockups/crew-portal.html) — gleiche Klassen, gleiche Struktur,
    nur ohne den Telefonrahmen drumherum.

    Bewusst KEINE x-ui-Komponenten: das Portal bringt seine eigene CSS mit
    (siehe layouts/portal.blade.php). Blade-Direktiven immer in Blockform,
    nie an Wortzeichen geklebt — sonst kompilieren sie still nicht.
--}}
<div>
@php
    // Die Anmeldeseite hat keine Seitenleiste. Ohne diese Kennzeichnung landet
    // sie am Rechner in der 240px-Spalte des Zweispalters und wird gequetscht.
    // Vorberechnet statt @if im Attribut — Hausregel.
    $schirm = $state === 'verified' ? 'screen' : 'screen anmeldung';
@endphp

<div class="{{ $schirm }}" x-data="{ tab: 'start' }">

    @if ($state !== 'verified')

        <div class="appbar">
            <div class="wordmark">Rhein<span>Gedeck</span></div>
        </div>

        <div class="scroll">
            @if ($state === 'gesperrt')
                <div class="greet">
                    <h2>Dein Zugang ist gesperrt</h2>
                    <p>Bitte melde dich bei deiner Ansprechperson bei RheinGedeck.</p>
                </div>
            @elseif ($state === 'weg')
                <div class="greet">
                    <h2>Bitte lade die Seite neu</h2>
                    <p>An deinem Zugang hat sich gerade etwas geändert.</p>
                </div>
            @elseif ($state === 'rateLimited')
                <div class="greet">
                    <h2>Zu viele Versuche</h2>
                    <p>Bitte versuche es in 15 Minuten noch einmal.</p>
                </div>
            @else
                @php
                    $begruessung = $duzen
                        ? 'Damit niemand anders deine Daten sieht, brauchen wir zwei Angaben von dir.'
                        : 'Damit niemand anders Ihre Daten sieht, brauchen wir zwei Angaben von Ihnen.';
                    $labelGeburt  = $duzen ? 'Dein Geburtsdatum' : 'Ihr Geburtsdatum';
                    // „Stellen", nicht „Ziffern": deutsche Ausweisnummern
                    // enthalten Buchstaben (L01X00T47 → 0T47). Genau deshalb
                    // vergleicht verifyPortalAccess mit strcasecmp.
                    $labelAusweis = $duzen
                        ? 'Die letzten 4 Stellen deiner Ausweisnummer'
                        : 'Die letzten 4 Stellen Ihrer Ausweisnummer';
                @endphp
                <div class="greet">
                    <h2>Anmelden</h2>
                    <p>{{ $begruessung }}</p>
                </div>

                <form class="card login" wire:submit="verify">
                    <label class="feld">
                        <span class="n">{{ $labelGeburt }}</span>
                        <input type="date" wire:model="birthDate" required autocomplete="bday">
                    </label>
                    <label class="feld">
                        <span class="n">{{ $labelAusweis }}</span>
                        {{--
                            KEIN inputmode="numeric". Das war schon einmal ein
                            Login-Blocker (behoben am 06.08.2026 in 9baafe9):
                            auf der iOS-Zahlentastatur liessen sich die
                            Buchstaben der Ausweisnummer nicht eingeben.
                        --}}
                        <input type="text" wire:model="idLast4" required maxlength="4"
                               autocomplete="off" autocapitalize="characters"
                               autocorrect="off" spellcheck="false" placeholder="z.B. 0T47">
                    </label>

                    @if ($fehler !== '')
                        <div class="alert crit">
                            <span class="dot crit" style="margin-top:6px"></span>
                            <div class="txt">{{ $fehler }}</div>
                        </div>
                    @endif

                    <button type="submit" class="btn primary" wire:loading.attr="disabled">Anmelden</button>
                </form>
            @endif
        </div>

    @else

        @php
            $untertitel = $offen === 0
                ? ($duzen ? 'Bei dir ist alles vollständig. Danke!' : 'Bei Ihnen ist alles vollständig. Danke!')
                : ($duzen ? 'Es fehlt noch etwas von dir.' : 'Es fehlt noch etwas von Ihnen.');
            $offeneAufgaben = array_values(array_filter($aufgaben, fn ($a) => $a['offen']));
            $erledigt       = array_values(array_filter($aufgaben, fn ($a) => ! $a['offen']));
            // Die Arbeitgeber-Frage ist wichtiger als jeder Nachweis -- sie
            // gewinnt die "ganz oben"-Zeile der Zusammenfassung, solange sie
            // unbeantwortet ist.
            $ersteAufgabe   = $arbeitgeberAufgabe ?? ($offeneAufgaben[0] ?? null);
        @endphp

        <div class="appbar">
            <div class="wordmark">Rhein<span>Gedeck</span></div>
            <div class="avatar">{{ $initialen }}</div>
        </div>


        {{--
            Desktop: Seitenleiste statt Reiterleiste. Das ist keine Erfindung —
            der Entwurf bringt sie mit (.brail/.rnav/.bmain im Stilblock), ich
            hatte sie zuerst fuer Deko der Praesentationsseite gehalten.
            Dieselben Bereiche, derselbe Alpine-Zustand, nur anderes Gestell.
        --}}
        <aside class="brail">
            <div class="logo">Rhein<span>Gedeck</span></div>
            <button type="button" class="rnav" @click="tab = 'start'" :class="tab === 'start' && 'on'">
                <svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/></svg> Start
                @if ($offen > 0)
                    <span class="n">{{ $offen }}</span>
                @endif
            </button>
            <button type="button" class="rnav" @click="tab = 'jobs'" :class="tab === 'jobs' && 'on'">
                <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></svg> Einsätze
            </button>
            <button type="button" class="rnav" @click="tab = 'docs'" :class="tab === 'docs' && 'on'">
                <svg viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg> Dokumente
            </button>
            <button type="button" class="rnav" @click="tab = 'me'" :class="tab === 'me' && 'on'">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg> Profil
            </button>
            <div class="who">
                <div class="avatar">{{ $initialen }}</div>
                <div>
                    <div class="nm">{{ $displayName }}</div>
                    @php
                        $ersteNummer = optional($anstellungen->first())->personnel_number;
                    @endphp
                    @if ($ersteNummer)
                        <div class="sb">Personalnr. {{ $ersteNummer }}</div>
                    @endif
                </div>
            </div>
        </aside>

        <div class="scroll">

            {{-- ---------------- START ---------------- --}}
            <div class="pane" :class="tab === 'start' && 'on'">
                <div class="greet">
                    <h2>{{ $duzen ? 'Moin' : 'Guten Tag,' }} {{ $displayName }} 👋</h2>
                    <p>{{ $untertitel }}</p>
                </div>

                {{--
                    Der dunkle Block ist im Entwurf „Dein naechster Einsatz".
                    Den gibt es noch nicht (Schritt 4). Statt ihn leer zu lassen
                    oder einen Termin zu erfinden, steht hier der Stand der
                    Unterlagen — die Frage, die der Start-Bildschirm heute
                    beantwortet. Wenn die Einsaetze kommen, nehmen sie diesen
                    Platz und der Stand rutscht darunter.
                --}}
                <div class="next">
                    <div class="kicker">{{ $duzen ? 'Deine Unterlagen' : 'Ihre Unterlagen' }}</div>
                    @if ($offen === 0)
                        <h3>Alles vollständig</h3>
                        <div class="when">
                            {{ $duzen
                                ? 'Wir haben alles, was wir von dir brauchen. Läuft etwas ab, melden wir uns rechtzeitig.'
                                : 'Wir haben alles, was wir von Ihnen brauchen. Läuft etwas ab, melden wir uns rechtzeitig.' }}
                        </div>
                    @else
                        <h3>{{ $offen === 1 ? 'Ein Punkt offen' : $offen . ' Punkte offen' }}</h3>
                        <div class="when">{{ $ersteAufgabe['label'] }} — {{ $ersteAufgabe['text'] }}</div>
                    @endif
                </div>

                <div class="bcols">
                @if ($offen > 0)
                    <div>
                        <div class="sec-label">Das fehlt noch <span class="count">{{ $offen }}</span></div>
                        <div class="card" style="margin-top:11px">
                            @if ($arbeitgeberAufgabe !== null)
                                {{--
                                    Ganz oben, noch vor jedem Nachweis: an
                                    dieser Angabe haengt die Steuerklasse. Ein
                                    Klick fuehrt ins Profil -- dort steht die
                                    eigentliche Frage, kein Upload-Formular.
                                --}}
                                <div class="task tap" @click="tab = 'me'">
                                    <span class="dot {{ $arbeitgeberAufgabe['punkt'] }}"></span>
                                    <div>
                                        <div class="t">{{ $arbeitgeberAufgabe['label'] }}</div>
                                        <div class="s">{{ $arbeitgeberAufgabe['text'] }}</div>
                                    </div>
                                    <span class="chev">›</span>
                                </div>
                            @endif
                            @foreach ($offeneAufgaben as $aufgabe)
                                <div class="task tap" wire:click="oeffneUpload('{{ $aufgabe['code'] }}')">
                                    <span class="dot {{ $aufgabe['punkt'] }}"></span>
                                    <div>
                                        <div class="t">{{ $aufgabe['label'] }}</div>
                                        <div class="s">{{ $aufgabe['text'] }}</div>
                                    </div>
                                    <span class="chev">›</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($erledigt) > 0)
                    <div>
                        <div class="sec-label">Liegt vor <span class="count">{{ count($erledigt) }}</span></div>
                        <div class="card flat" style="margin-top:11px">
                            @foreach ($erledigt as $aufgabe)
                                <div class="task tap" wire:click="oeffneUpload('{{ $aufgabe['code'] }}')">
                                    <span class="dot ok"></span>
                                    <div>
                                        <div class="t">{{ $aufgabe['label'] }}</div>
                                        <div class="s">{{ $aufgabe['text'] }}</div>
                                    </div>
                                    <span class="chev">›</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
                </div>
            </div>

            {{-- ---------------- EINSAETZE ---------------- --}}
            <div class="pane" :class="tab === 'jobs' && 'on'">
                <div class="greet">
                    <h2>{{ $duzen ? 'Deine Einsätze' : 'Ihre Einsätze' }}</h2>
                    <p>Alles, was ansteht — und was schon gelaufen ist.</p>
                </div>
                <div class="card">
                    <div class="leer">
                        Die Einsätze ziehen als Nächstes hier ein. Bis dahin kommen sie
                        wie gewohnt per WhatsApp.
                    </div>
                </div>
            </div>

            {{-- ---------------- DOKUMENTE ---------------- --}}
            <div class="pane" :class="tab === 'docs' && 'on'">
                <div class="greet">
                    <h2>Dokumente</h2>
                    <p>Was von {{ $duzen ? 'dir' : 'Ihnen' }} gebraucht wird — und was {{ $duzen ? 'dir' : 'Ihnen' }} gehört.</p>
                </div>

                <div>
                    <div class="sec-label">{{ $duzen ? 'Deine Nachweise' : 'Ihre Nachweise' }} <span class="count">{{ count($aufgaben) }}</span></div>
                    <div class="card" style="margin-top:11px">
                        @forelse ($aufgaben as $aufgabe)
                            <div class="task tap" wire:click="oeffneUpload('{{ $aufgabe['code'] }}')">
                                <span class="dot {{ $aufgabe['punkt'] }}"></span>
                                <div>
                                    <div class="t">{{ $aufgabe['label'] }}</div>
                                    <div class="s">{{ $aufgabe['text'] }}</div>
                                </div>
                                <span class="chev">›</span>
                            </div>
                        @empty
                            <div class="leer">Hier ist noch nichts hinterlegt.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ---------------- PROFIL ---------------- --}}
            <div class="pane" :class="tab === 'me' && 'on'">
                <div class="greet">
                    <h2>Meine Daten</h2>
                    <p>{{ $duzen ? 'Hier siehst du, was über dich hinterlegt ist.' : 'Hier sehen Sie, was über Sie hinterlegt ist.' }}</p>
                </div>

                {{--
                    Arbeitgeber-Pflichtfrage (Markus 24.09.2026) -- an ihr
                    haengt die Steuerklasse. Nutzt MainEmployerRequiredGuard,
                    dieselbe Regel wie im alten Portal, keine zweite.
                --}}
                <div>
                    <div class="sec-label">{{ $duzen ? 'Dein Arbeitgeber' : 'Ihr Arbeitgeber' }}</div>
                    <div class="card" style="margin-top:11px; padding:15px; display:flex; flex-direction:column; gap:13px">
                        <label class="feld">
                            <span class="n">{{ $duzen ? 'Sind wir dein Hauptarbeitgeber?' : 'Sind wir Ihr Hauptarbeitgeber?' }}</span>
                            <select wire:model.live="arbeitgeberIstHaupt">
                                <option value="">— bitte wählen —</option>
                                <option value="1">Ja</option>
                                <option value="0">Nein</option>
                            </select>
                        </label>

                        @if ($arbeitgeberIstHaupt === '0')
                            <label class="feld">
                                <span class="n">Wer ist es dann?</span>
                                {{-- maxlength = Spaltenbreite von rec_employees.other_employer, der harte
                                     Schutz sitzt im MainEmployerRequiredGuard. --}}
                                <input type="text" wire:model="arbeitgeberAnderer"
                                       maxlength="{{ \Platform\Recruiting\Support\MainEmployerRequiredGuard::MAX_OTHER_EMPLOYER }}"
                                       autocomplete="off">
                            </label>
                        @endif

                        @if ($arbeitgeberFehler !== '')
                            <div class="alert crit">
                                <span class="dot crit" style="margin-top:6px"></span>
                                <div class="txt">{{ $arbeitgeberFehler }}</div>
                            </div>
                        @endif

                        <button type="button" class="btn primary" wire:click="speichereArbeitgeber"
                                wire:loading.attr="disabled" wire:target="speichereArbeitgeber">
                            Speichern
                        </button>
                    </div>
                </div>

                <div>
                    @php
                        // Wie viele UNTERSCHIEDLICHE Gesellschaften es sind —
                        // zwei Datensaetze derselben Firma sind eine Dublette,
                        // keine zweite Anstellung.
                        $gesellschaften = $anstellungen
                            ->map(fn ($a) => trim((string) ($a->company ?? '')) ?: 'RheinGedeck')
                            ->unique()->count();
                        $titel = $anstellungen->count() > 1
                            ? ($duzen ? 'Deine Anstellungen' : 'Ihre Anstellungen')
                            : ($duzen ? 'Deine Anstellung' : 'Ihre Anstellung');
                    @endphp
                    <div class="sec-label">
                        {{ $titel }}
                        <span class="count">{{ $anstellungen->count() }}</span>
                    </div>
                    <div class="card" style="margin-top:11px">
                        @foreach ($anstellungen as $anstellung)
                            @php
                                $firma = trim((string) ($anstellung->company ?? '')) ?: 'RheinGedeck';
                                $nummer = trim((string) ($anstellung->personnel_number ?? '')) ?: 'noch keine Nummer';
                            @endphp
                            <div class="grouprow">
                                <div>
                                    <div class="n">{{ $firma }}</div>
                                    <div class="v">Personalnummer {{ $nummer }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($gesellschaften > 1)
                        <p class="fuss">
                            {{ $duzen ? 'Du arbeitest' : 'Sie arbeiten' }} für {{ $gesellschaften }} Gesellschaften.
                            {{ $duzen ? 'Deine' : 'Ihre' }} Nachweise gelten für alle —
                            {{ $duzen ? 'du musst' : 'Sie müssen' }} nichts doppelt hochladen.
                        </p>
                    @endif
                </div>

                <button type="button" class="btn" wire:click="logout">Abmelden</button>
            </div>

        </div>

            {{-- ---------------- REITER ---------------- --}}
            <nav class="tabbar" role="tablist" aria-label="Portalbereiche">
                <button class="tab" role="tab" type="button" @click="tab = 'start'"
                        :aria-selected="tab === 'start'" :class="tab === 'start' && 'on'">
                    <span class="ico">
                        <svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/></svg>
                        @if ($offen > 0)
                            <span class="badge">{{ $offen }}</span>
                        @endif
                    </span>Start
                </button>
                <button class="tab" role="tab" type="button" @click="tab = 'jobs'"
                        :aria-selected="tab === 'jobs'" :class="tab === 'jobs' && 'on'">
                    <span class="ico">
                        <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>
                    </span>Einsätze
                </button>
                <button class="tab" role="tab" type="button" @click="tab = 'docs'"
                        :aria-selected="tab === 'docs'" :class="tab === 'docs' && 'on'">
                    <span class="ico">
                        <svg viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>
                    </span>Dokumente
                </button>
                <button class="tab" role="tab" type="button" @click="tab = 'me'"
                        :aria-selected="tab === 'me'" :class="tab === 'me' && 'on'">
                    <span class="ico">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg>
                    </span>Profil
                </button>
            </nav>

            {{--
                Upload-Formular — Ueberlagerung ueber dem ganzen Bildschirm,
                unabhaengig vom offenen Reiter. $uploadCode ist Server-Zustand
                (gesetzt von oeffneUpload/speichereNachweis), deshalb bleibt
                das Zeigen/Verstecken serverseitig statt in Alpine.
            --}}
            @if ($uploadCode !== null)
                @php
                    $uploadEinleitung = $duzen
                        ? 'Fotografiere den Nachweis oder wähle eine Datei aus.'
                        : 'Fotografieren Sie den Nachweis oder wählen Sie eine Datei aus.';
                @endphp
                <div class="upload-overlay" wire:click.self="schliesseUpload">
                    <form class="upload-sheet" wire:submit="speichereNachweis">
                        <div class="upload-head">
                            <h3>{{ $uploadLabel }}</h3>
                            <button type="button" class="upload-close" wire:click="schliesseUpload" aria-label="Schließen">&times;</button>
                        </div>
                        <p class="upload-sub">{{ $uploadEinleitung }}</p>

                        @if ($uploadHatAblauf)
                            <label class="feld">
                                <span class="n">Gültig bis</span>
                                <input type="date" wire:model="uploadGueltigBis" required>
                            </label>
                        @endif

                        <label class="feld">
                            <span class="n">{{ $uploadHatRueckseite ? 'Vorderseite' : 'Foto oder Scan' }}</span>
                            {{--
                                Bewusst KEIN capture="environment" hier: das
                                Attribut zwingt auf dem Handy die Kamera auf
                                und nimmt die Auswahl "aus der Galerie" oder
                                "aus den Dateien" weg. Wer den Nachweis schon
                                fotografiert hat oder ihn als PDF im
                                Mailanhang bekommen hat, kaeme damit nicht
                                weiter (Kundenfeedback). Ohne das Attribut
                                bietet das Handy von sich aus Kamera UND
                                Galerie UND Dateien an — bitte NICHT wieder
                                einbauen.
                            --}}
                            <input type="file" wire:model="uploadDatei" accept="{{ $uploadAccept }}" required>
                        </label>

                        {{--
                            Zweiseitige Nachweise (Ausweis, Aufenthaltstitel,
                            Arbeitsgenehmigung, Fiktionsbescheinigung) bekommen
                            ein zweites, freiwilliges Feld. Bewusst OHNE
                            required: viele Ausweise haben nur eine bedruckte
                            Seite, und wer keine Rueckseite schickt, soll die
                            bereits hinterlegte nicht verlieren.
                        --}}
                        @if ($uploadHatRueckseite)
                            <label class="feld">
                                <span class="n">Rückseite (falls vorhanden)</span>
                                {{-- Kein capture="environment" — siehe Kommentar am Feld oben. --}}
                                <input type="file" wire:model="uploadDateiRueckseite" accept="{{ $uploadAccept }}">
                            </label>
                        @endif

                        <div wire:loading wire:target="uploadDatei,uploadDateiRueckseite" class="upload-status">Wird hochgeladen …</div>

                        @if ($uploadFehler !== '')
                            <div class="alert crit">
                                <span class="dot crit" style="margin-top:6px"></span>
                                <div class="txt">{{ $uploadFehler }}</div>
                            </div>
                        @endif

                        <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="speichereNachweis">
                            Speichern
                        </button>
                        <button type="button" class="btn" wire:click="schliesseUpload">Abbrechen</button>
                    </form>
                </div>
            @endif

    @endif
</div>
</div>
