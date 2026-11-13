{{--
    Zwei Marken einer VA-Zeile (Sheran 13.11.2026), Tabelle und Karte nutzen
    dieselbe Logik. Erwartet $event mit rausnehmen_count,
    nicht_angeschrieben_count, versand_begonnen (Index::mitHandlungsbedarf).
--}}
@php
    $rausnehmen = (int) ($event->rausnehmen_count ?? 0);
    // Nur wenn in der VA schon versendet wurde — sonst ist „nicht angeschrieben“ der Normalzustand.
    $nichtAngeschrieben = ($event->versand_begonnen ?? false) ? (int) ($event->nicht_angeschrieben_count ?? 0) : 0;
@endphp
@if ($nichtAngeschrieben > 0)
    <span class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-xs font-semibold text-violet-800"
          title="In dieser Veranstaltung wurden schon Bestätigungen verschickt — {{ $nichtAngeschrieben === 1 ? 'diese Einbuchung hat' : 'diese Einbuchungen haben' }} noch keine bekommen, zum Beispiel nach einem Tausch in ZAS.">{{ $nichtAngeschrieben }} nicht angeschrieben</span>
@endif
@if ($rausnehmen > 0)
    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800"
          title="Nach Ende der Eskalation zur Löschung gemeldet und in ZAS noch eingebucht — bitte in ZAS herausnehmen. Verschwindet, sobald die Einbuchung in ZAS weg ist oder der Haken „ZAS raus“ sitzt.">{{ $rausnehmen }} rausnehmen</span>
@endif
