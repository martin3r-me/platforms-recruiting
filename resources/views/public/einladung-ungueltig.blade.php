{{--
    Ungueltiger Einladungslink/-code (EinladungUngueltig, Status 404).
    Dieselbe Seite fuer JEDEN Grund — sie verraet nicht, ob es den Token gibt.
    Ohne Anrede-Entscheidung (die Person ist unbekannt): neutral formuliert.
    Optik wie konto-anlegen (layouts.portal bringt die CSS mit).
--}}
@component('recruiting::layouts.portal', ['title' => 'Link nicht mehr gültig · RheinGedeck'])
<div class="screen anmeldung konto">
    <div class="appbar">
        <div class="wordmark">Rhein<span>Gedeck</span></div>
    </div>
    <div class="scroll">
        <div class="greet">
            <h2>Dieser Link ist nicht mehr gültig</h2>
            <p>Vielleicht ist das Konto schon eingerichtet, oder der Link ist abgelaufen.</p>
        </div>
        <div class="card login">
            <p>Konto schon eingerichtet? Dann geht es hier zur Anmeldung.</p>
            <a href="{{ route('recruiting.public.konto') }}" class="btn primary">Zur Anmeldung</a>
        </div>
        <div class="card">
            <p>Code vertippt? Einfach noch einmal eingeben.</p>
            <a href="{{ route('recruiting.public.konto-anlegen-code') }}" class="btn">Code neu eingeben</a>
            <p class="hint">Klappt beides nicht, schreib dem HR-Team kurz per WhatsApp — dann kommt ein neuer Link.</p>
        </div>
    </div>
</div>
@endcomponent
