{{--
    Eine Zeile der Termin-Uebersicht.

    Als Teilstueck ausgelagert, weil die Tabelle sie an ZWEI Stellen rendert
    (anstehende Termine offen, vergangene eingeklappt). Zweimal derselbe Block in
    einer Datei wuerde beim naechsten Edit auseinanderdriften.

    Erwartet: $interview (RecInterview, mit interviewType/position/bookings geladen)
--}}
@php
    $takenCount = $interview->bookings->filter->takes_seat->count();
    $standbyCount = $interview->bookings->filter->is_standby->count();

    // Bezeichnung fuer die Loesch-Rueckfrage: Titel, sonst die Terminart, sonst
    // ein neutraler Platzhalter — ein Termin ohne Titel ist erlaubt, und
    // „Termin ‚' wirklich loeschen?" waere die Rueckfrage, die nichts sagt.
    $loeschName = $interview->title ?: ($interview->interviewType->name ?? 'ohne Titel');
    $loeschFrage = 'Termin „' . $loeschName . '" am ' . $interview->starts_at->format('d.m.Y')
        . ' wirklich löschen?';
@endphp
<tr class="hover:bg-gray-50">
    <td class="px-4 py-3">
        <div class="font-medium">{{ $interview->starts_at->format('d.m.Y') }}</div>
        <div class="text-xs text-[var(--ui-muted)]">
            {{ $interview->starts_at->format('H:i') }}
            @if($interview->ends_at)
                — {{ $interview->ends_at->format('H:i') }}
            @endif
        </div>
    </td>
    <td class="px-4 py-3">
        @if($interview->interviewType)
            <x-ui-badge variant="secondary" size="xs">{{ $interview->interviewType->name }}</x-ui-badge>
        @else
            <span class="text-[var(--ui-muted)]">—</span>
        @endif
    </td>
    <td class="px-4 py-3 font-medium">
        {{ $interview->title ?? '—' }}
        <x-ui-badge variant="secondary" size="xs" class="ml-1">{{ strtoupper($interview->language ?? 'de') }}</x-ui-badge>
    </td>
    <td class="px-4 py-3">{{ $interview->position->title ?? '—' }}</td>
    <td class="px-4 py-3">{{ $interview->location ?? '—' }}</td>
    <td class="px-4 py-3">
        <span class="font-medium">{{ $takenCount }}</span>
        @if($interview->max_participants)
            <span class="text-[var(--ui-muted)]">/ {{ $interview->max_participants }}</span>
        @endif
        @if($standbyCount > 0)
            <span class="text-amber-600">(+{{ $standbyCount }} Standby)</span>
        @endif
    </td>
    <td class="px-4 py-3">
        @if($interview->reminder_wa_template_id && $interview->reminder_hours_before)
            <x-ui-badge variant="info" size="xs">WA {{ $interview->reminder_hours_before }}h</x-ui-badge>
        @else
            <span class="text-[var(--ui-muted)]">—</span>
        @endif
    </td>
    <td class="px-4 py-3">
        @if($interview->status === 'planned')
            <x-ui-badge variant="warning" size="xs">Geplant</x-ui-badge>
        @elseif($interview->status === 'confirmed')
            <x-ui-badge variant="info" size="xs">Bestätigt</x-ui-badge>
        @elseif($interview->status === 'cancelled')
            <x-ui-badge variant="danger" size="xs">Abgesagt</x-ui-badge>
        @elseif($interview->status === 'completed')
            <x-ui-badge variant="success" size="xs">Abgeschlossen</x-ui-badge>
        @else
            <x-ui-badge variant="secondary" size="xs">{{ $interview->status }}</x-ui-badge>
        @endif
    </td>
    <td class="px-4 py-3">
        <div class="flex gap-2">
            <a href="{{ route('recruiting.interview-bookings.index', $interview->id) }}" wire:navigate>
                <x-ui-button variant="secondary-outline" size="xs">
                    Buchungen
                </x-ui-button>
            </a>
            <x-ui-button variant="secondary-outline" size="xs" wire:click="openEditModal({{ $interview->id }})">
                Bearbeiten
            </x-ui-button>
            <x-ui-button variant="danger-outline" size="xs" wire:click="delete({{ $interview->id }})" wire:confirm="{{ $loeschFrage }}">
                Löschen
            </x-ui-button>
        </div>
    </td>
</tr>
