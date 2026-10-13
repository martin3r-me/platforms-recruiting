<div class="rg-crew">
@include('recruiting::livewire.public._crew-stil')

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
                    <div class="kicker">Wir suchen Verstärkung</div>
                    <h3>{{ $group['name'] }}</h3>
                    @if (!empty($group['ort']))
                        <div class="when">{{ $group['ort'] }}</div>
                    @endif
                </header>

                @foreach ($group['days'] as $ymd => $shifts)
                    <div class="jobday">{{ $labelDate($ymd) }}</div>
                    @foreach ($shifts as $shift)
                        @php
                            $zeile = ($shift['zeit'] !== '' ? $shift['zeit'] . ' · ' : '') . ($shift['taetigkeit'] ?: 'Einsatz');
                        @endphp
                        <div class="jobrow">
                            <div>
                                <div class="l1">{{ $zeile }}</div>
                                @if ($shift['zuerst'] && !$shift['voll'] && !$shift['accepted'])
                                    <div class="l2"><span class="badge-frei">noch {{ $shift['frei'] }} frei</span></div>
                                @elseif ($shift['voll'])
                                    <div class="l2">schon voll — danke fürs Melden</div>
                                @endif
                            </div>
                            @if ($shift['accepted'])
                                <div class="btn-done">Du bist dabei — wir melden uns.</div>
                            @elseif (!$shift['voll'])
                                <button type="button" class="btn-confirm" wire:click="zusagen({{ $shift['offer_shift_id'] }})" wire:loading.attr="disabled">Ich bin dabei</button>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </article>
        @empty
            <div class="empty">Gerade gibt es keine offenen Einsätze für dich.</div>
        @endforelse
    @endif
</div>
</div>
