<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">Veranstaltungen</h1>
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="showPast" class="rounded border-gray-300">
            Vergangene anzeigen
        </label>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Zeitraum</th>
                    <th class="px-4 py-2 font-medium">Veranstaltung</th>
                    <th class="px-4 py-2 font-medium">Ort</th>
                    <th class="px-4 py-2 font-medium">Einsatzfirma</th>
                    <th class="px-4 py-2 font-medium">Einbuchungen</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($events as $event)
                    @php
                        $open = $event->assignments_count - $event->matched_count;
                    @endphp
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $event->starts_on?->format('d.m.Y') ?? '—' }}
                            @if ($event->ends_on && $event->starts_on && !$event->ends_on->isSameDay($event->starts_on))
                                – {{ $event->ends_on->format('d.m.Y') }}
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $event->name ?? $event->einsatz_ref }}</td>
                        <td class="px-4 py-2">{{ $event->ort ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $event->einsatzfirma ?? '—' }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $event->assignments_count }} gesamt · {{ $event->matched_count }} zugeordnet
                            @if ($open > 0)
                                <span class="ml-1 rounded bg-orange-50 px-1.5 py-0.5 text-xs text-orange-600">{{ $open }} offen</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('recruiting.dispo.events.show', ['eventId' => $event->id]) }}" class="text-blue-600 hover:underline">Ansehen</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                            Noch keine Veranstaltungen verarbeitet. Sie entstehen automatisch aus den ZAS-Lieferungen.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
