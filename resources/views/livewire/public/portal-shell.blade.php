{{--
    Das neue Mitarbeiterportal. Markup folgt dem abgenommenen Entwurf
    (resources/mockups/crew-portal.html) — gleiche Klassen, gleiche Struktur,
    nur ohne den Telefonrahmen drumherum.

    Bewusst KEINE x-ui-Komponenten: das Portal bringt seine eigene CSS mit
    (siehe layouts/portal.blade.php). Blade-Direktiven immer in Blockform,
    nie an Wortzeichen geklebt — sonst kompilieren sie still nicht.
--}}
<div>
<div class="screen" x-data="{ tab: 'start' }">

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
                    $labelAusweis = $duzen
                        ? 'Die letzten 4 Ziffern deiner Ausweisnummer'
                        : 'Die letzten 4 Ziffern Ihrer Ausweisnummer';
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
                        <input type="text" wire:model="idLast4" required inputmode="numeric"
                               maxlength="4" autocomplete="off" placeholder="1234">
                    </label>

                    @if ($fehler !== '')
                        <div class="alert">
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
            $offeneBeschriftung = $offen === 1 ? '1 offene Aufgabe' : $offen . ' offene Aufgaben';
            $untertitel = $offen === 0
                ? ($duzen ? 'Bei dir ist alles vollständig. Danke!' : 'Bei Ihnen ist alles vollständig. Danke!')
                : ($duzen ? 'Es fehlt noch etwas von dir.' : 'Es fehlt noch etwas von Ihnen.');
            $offeneAufgaben = array_values(array_filter($aufgaben, fn ($a) => $a['offen']));
            $erledigt       = array_values(array_filter($aufgaben, fn ($a) => ! $a['offen']));
        @endphp

        <div class="appbar">
            <div class="wordmark">Rhein<span>Gedeck</span></div>
            <div class="avatar">{{ $initialen }}</div>
        </div>

        <div class="scroll">

            {{-- ---------------- START ---------------- --}}
            <div class="pane" :class="tab === 'start' && 'on'">
                <div class="greet">
                    <h2>Moin {{ $displayName }} 👋</h2>
                    <p>{{ $untertitel }}</p>
                </div>

                @if ($offen > 0)
                    <div>
                        <div class="sec-label">Das fehlt noch <span class="count">{{ $offen }}</span></div>
                        <div class="card" style="margin-top:11px">
                            @foreach ($offeneAufgaben as $aufgabe)
                                <div class="task">
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
                                <div class="task">
                                    <span class="dot ok"></span>
                                    <div>
                                        <div class="t">{{ $aufgabe['label'] }}</div>
                                        <div class="s">{{ $aufgabe['text'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- ---------------- EINSAETZE ---------------- --}}
            <div class="pane" :class="tab === 'jobs' && 'on'">
                <div class="greet">
                    <h2>Deine Einsätze</h2>
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
                    <div class="sec-label">Deine Nachweise <span class="count">{{ count($aufgaben) }}</span></div>
                    <div class="card" style="margin-top:11px">
                        @forelse ($aufgaben as $aufgabe)
                            <div class="task">
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

                <div>
                    <div class="sec-label">
                        {{ $anstellungen->count() > 1 ? 'Deine Anstellungen' : 'Deine Anstellung' }}
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
                    @if ($anstellungen->count() > 1)
                        <p class="fuss">
                            {{ $duzen ? 'Du arbeitest' : 'Sie arbeiten' }} für zwei Gesellschaften.
                            {{ $duzen ? 'Deine' : 'Ihre' }} Nachweise gelten für beide —
                            {{ $duzen ? 'du musst' : 'Sie müssen' }} nichts doppelt hochladen.
                        </p>
                    @endif
                </div>
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

    @endif
</div>
</div>
