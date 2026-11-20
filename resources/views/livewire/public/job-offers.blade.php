@php
    // Eingebettet im Portal-Reiter „Einsätze“ (Spec 2026-11-13 §6.5): ohne Vollbild-Hoehe und eigenen Hintergrund (Hausregel: Klasse vorberechnen).
    $crewKlasse = $eingebettet ? 'rg-crew eingebettet' : 'rg-crew';
@endphp
<div class="{{ $crewKlasse }}">
@include('recruiting::livewire.public._crew-stil')
<style>
/* Dringend: zwei gleich grosse Knoepfe unter der Schicht, gruen und rot (Markus 12.11.2026 —
   am kleinen „Kann nicht“-Link haben sich die Leute vertippt). Feste Farben statt Variablen:
   weisse Schrift muss im hellen und im dunklen Modus lesbar bleiben. */
.rg-crew .jobrow{flex-wrap:wrap}
.rg-crew .jobrow .side{display:flex; flex-direction:column; gap:6px; align-items:stretch}
.rg-crew .entscheid{flex-basis:100%; display:flex; flex-wrap:wrap; gap:8px}
/* nowrap + Mindestbreite: auf sehr schmalen Handys stehen die Knoepfe untereinander statt zweizeilig. */
.rg-crew .btn-ja, .rg-crew .btn-rot{flex:1 1 118px; white-space:nowrap; border:none; border-radius:var(--r); padding:12px 10px; color:#fff; font-family:var(--display); font-size:16px; letter-spacing:.01em; cursor:pointer; transition:filter .12s, transform .12s}
.rg-crew .btn-ja{background:#15803d}
.rg-crew .btn-rot{background:#b42318}
.rg-crew .btn-ja:hover, .rg-crew .btn-rot:hover{filter:brightness(1.07)}
.rg-crew .btn-ja:active, .rg-crew .btn-rot:active{transform:scale(.99)}
.rg-crew .absage{flex-basis:100%; margin-top:8px; background:var(--surface-2); border-radius:var(--r); padding:12px 13px}
.rg-crew .absage .frage{font-family:var(--display); font-size:16px; color:var(--ink)}
.rg-crew .absage .zaehler{margin-top:4px; font-size:12.5px; color:var(--ink-2)}
.rg-crew .absage .gruende{display:flex; flex-wrap:wrap; gap:6px; margin-top:10px}
/* Farben nur ueber Variablen (Janine 12.11.2026): festes Weiss ergab im Dunkelmodus helle Schrift auf weissem Grund. */
.rg-crew .absage .gruende label{border:1px solid var(--line-2); border-radius:999px; padding:6px 11px; font-size:13px; cursor:pointer; background:var(--surface); color:var(--ink)}
.rg-crew .absage .gruende label:has(input:checked){background:var(--brand); color:#fff; border-color:var(--brand)}
.rg-crew .absage .gruende input{position:absolute; opacity:0; width:0; height:0}
.rg-crew .absage textarea{width:100%; margin-top:10px; border:1px solid var(--line-2); border-radius:var(--r); padding:8px 10px; font:inherit; font-size:14px; resize:vertical; background:var(--surface); color:var(--ink)}
.rg-crew .absage textarea::placeholder{color:var(--ink-3)}
.rg-crew .absage .knoepfe{display:flex; flex-wrap:wrap; gap:8px; margin-top:10px}
.rg-crew .absage .btn-ja{flex:1 1 150px; padding:11px; font-size:15px}
.rg-crew .absage .btn-nein{flex:1 1 150px; white-space:nowrap; border:1px solid var(--crit); border-radius:var(--r); padding:11px; background:var(--surface); color:var(--crit); font-size:14px; font-weight:600; cursor:pointer}
.rg-crew .evhead .satz{margin-top:4px; font-size:13.5px; opacity:.92}
.rg-crew .evhead .bis{margin-top:6px; font-size:13px; font-weight:700}
.rg-crew .zusage-fenster{position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,.45)}
.rg-crew .zusage-box{width:100%; max-width:380px; background:var(--surface); color:var(--ink); border-radius:var(--r); padding:18px 18px 16px; box-shadow:0 10px 30px rgba(0,0,0,.25)}
.rg-crew .zusage-box .titel{font-family:var(--display); font-size:18px}
.rg-crew .zusage-box .ort{margin-top:2px; font-size:13px; color:var(--ink-2)}
.rg-crew .zusage-box dl{display:grid; grid-template-columns:auto 1fr; gap:4px 12px; margin:12px 0 0; font-size:14px}
.rg-crew .zusage-box dt{color:var(--ink-2)}
.rg-crew .zusage-box dd{margin:0; font-weight:600}
.rg-crew .zusage-box .satz{margin:14px 0 0; font-size:15px; font-weight:600}
.rg-crew .zusage-box .knoepfe{display:flex; flex-wrap:wrap; gap:8px; margin-top:14px}
.rg-crew .zusage-box .btn-ja{flex:1 1 120px; padding:11px; font-size:16px}
.rg-crew .zusage-box .btn-zurueck{flex:1 1 120px; border:1px solid var(--line-2); border-radius:var(--r); padding:11px; background:var(--surface); color:var(--ink); font-size:15px; cursor:pointer}
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

{{-- Eingebettet im Portal: Kopf und Gruss hat das Portal schon. --}}
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
        @php $groups = $this->angebote; @endphp

        @unless ($eingebettet)
            <div class="greet">
                <h1>Hallo {{ $firstName }}</h1>
                <p>Hier stehen Einsätze, für die wir noch Leute suchen — nur an Tagen, an denen du frei bist.</p>
            </div>
        @endunless

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
                                @if ($shift['ausgetragen'])
                                    <div class="l2">{{ $duzen ? 'Du bist für diese Schicht ausgetragen' : 'Sie sind für diese Schicht ausgetragen' }}</div>
                                @elseif ($shift['declined'])
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
                                @if (!$shift['dringend'])
                                    <div class="side">
                                        <button type="button" class="btn-confirm" wire:click="zusageFragen({{ $shift['offer_shift_id'] }})" wire:loading.attr="disabled">Ich bin dabei</button>
                                    </div>
                                @elseif (!$absageHier)
                                    {{-- Ist die Rueckfrage offen, stehen beide Antworten dort — sonst gaebe es „dabei“ doppelt. --}}
                                    <div class="entscheid">
                                        <button type="button" class="btn-ja" wire:click="zusageFragen({{ $shift['offer_shift_id'] }})" wire:loading.attr="disabled">Ich bin dabei</button>
                                        <button type="button" class="btn-rot" wire:click="absageOeffnen({{ $shift['offer_shift_id'] }})" wire:loading.attr="disabled">Ich kann nicht</button>
                                    </div>
                                @endif
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
                                    {{-- Der Hinweis oben auf der Seite ist bei langer Liste nicht im Blick („Bitte wähle einen Grund“). --}}
                                    @if ($hinweis !== '' && !$hinweisOk)
                                        <div class="notice">{{ $hinweis }}</div>
                                    @endif
                                    <div class="knoepfe">
                                        <button type="button" class="btn-nein" wire:click="absagen" wire:loading.attr="disabled">Ja, ich kann nicht</button>
                                        <button type="button" class="btn-ja" wire:click="zusageFragen({{ $shift['offer_shift_id'] }})" wire:loading.attr="disabled">Doch, ich bin dabei</button>
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
            @if ($eingebettet)
                <div class="empty">{{ $duzen ? 'Gerade gibt es keine freien Einsätze für dich.' : 'Gerade gibt es keine freien Einsätze für Sie.' }}</div>
            @elseif ($anfrageId !== null)
                <div class="empty">Für diese Anfrage gibt es gerade nichts mehr zu besetzen.</div>
            @else
                <div class="empty">Gerade gibt es keine offenen Einsätze für dich.</div>
            @endif
        @endforelse

        {{-- Spec 2026-11-20 Punkt 1 (Markus 19.11.2026): Zusage erst nach „OK“ im Fenster. --}}
        @php
            $zusageZeile = null;
            if ($zusageOffen !== null) {
                foreach ($groups as $g) {
                    foreach ($g['days'] as $zYmd => $zShifts) {
                        foreach ($zShifts as $zShift) {
                            if ($zShift['offer_shift_id'] === $zusageOffen) {
                                $zusageZeile = ['name' => $g['name'], 'ort' => $g['ort'] ?? null, 'tag' => $labelDate($zYmd), 'zeit' => $zShift['zeit'], 'taetigkeit' => $zShift['taetigkeit'] ?: 'Einsatz'];
                            }
                        }
                    }
                }
            }
            $verbindlichSatz = $duzen ? 'Mit der Zusage wirst du verbindlich für diesen Einsatz gebucht.' : 'Mit der Zusage werden Sie verbindlich für diesen Einsatz gebucht.';
        @endphp
        @if ($zusageZeile !== null)
            <div class="zusage-fenster" wire:key="zusage-fenster-{{ $zusageOffen }}" role="dialog" aria-modal="true" aria-labelledby="zusage-titel">
                <div class="zusage-box">
                    <div id="zusage-titel" class="titel">{{ $zusageZeile['name'] }}</div>
                    @if (!empty($zusageZeile['ort']))
                        <div class="ort">{{ $zusageZeile['ort'] }}</div>
                    @endif
                    <dl>
                        <dt>Tag</dt><dd>{{ $zusageZeile['tag'] }}</dd>
                        @if ($zusageZeile['zeit'] !== '')
                            <dt>Uhrzeit</dt><dd>{{ $zusageZeile['zeit'] }}</dd>
                        @endif
                        <dt>Tätigkeit</dt><dd>{{ $zusageZeile['taetigkeit'] }}</dd>
                    </dl>
                    <p class="satz">{{ $verbindlichSatz }}</p>
                    <div class="knoepfe">
                        <button type="button" class="btn-ja" wire:click="zusagen({{ $zusageOffen }})" wire:loading.attr="disabled">OK</button>
                        <button type="button" class="btn-zurueck" wire:click="zusageAbbrechen" wire:loading.attr="disabled">Abbrechen</button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
</div>
