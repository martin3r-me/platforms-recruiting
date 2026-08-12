<div class="p-6 space-y-6">
    @php
        $event = $this->event;
        $statusLabels = [0 => 'Angebot', 1 => 'Auftrag', 2 => 'Beendet', 3 => 'Storno'];
        $statusClasses = [
            0 => 'bg-gray-100 text-gray-700',
            1 => 'bg-green-100 text-green-800',
            2 => 'bg-blue-50 text-blue-700',
            3 => 'bg-red-100 text-red-800',
        ];
    @endphp

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">{{ $event->name ?? $event->einsatz_ref }}</h1>
            <p class="text-sm text-gray-500">
                {{ $event->einsatz_ref }}
                @if ($event->starts_on) · {{ $event->starts_on->format('d.m.Y') }}@endif
                @if ($event->ends_on && $event->starts_on && !$event->ends_on->isSameDay($event->starts_on)) – {{ $event->ends_on->format('d.m.Y') }}@endif
                @if ($event->einsatzfirma) · {{ $event->einsatzfirma }}@endif
            </p>
        </div>
        <a href="{{ route('recruiting.dispo.events.index') }}" class="text-sm text-blue-600 hover:underline">← Zurück zur Liste</a>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm font-medium text-gray-500">Ort / Venue</div>
            <div class="mt-1 whitespace-pre-line text-sm">{{ $event->venue_text ?? $event->ort ?? '—' }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm font-medium text-gray-500">Anfahrt / Dresscode</div>
            <div class="mt-1 whitespace-pre-line text-sm">{{ $event->anfahrt ?? 'Anfahrt: folgt von ZAS' }}</div>
            <div class="mt-1 whitespace-pre-line text-sm">{{ $event->dresscode ?? 'Dresscode: folgt von ZAS' }}</div>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-4 py-3 font-medium">
            Einbuchungen ({{ $event->assignments->count() }})
            {{-- Step 2 (Bestaetigungs-Flow): hier kommt der Sende-Button hin --}}
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Datum</th>
                    <th class="px-4 py-2 font-medium">Zeit</th>
                    <th class="px-4 py-2 font-medium">Tätigkeit</th>
                    <th class="px-4 py-2 font-medium">Mitarbeiter</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($event->assignments as $assignment)
                    <tr class="{{ $assignment->missing_since ? 'opacity-50' : '' }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $assignment->datum->format('d.m.Y') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $assignment->von ?? '—' }}@if ($assignment->bis)–{{ $assignment->bis }}@endif</td>
                        <td class="px-4 py-2">{{ $assignment->taetigkeit ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($assignment->employee)
                                <a href="{{ route('recruiting.employees.show', $assignment->employee->id) }}" class="text-blue-600 hover:underline">
                                    {{ $assignment->employee->first_name }} {{ $assignment->employee->last_name }}
                                </a>
                            @else
                                <span class="rounded bg-orange-50 px-1.5 py-0.5 text-xs text-orange-600">PNr unbekannt: {{ $assignment->pnr_raw }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <span class="rounded px-1.5 py-0.5 text-xs {{ $statusClasses[$assignment->status_id] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ $statusLabels[$assignment->status_id] ?? $assignment->status_id }}
                            </span>
                            @if ($assignment->missing_since)
                                <span class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600" title="Fehlt seit {{ $assignment->missing_since->format('d.m.Y H:i') }} im ZAS-Vollbestand">verschwunden</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">Keine Einbuchungen.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
