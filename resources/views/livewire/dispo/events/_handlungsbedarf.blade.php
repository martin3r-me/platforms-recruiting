{{--
    Zwei Marken einer VA-Zeile (Sheran 13.11.2026), Tabelle und Karte nutzen
    dieselbe Logik. Erwartet $event mit rausnehmen_count und
    nicht_angeschrieben_count (Index::mitHandlungsbedarf).
--}}
@php
    $rausnehmen = (int) ($event->rausnehmen_count ?? 0);
    $nichtAngeschrieben = (int) ($event->nicht_angeschrieben_count ?? 0);
@endphp
@if ($nichtAngeschrieben > 0)
    <span class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-xs font-semibold text-violet-800"
          title="Für diesen Tag wurden schon Bestätigungen verschickt — {{ $nichtAngeschrieben === 1 ? 'diese Einbuchung hat' : 'diese Einbuchungen haben' }} noch keine bekommen, zum Beispiel nach einem Tausch in ZAS.">{{ $nichtAngeschrieben }} nicht angeschrieben</span>
@endif
@if ($rausnehmen > 0)
    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800"
          title="Nach Ende der Eskalation zur Löschung gemeldet und in ZAS noch eingebucht (ab heute) — bitte in ZAS herausnehmen. Verschwindet, sobald die Einbuchung in ZAS weg ist oder der Haken „ZAS raus“ sitzt.">{{ $rausnehmen }} rausnehmen</span>
@endif
