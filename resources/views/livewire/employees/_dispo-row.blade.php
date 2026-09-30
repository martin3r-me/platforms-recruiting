{{-- Eine Einbuchung in der Mitarbeiter-Akte (Kunde 26.09.). Erwartet $row aus
     Employees\Show::dispoAssignments(). Gleiche Begriffe wie auf der VA-Seite. --}}
@php
    $statusClass = [
        'confirmed' => 'bg-green-100 text-green-800',
        'declined'  => 'bg-red-100 text-red-800',
        'sent'      => 'bg-blue-50 text-blue-700',
        'gone'      => 'bg-gray-100 text-gray-500',
        'open'      => 'bg-orange-50 text-orange-700',
    ][$row['status']] ?? 'bg-gray-100 text-gray-700';
@endphp
<div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm">
    <span class="w-32 shrink-0 font-medium tabular-nums text-gray-900">{{ $row['datum'] }}</span>
    <span class="w-28 shrink-0 tabular-nums text-gray-500">{{ $row['zeit'] }}</span>
    <span class="min-w-0 flex-1">
        <span class="text-gray-900">{{ $row['event_name'] }}</span>
        @if ($row['taetigkeit'] !== '')
            <span class="text-gray-500"> · {{ $row['taetigkeit'] }}</span>
        @endif
        @if ($row['filiale'] !== '')
            <span class="text-gray-400"> · {{ $row['filiale'] }}</span>
        @endif
    </span>
    <span class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $row['status_label'] }}</span>
    @if ($row['event_id'])
        <a href="{{ route('recruiting.dispo.events.show', ['eventId' => $row['event_id']]) }}"
           class="shrink-0 text-xs font-semibold text-blue-600 hover:underline">Ansehen</a>
    @endif
</div>
