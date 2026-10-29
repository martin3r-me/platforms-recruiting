<div class="rg-crew">
@include('recruiting::livewire.public._crew-stil')
<style>
/* „Kann nicht“ (nur Dringend) — bewusst klein unter dem grossen Knopf. */
.rg-crew .jobrow{flex-wrap:wrap}
.rg-crew .jobrow .side{display:flex; flex-direction:column; gap:6px; align-items:stretch}
.rg-crew .btn-link{background:none; border:none; padding:4px 0; color:var(--ink-2); font-size:12.5px; text-decoration:underline; cursor:pointer}
.rg-crew .absage{flex-basis:100%; margin-top:8px; background:var(--surface-2); border-radius:var(--r); padding:12px 13px}
.rg-crew .absage .frage{font-family:var(--display); font-size:16px; color:var(--ink)}
.rg-crew .absage .zaehler{margin-top:4px; font-size:12.5px; color:var(--ink-2)}
.rg-crew .absage .gruende{display:flex; flex-wrap:wrap; gap:6px; margin-top:10px}
.rg-crew .absage .gruende label{border:1px solid rgba(0,0,0,.15); border-radius:999px; padding:6px 11px; font-size:13px; cursor:pointer; background:#fff}
.rg-crew .absage .gruende label:has(input:checked){background:var(--brand); color:#fff; border-color:var(--brand)}
.rg-crew .absage .gruende input{position:absolute; opacity:0; width:0; height:0}
.rg-crew .absage textarea{width:100%; margin-top:10px; border:1px solid rgba(0,0,0,.15); border-radius:var(--r); padding:8px 10px; font:inherit; font-size:14px; resize:vertical}
.rg-crew .absage .knoepfe{display:flex; gap:8px; margin-top:10px}
.rg-crew .absage .btn-confirm{padding:11px; font-size:15px}
.rg-crew .absage .btn-nein{flex:1; border:1px solid rgba(0,0,0,.2); border-radius:var(--r); padding:11px; background:#fff; color:var(--ink); font-size:14px; cursor:pointer}
.rg-crew .evhead .satz{margin-top:4px; font-size:13.5px; opacity:.92}
.rg-crew .evhead .bis{margin-top:6px; font-size:13px; font-weight:700}
.rg-crew .fussnote{padding:8px 16px 12px; font-size:12px; color:var(--ink-2)}
</style>

@php
    $wdAbbr = ['So','Mo','Di','Mi','Do','Fr','Sa'];
    $avatarInitial = $firstName !== '' ? mb_strtoupper(mb_substr($firstName, 0, 1)) : '•';

    // „Sa 24.10." aus Y-m-d (locale-unabhängig)
    $labelDate = function (string $ymd) use ($wdAbbr) {
        $c = \Illuminate\Support\Carbon::parse($ymd);
        return $wdAbbr[$c->dayOfWeek] . ' ' . $c->format('d.m.');
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
        @php $groups = $this->angebote; @endphp

        <div class="greet">
            <h1>Hallo {{ $firstName }}</h1>
            <p>Hier stehen Einsätze, für die wir noch Leute suchen — nur an Tagen, an denen du frei bist.</p>
        </div>

        @if ($hinweis !== '')
            <div class="{{ $hinweisOk ? 'notice-ok' : 'notice' }}">{{ $hinweis }}</div>
        @endif

        @forelse ($groups as $group)
            <article class="evcard">
                <header class="evhead">
                    <div class="kicker">{{ $group['texte']['kicker'] }}</div>
                    <h3>{{ $group['name'] }}</h3>
                    @if (!empty($group['ort']))
                        <div class="when">{{ $group['ort'] }}</div>
                    @endif
                    @if ($group['texte']['satz'] !== null)
                        <div class="satz">{{ $group['texte']['satz'] }}</div>
                    @endif
                    @if ($group['texte']['antwort'] !== null)
                        <div class="bis">{{ $group['texte']['antwort'] }}</div>
                    @endif
                </header>

                @foreach ($group['days'] as $ymd => $shifts)
                    <div class="jobday">{{ $labelDate($ymd) }}</div>
                    @foreach ($shifts as $shift)
                        @php
                            $zeile = ($shift['zeit'] !== '' ? $shift['zeit'] . ' · ' : '') . ($shift['taetigkeit'] ?: 'Einsatz');
                        @endphp
                        @php
                            $absageHier = $absageOffen === $shift['offer_shift_id'];
                            $naechsteNr = $this->absagen60 + 1;
                        @endphp
                        <div class="jobrow">
                            <div>
                                <div class="l1">{{ $zeile }}</div>
                                @if ($shift['declined'])
                                    <div class="l2">Du hast abgesagt</div>
                                @elseif ($shift['tag_belegt'] && !$shift['accepted'])
                                    <div class="l2">Du hast für diesen Tag schon zugesagt</div>
                                @elseif ($shift['zuerst'] && !$shift['voll'] && !$shift['accepted'])
                                    <div class="l2"><span class="badge-frei">noch {{ $shift['frei'] }} frei</span></div>
                                @elseif ($shift['voll'])
                                    <div class="l2">schon voll — danke fürs Melden</div>
                                @endif
                            </div>
                            @if ($shift['accepted'])
                                <div class="btn-done">✓ Du bist dabei</div>
                            @elseif (!$shift['voll'] && !$shift['declined'] && !$shift['tag_belegt'])
                                <div class="side">
                                    <button type="button" class="btn-confirm" wire:click="zusagen({{ $shift['offer_shift_id'] }})" wire:loading.attr="disabled">Ich bin dabei</button>
                                    @if ($shift['dringend'] && !$absageHier)
                                        <button type="button" class="btn-link" wire:click="absageOeffnen({{ $shift['offer_shift_id'] }})">Kann nicht</button>
                                    @endif
                                </div>
                            @endif
                            @if ($absageHier && $shift['dringend'] && !$shift['accepted'] && !$shift['declined'])
                                <div class="absage" wire:key="absage-{{ $shift['offer_shift_id'] }}">
                                    <div class="frage">Wir zählen auf dich. Bist du sicher, dass du nicht kannst?</div>
                                    @if ($naechsteNr >= 2)
                                        <div class="zaehler">Das wäre deine {{ $naechsteNr }}. Absage in den letzten 60 Tagen.</div>
                                    @endif
                                    <div class="gruende">
                                        @foreach (\Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersAbsage::GRUENDE as $grundKey => $grundLabel)
                                            <label wire:key="grund-{{ $shift['offer_shift_id'] }}-{{ $grundKey }}"><input type="radio" name="absageGrund" value="{{ $grundKey }}" wire:model="absageGrund">{{ $grundLabel }}</label>
                                        @endforeach
                                    </div>
                                    <textarea rows="2" maxlength="200" placeholder="Magst du uns kurz sagen, warum? (freiwillig)" wire:model="absageNotiz"></textarea>
                                    <div class="knoepfe">
                                        <button type="button" class="btn-nein" wire:click="absagen" wire:loading.attr="disabled">Ja, ich kann nicht</button>
                                        <button type="button" class="btn-confirm" wire:click="zusagen({{ $shift['offer_shift_id'] }})" wire:loading.attr="disabled">Doch, ich bin dabei</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endforeach
                @if ($group['texte']['fussnote'] !== null)
                    <div class="fussnote">{{ $group['texte']['fussnote'] }}</div>
                @endif
            </article>
        @empty
            @if ($anfrageId !== null)
                <div class="empty">Für diese Anfrage gibt es gerade nichts mehr zu besetzen.</div>
            @else
                <div class="empty">Gerade gibt es keine offenen Einsätze für dich.</div>
            @endif
        @endforelse
    @endif
</div>
</div>
