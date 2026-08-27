<div class="rg-crew">
{{-- Head-Assets: Fonts (bunny.net, wie im Guest-Layout) + gescopte Styles.
     wire:ignore, damit Livewire sie bei Updates nicht anfasst. Alles unter
     .rg-crew gescoped, damit nichts ins Guest-Layout / andere Public-Views leakt. --}}
<div wire:ignore>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=chau-philomene-one:400|inter:400,500,600,700&display=swap" rel="stylesheet">
<style>
.rg-crew{
  --brand:#22a8b8; --brand-hover:#1e97a6; --brand-mid:#167582; --brand-deep:#0e535e;
  --brand-light:#90dce4; --brand-tint:#E4F5F7;
  --ink:#111827; --ink-2:#374151; --ink-3:#6b7280;
  --line:#e5e7eb; --line-2:#d1d5db;
  --surface:#ffffff; --surface-2:#f9fafb; --surface-3:#f3f4f6;
  --ground:#EDF1F2;
  --ok:#15803d; --ok-bg:#DCF3E4;
  --warn:#9a5b06; --warn-bg:#FBEEDA;
  --crit:#a4261d; --crit-bg:#FAE6E3;
  --r:8px; --r-card:10px;
  --display:"Chau Philomene One",ui-sans-serif,system-ui,sans-serif;
  --body:"Inter",ui-sans-serif,system-ui,-apple-system,sans-serif;
  position:relative; z-index:0;
  min-height:100vh; width:100%;
  background:var(--ground); color:var(--ink);
  font-family:var(--body); font-size:16px; line-height:1.55; -webkit-font-smoothing:antialiased;
}
@media (prefers-color-scheme: dark){.rg-crew{
  --brand:#22a8b8; --brand-hover:#3fbccb; --brand-mid:#3fbccb; --brand-deep:#0b3f48;
  --brand-light:#90dce4; --brand-tint:#0E2F35;
  --ink:#F3F5F6; --ink-2:#B6C0C4; --ink-3:#8A959A;
  --line:#243237; --line-2:#32444A;
  --surface:#131E21; --surface-2:#18262A; --surface-3:#1F2F34;
  --ground:#0A1214;
  --ok:#4ec288; --ok-bg:#123024; --warn:#dda85e; --warn-bg:#31260F; --crit:#eb8279; --crit-bg:#331A18;
}}
.rg-crew *{box-sizing:border-box}
.rg-crew h1,.rg-crew h2,.rg-crew h3{font-family:var(--display); font-weight:400; margin:0; line-height:1.12; text-wrap:balance}
.rg-crew :focus-visible{outline:2px solid var(--brand); outline-offset:2px; border-radius:6px}
@media (prefers-reduced-motion:reduce){.rg-crew *{animation:none!important;transition:none!important}}

.rg-crew .appbar{position:sticky; top:0; z-index:5; display:flex; align-items:center; justify-content:space-between;
  padding:12px 18px; background:var(--surface); border-bottom:1px solid var(--line)}
.rg-crew .wordmark{font-family:var(--display); font-size:18px; letter-spacing:.01em; color:var(--ink)}
.rg-crew .wordmark span{color:var(--brand)}
.rg-crew .avatar{width:32px; height:32px; border-radius:50%; background:var(--brand); color:#fff; display:grid; place-items:center; font-size:12.5px; font-weight:700}

.rg-crew .wrap{max-width:460px; margin:0 auto; padding:20px 16px 48px; display:flex; flex-direction:column; gap:18px}

.rg-crew .greet h1{font-size:27px}
.rg-crew .greet p{color:var(--ink-2); font-size:14px; margin-top:3px}

.rg-crew .evcard{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); overflow:hidden; box-shadow:0 1px 2px rgba(17,24,39,.04)}
.rg-crew .evhead{background:var(--brand-deep); color:#fff; padding:17px 16px 16px}
.rg-crew .evhead-top{display:flex; justify-content:space-between; gap:10px; align-items:flex-start}
.rg-crew .evhead .kicker{font-size:10.5px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; opacity:.75}
.rg-crew .evhead h3{font-size:22px; margin-top:7px; letter-spacing:.01em}
.rg-crew .evhead .when{font-size:13.5px; opacity:.82; margin-top:4px; font-variant-numeric:tabular-nums}
.rg-crew .beacon{margin-top:13px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:var(--r); padding:11px 13px; display:flex; align-items:center; gap:12px}
.rg-crew .beacon .lbl{font-size:13.5px; opacity:.95; line-height:1.4}
.rg-crew .beacon .lbl b{display:block; font-family:var(--display); font-weight:400; font-size:18px; letter-spacing:.01em; color:var(--brand-light); margin-bottom:4px}
.rg-crew .beacon .lbl .sub{opacity:.72}

.rg-crew .evbody{padding:4px 0 0}
.rg-crew .day{display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--line)}
.rg-crew .datebox{width:46px; flex:none; text-align:center; border-radius:var(--r); background:var(--brand-tint); padding:5px 0; line-height:1.1}
.rg-crew .datebox .d{font-family:var(--display); font-size:19px; color:var(--brand-mid); font-variant-numeric:tabular-nums}
.rg-crew .datebox .m{font-size:10px; font-weight:700; text-transform:uppercase; color:var(--brand-mid); letter-spacing:.06em}
.rg-crew .day .l1{font-size:14px; font-weight:600}
.rg-crew .day .l2{font-size:12.5px; color:var(--ink-2); font-variant-numeric:tabular-nums}

.rg-crew .panels{padding:14px 16px 4px; display:flex; flex-direction:column; gap:10px}
.rg-crew .panel{background:var(--surface-2); border-radius:var(--r); padding:11px 13px}
.rg-crew .panel .h{font-size:10.5px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--ink-3)}
.rg-crew .panel .b{font-size:13.5px; margin-top:4px; line-height:1.45; white-space:pre-line}

.rg-crew .hint{margin:10px 16px 0; background:var(--warn-bg); border-radius:var(--r); padding:11px 13px}
.rg-crew .hint .h{font-size:10.5px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--warn)}
.rg-crew .hint .b{font-size:13.5px; margin-top:3px; color:var(--warn); line-height:1.4; white-space:pre-line}

.rg-crew .evfoot{padding:14px 16px 16px}
.rg-crew .btn-confirm{width:100%; border:none; border-radius:var(--r); padding:14px; background:var(--brand); color:#fff; font-family:var(--display); font-size:17px; letter-spacing:.01em; cursor:pointer; transition:filter .12s, transform .12s}
.rg-crew .btn-confirm:hover{filter:brightness(1.05)} .rg-crew .btn-confirm:active{transform:scale(.99)}
.rg-crew .btn-done{width:100%; text-align:center; border-radius:var(--r); padding:13px; background:var(--ok-bg); color:var(--ok); font-family:var(--display); font-size:16px; letter-spacing:.01em}
.rg-crew .evcontact{font-size:12.5px; color:var(--ink-2); text-align:center; margin-top:11px}
.rg-crew .evcontact b{color:var(--ink); font-weight:600}

.rg-crew .chip{display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; flex:none}
.rg-crew .evhead .chip{background:rgba(255,255,255,.16); color:#fff}
.rg-crew .evhead .chip.ok{background:rgba(220,243,228,.22); color:#dcfce7}

.rg-crew .empty{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); padding:26px 18px; text-align:center; color:var(--ink-2); font-size:14px}

.rg-crew .seclabel{font-size:11px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--ink-3); display:flex; align-items:center; justify-content:space-between; gap:8px; width:100%; background:none; border:none; padding:6px 2px; cursor:pointer}
.rg-crew .seclabel .toggle{font-size:11px; letter-spacing:0; color:var(--ink-3)}
.rg-crew .pastlist{margin-top:8px; background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); overflow:hidden}
.rg-crew .pastrow{padding:11px 14px; border-bottom:1px solid var(--line)}
.rg-crew .pastrow:last-child{border-bottom:none}
.rg-crew .pastrow .n{font-size:14px; font-weight:600; color:var(--ink-2)}
.rg-crew .pastrow .d{margin-top:2px; display:flex; flex-wrap:wrap; gap:4px 12px; font-size:12px; color:var(--ink-3); font-variant-numeric:tabular-nums}

.rg-crew .state{background:var(--surface); border:1px solid var(--line); border-radius:var(--r-card); overflow:hidden}
.rg-crew .state .bar{height:5px; background:var(--crit)}
.rg-crew .state .inner{padding:22px 18px; text-align:center}
.rg-crew .state h1{font-size:22px; color:var(--ink)}
.rg-crew .state p{margin-top:8px; color:var(--ink-2); font-size:14px; line-height:1.5}
</style>
</div>

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

<div class="appbar">
    <div class="wordmark">Rhein<span>Gedeck</span></div>
    @unless ($tokenInvalid)
        <div class="avatar">{{ $avatarInitial }}</div>
    @endunless
</div>

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
            <h1>Moin {{ $firstName }} 👋</h1>
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

                // "When"-Zeile
                if ($dayCount <= 1) {
                    $whenLine = $first ? $labelDate($first['datum']) . ' · 1 Tag' : '';
                } else {
                    $whenLine = $labelDate($first['datum']) . ' – ' . $labelDate($last['datum']) . ' · ' . $dayCount . ' Tage';
                }

                $hasPanel = !empty($group['adresse']) || !empty($group['zusatz_ort']) || !empty($group['kleidung']);
                $singleNote = ($dayCount <= 1 && $first) ? ($first['individual_note'] ?? null) : null;

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
                    $head = $dayCount > 1
                        ? ('Erster Tag · ' . e($labelDate($first['datum'])) . ($schichtStr !== '' ? ' · ' . e($schichtStr) : ''))
                        : e($schichtStr);
                    if ($head !== '') {
                        $beaconLbl .= '<b>' . $head . '</b>';
                    }
                    if ($vorlaufSatz !== '') {
                        $beaconLbl .= ($beaconLbl !== '' ? '<br>' : '') . e($vorlaufSatz);
                    }
                    if ($dayCount > 1) {
                        $beaconLbl .= '<br><span class="sub">Die weiteren Tage haben eigene Zeiten — siehe unten.</span>';
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
                        @else
                            <span class="chip">Offen</span>
                        @endif
                    </div>

                    @if ($beaconLbl !== '')
                        <div class="beacon">
                            <div class="lbl">{!! $beaconLbl !!}</div>
                        </div>
                    @endif
                </header>

                <div class="evbody">
                    @if ($dayCount > 1)
                        @foreach ($days as $day)
                            @php
                                $dd = mb_substr($day['datum'], 0, 2);
                                $mm = $monthAbbr[mb_substr($day['datum'], 3, 2)] ?? '';
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
                                </div>
                            </div>
                            @if ($day['individual_note'])
                                <div class="hint">
                                    <div class="h">Hinweis · {{ $labelDate($day['datum']) }}</div>
                                    <div class="b">{{ $day['individual_note'] }}</div>
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
                            @if ($group['kleidung'])
                                <div class="panel"><div class="h">Kleidung / Infos</div><div class="b">{{ $group['kleidung'] }}</div></div>
                            @endif
                        </div>
                    @endif

                    @if ($singleNote)
                        <div class="hint">
                            <div class="h">Hinweis für dich</div>
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
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
</div>
