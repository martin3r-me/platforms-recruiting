{{-- Eine Schulungszeile der Teamleiter-Liste. Eigenes Partial, damit die
     offene und die eingeklappte Gruppe garantiert gleich aussehen. --}}
@php
    $start = $interview->starts_at;
    $istVorbei = \Platform\Recruiting\Support\InterviewPastness::isPast(
        $interview->starts_at,
        $interview->ends_at,
        now(),
    );
    // Vorbei heisst: hier wird jetzt bewertet. Deshalb bekommt genau diese
    // Zeile den auffaelligen Knopf, nicht die kommende.
    $btnVariant = $istVorbei ? 'primary' : 'secondary-outline';
@endphp
<tr class="hover:bg-gray-50" wire:key="interview-{{ $interview->id }}">
    <td class="px-4 py-3 font-medium text-[var(--ui-secondary)]">
        {{ $interview->interviewType?->name ?? 'Schulung' }}
    </td>
    <td class="px-4 py-3 whitespace-nowrap">
        @if($start)
            {{ $start->format('d.m.Y') }}
            <span class="text-[var(--ui-muted)]">{{ $start->format('H:i') }}</span>
        @else
            <span class="text-[var(--ui-muted)]">—</span>
        @endif
    </td>
    <td class="px-4 py-3">{{ $interview->location ?? '—' }}</td>
    <td class="px-4 py-3">{{ $interview->teilnehmer_count }}</td>
    <td class="px-4 py-3 text-right">
        <x-ui-button
            :variant="$btnVariant"
            size="xs"
            :href="route('recruiting.training-review.show', $interview->id)"
            wire:navigate
        >
            Öffnen
        </x-ui-button>
    </td>
</tr>
