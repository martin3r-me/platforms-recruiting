@php
    // Eingebettet im Portal: ohne Vollbild-Hoehe und eigenen Hintergrund (Hausregel: Klasse vorberechnen).
    $crewKlasse = $eingebettet ? 'rg-crew eingebettet' : 'rg-crew';
@endphp
<div class="{{ $crewKlasse }}">
{{-- Head-Assets: Fonts (bunny.net, wie im Guest-Layout) + gescopte Styles.
     wire:ignore, damit Livewire sie bei Updates nicht anfasst. Alles unter
     .rg-crew gescoped, damit nichts ins Guest-Layout / andere Public-Views leakt. --}}
@include('recruiting::livewire.public._crew-stil')

@php
    $monthAbbr = ['01'=>'Jan','02'=>'Feb','03'=>'Mär','04'=>'Apr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Aug','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Dez'];
    $wdAbbr = ['So','Mo','Di','Mi','Do','Fr','Sa'];
    $avatarInitial = $firstName !== '' ? mb_strtoupper(mb_substr($firstName, 0, 1)) : '•';

    // „Sa 29.08." aus 'd.m.Y' (locale-unabhängig, kein Carbon-Locale nötig)
    $labelDate = function (string $datum) use ($wdAbbr) {
        try {
            $c = \Illuminate\Support\Carbon::createFromFormat('d.m.Y', $datum);
            return $wdAbbr[$c->dayOfWeek] . ' ' . mb_substr($datum, 0, 6); // "Sa 29.08."
        } catch (\Throwable $e) {
            return $datum;
        }
    };
@endphp

{{-- Eingebettet im Portal-Reiter „Einsätze“: Kopf und Gruss hat das Portal schon. --}}
@unless ($eingebettet)
<div class="appbar">
    <div class="wordmark">Rhein<span>Gedeck</span></div>
    @unless ($tokenInvalid)
        <div class="avatar">{{ $avatarInitial }}</div>
    @endunless
</div>
@endunless

<div class="wrap">
    @if ($tokenInvalid)
        <div class="state">
            <div class="bar"></div>
            <div class="inner">
                <h1>Link ungültig</h1>
                <p>Dieser Link ist nicht (mehr) gültig. Bitte melde dich bei deinem Ansprechpartner.</p>
            </div>
        </div>
    @elseif ($portalLocked)
        <div class="state">
            <div class="bar"></div>
            <div class="inner">
                <h1>Zugang gesperrt</h1>
                <p>Bitte kontaktiere deine Ansprechperson in der Personalabteilung (HR).</p>
            </div>
        </div>
    @else
        @php
            $groups = $this->eventGroups;
            $offen = 0;
            foreach ($groups as $g) {
                if (empty($g['all_confirmed'])) {
                    $offen++;
                }
            }
        @endphp

        <div class="greet">
            @unless ($eingebettet)
                <h1>Hey {{ $firstName }} 👋</h1>
            @endunless
            <p>
                @if ($groups === [])
                    Aktuell steht nichts an.
                @elseif ($offen === 0)
                    Alles bestätigt — danke dir!
                @elseif ($offen === 1)
                    Ein Einsatz wartet auf deine Zusage.
                @else
                    {{ $offen }} Einsätze warten auf deine Zusage.
                @endif
            </p>
        </div>

        @forelse ($groups as $group)
            @php
                $days = $group['days'];
                $dayCount = count($days);
                $first = $days[0] ?? null;
                $last = $days[$dayCount - 1] ?? null;
                $isConfirmed = !empty($group['all_confirmed']);
                $hasReconfirm = !empty($group['has_reconfirm']);
                $prevLbl = ($dayCount <= 1 && $first) ? ($first['previous_label'] ?? null) : null;

                // "When"-Zeile
                if ($dayCount <= 1) {
                    $whenLine = $first ? $labelDate($first['datum']) . ' · 1 Tag' : '';
                } else {
                    $whenLine = $labelDate($first['datum']) . ' – ' . $labelDate($last['datum']) . ' · ' . $dayCount . ' Tage';
                }

                $hasPanel = !empty($group['adresse']) || !empty($group['zusatz_ort'])
                    || !empty($group['dress_group']) || !empty($group['dress_hinweis']);
                $singleNote = ($dayCount <= 1 && $first) ? ($first['individual_note'] ?? null) : null;
                $singleNoteNew = ($dayCount <= 1 && $first) ? !empty($first['note_new']) : false;

                // Beacon-Label VORBERECHNEN in reinem PHP (Pitfall: an Wortzeichen
                // geklebte Blade-Direktiven kompilieren nicht -> if/else zerschossen).
                // Kunden-Feedback 2: KEINE errechnete Ankunftszeit mehr — nur
                // Schichtzeit + Vorlauf-Satz (Wording identisch zum WhatsApp-Template).
                $firstVon = $first['von'] ?? null;
                $firstBis = $first['bis'] ?? null;
                $vorlauf = (int) ($group['vorlauf_minuten'] ?? 0);
                $schichtStr = '';
                if ($firstVon) {
                    $schichtStr = $firstBis ? ('Schicht ' . $firstVon . '–' . $firstBis . ' Uhr') : ('Schicht ab ' . $firstVon . ' Uhr');
                }
                $vorlaufSatz = $vorlauf > 0 ? ('Bitte sei ' . $vorlauf . ' Minuten vor Dienstbeginn vor Ort!') : '';
                $beaconLbl = '';
                if ($first) {
                    // Taetigkeit gehoert in den Kasten (Kunde 07.09.): bei Eintages-
                    // Einsaetzen gibt es keine Tages-Zeilen, die sie sonst zeigen.
                    $firstTaetigkeit = trim((string) ($first['taetigkeit'] ?? ''));
                    $singleHead = ($firstTaetigkeit !== '' ? $firstTaetigkeit . ' · ' : '') . $schichtStr;
                    $head = $dayCount > 1
                        ? ('Erster Tag · ' . e($labelDate($first['datum'])) . ($schichtStr !== '' ? ' · ' . e($schichtStr) : ''))
                        : e($singleHead);
                    if ($head !== '') {
                        $beaconLbl .= '<b>' . $head . '</b>';
                    }
                    // KEIN <br> nach der Kopfzeile: das <b> ist display:block und
                    // bricht selbst um — br obendrauf ergab eine ganze Leerzeile.
                    if ($vorlaufSatz !== '') {
                        $beaconLbl .= e($vorlaufSatz);
                    }
                    if ($dayCount > 1) {
                        $beaconLbl .= ($vorlaufSatz !== '' ? '<br>' : '') . '<span class="sub">Die weiteren Tage haben eigene Zeiten — siehe unten.</span>';
                    }
                }
            @endphp

            <article class="evcard">
                <header class="evhead">
                    <div class="evhead-top">
                        <div>
                            <div class="kicker">Dein Einsatz</div>
                            <h3>{{ $group['name'] }}</h3>
                            @if ($whenLine !== '')
                                <div class="when">{{ $whenLine }}</div>
                            @endif
                        </div>
                        @if ($isConfirmed)
                            <span class="chip ok">✓ Bestätigt</span>
                        @elseif ($hasReconfirm)
                            <span class="chip warn">⟳ Erneut bestätigen</span>
                        @else
                            <span class="chip">Offen</span>
                        @endif
                    </div>

                    @if ($beaconLbl !== '')
                        <div class="beacon">
                            <div class="lbl">{!! $beaconLbl !!}</div>
                        </div>
                    @endif

                    @if ($hasReconfirm)
                        <div class="notice">
                            <b>Neue Zeiten!</b>
                            @if ($prevLbl)
                                Vorher: {{ $prevLbl }}.
                            @endif
                            Bitte bestätige den Einsatz erneut.
                        </div>
                    @endif
                </header>

                <div class="evbody">
                    @if ($dayCount > 1)
                        @foreach ($days as $day)
                            @php
                                $dd = mb_substr($day['datum'], 0, 2);
                                $mm = $monthAbbr[mb_substr($day['datum'], 3, 2)] ?? '';
                                // Vorberechnet in reinem PHP (Pitfall: an Wortzeichen geklebte
                                // Blade-Direktiven kompilieren nicht).
                                $dayPrev = $day['reconfirm'] ? ('⟳ geändert' . ($day['previous_label'] ? ' — vorher ' . $day['previous_label'] : '')) : '';
                            @endphp
                            <div class="day">
                                <div class="datebox"><div class="d">{{ $dd }}</div><div class="m">{{ $mm }}</div></div>
                                <div>
                                    <div class="l1">
                                        @if ($day['taetigkeit']){{ $day['taetigkeit'] }}@else Einsatz @endif
                                    </div>
                                    @if ($day['von'])
                                        <div class="l2">Schicht {{ $day['von'] }}@if ($day['bis'])–{{ $day['bis'] }}@endif Uhr</div>
                                    @endif
                                    @if ($dayPrev !== '')
                                        <div class="l2 warn">{{ $dayPrev }}</div>
                                    @endif
                                </div>
                            </div>
                            @if ($day['individual_note'])
                                @php $noteNewTag = !empty($day['note_new']); @endphp
                                <div class="hint {{ $noteNewTag ? 'isnew' : '' }}">
                                    <div class="h">
                                        Hinweis · {{ $labelDate($day['datum']) }}
                                        @if ($noteNewTag)
                                            <span class="newtag">NEU</span>
                                        @endif
                                    </div>
                                    <div class="b">{{ $day['individual_note'] }}</div>
                                </div>
                            @endif
                            @if ($day['dress'])
                                <div class="hint">
                                    <div class="h">{{ $day['dress']['heading'] }} · {{ $labelDate($day['datum']) }}</div>
                                    <div class="b">{{ $day['dress']['text'] }}</div>
                                </div>
                            @endif
                        @endforeach
                    @endif

                    @if ($hasPanel)
                        <div class="panels">
                            @if ($group['adresse'])
                                <div class="panel"><div class="h">Ort / Adresse</div><div class="b">{{ $group['adresse'] }}</div></div>
                            @endif
                            @if ($group['zusatz_ort'])
                                <div class="panel"><div class="h">Anfahrt / wo genau</div><div class="b">{{ $group['zusatz_ort'] }}</div></div>
                            @endif
                            @if ($group['dress_group'])
                                <div class="panel"><div class="h">{{ $group['dress_group']['heading'] }}</div><div class="b">{{ $group['dress_group']['text'] }}</div></div>
                            @endif
                            @if ($group['dress_hinweis'])
                                <div class="panel"><div class="h">Infos zur Veranstaltung</div><div class="b">{{ $group['dress_hinweis'] }}</div></div>
                            @endif
                        </div>
                    @endif

                    @if (!empty($group['attachments']))
                        <div class="panels" style="padding-top:{{ $hasPanel ? '0' : '14px' }}">
                            @foreach ($group['attachments'] as $att)
                                <a class="panel attach {{ $att['is_new'] ? 'isnew' : '' }}" href="{{ $att['url'] }}" target="_blank" rel="noopener">
                                    <div class="h">
                                        {{ count($group['attachments']) > 1 ? 'Dokument für dich' : 'Anhang für dich' }}
                                        @if ($att['is_new'])
                                            <span class="newtag">NEU</span>
                                        @endif
                                    </div>
                                    <div class="b">📎 {{ $att['name'] }}</div>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    @if ($singleNote)
                        <div class="hint {{ $singleNoteNew ? 'isnew' : '' }}">
                            <div class="h">
                                Hinweis für dich
                                @if ($singleNoteNew)
                                    <span class="newtag">NEU</span>
                                @endif
                            </div>
                            <div class="b">{{ $singleNote }}</div>
                        </div>
                    @endif
                </div>

                <div class="evfoot">
                    @if ($isConfirmed)
                        <div class="btn-done">✓ Bestätigt — danke!</div>
                    @else
                        <button type="button" class="btn-confirm" wire:click="confirm({{ $group['event_id'] }})" wire:loading.attr="disabled">
                            @if ($dayCount > 1) Alle {{ $dayCount }} Einsätze bestätigen @else Einsatz bestätigen @endif
                        </button>
                    @endif

                    @if ($group['contact_line'])
                        <div class="evcontact">Dein Ansprechpartner ist <b>{{ $group['contact_line'] }}</b></div>
                    @endif
                </div>
            </article>
        @empty
            <div class="empty">Aktuell liegen keine offenen Einsätze für dich vor.</div>
        @endforelse

        @if ($this->pastEventGroups !== [])
            <div>
                <button type="button" class="seclabel" wire:click="$toggle('showPast')">
                    <span>Vergangene Einsätze</span>
                    <span class="toggle">{{ $showPast ? 'ausblenden ▲' : 'anzeigen ▼' }}</span>
                </button>
                @if ($showPast)
                    <div class="pastlist">
                        @foreach ($this->pastEventGroups as $group)
                            <div class="pastrow">
                                <div class="n">{{ $group['name'] }}</div>
                                <div class="d">
                                    @foreach ($group['days'] as $day)
                                        <span>{{ $day['datum'] }}@if ($day['confirmed']) ✓@endif</span>
                                    @endforeach
                                </div>
                                @if (!empty($group['attachments']))
                                    <div class="d pastfiles">
                                        @foreach ($group['attachments'] as $att)
                                            <a href="{{ $att['url'] }}" target="_blank" rel="noopener">📎 {{ $att['name'] }}</a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
</div>
