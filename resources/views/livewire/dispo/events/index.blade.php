<div class="p-4 lg:p-6 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">Veranstaltungen</h1>
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="showPast" class="rounded border-gray-300">
            Vergangene anzeigen
        </label>
    </div>

    <div class="flex flex-wrap items-end gap-4 rounded-lg border border-gray-200 bg-white p-4">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Datum von</label>
            <input type="date" wire:model.live="dateFrom" class="rounded border border-gray-300 px-2 py-1 text-sm">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Datum bis</label>
            <input type="date" wire:model.live="dateTo" class="rounded border border-gray-300 px-2 py-1 text-sm">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Filiale</label>
            <select wire:model.live="filialeFilter" class="rounded border border-gray-300 px-2 py-1 text-sm">
                <option value="">Alle</option>
                @foreach ($this->filialeOptions as $filialeNr => $filialeLabel)
                    <option value="{{ $filialeNr }}">{{ $filialeLabel }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Mobil: eine Karte pro VA, ganze Flaeche tippbar — Weg zum Chat in zwei Tippern.
         Desktop (lg:) rendert unveraendert die Tabelle darunter. --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($events as $event)
            @php
                $open = $event->assignments_count - $event->confirmed_count;
                $unread = $unreadByEvent[$event->id] ?? 0;
                $range = $event->starts_on?->format('d.m.Y') ?? '—';
                if ($event->ends_on && $event->starts_on && !$event->ends_on->isSameDay($event->starts_on)) {
                    $range .= ' – ' . $event->ends_on->format('d.m.Y');
                }
                $subParts = array_filter([$event->filiale_label, $event->einsatzfirma]);
            @endphp
            <a href="{{ route('recruiting.dispo.events.show', ['eventId' => $event->id]) }}"
               class="block rounded-xl border border-gray-200 bg-white p-4 shadow-sm active:bg-gray-50">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 tabular-nums">{{ $range }}</div>
                        <div class="mt-0.5 flex items-center gap-1.5 text-[15px] font-semibold text-gray-900">
                            <span class="truncate">{{ $event->name ?? $event->einsatz_ref }}</span>
                            @if ($event->has_failed_send || $event->alarm_failed)
                                <span class="shrink-0 text-red-600" title="Mindestens eine Zustellung fehlgeschlagen">⚠</span>
                            @endif
                        </div>
                        @if ($subParts !== [])
                            <div class="mt-0.5 truncate text-xs text-gray-500">{{ implode(' · ', $subParts) }}</div>
                        @endif
                    </div>
                    @if ($unread > 0)
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white">💬 {{ $unread }}</span>
                    @endif
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-gray-600">
                    <span class="tabular-nums">{{ $event->assignments_count }} gesamt · {{ $event->confirmed_count }} bestätigt</span>
                    @if ($open > 0)
                        <span class="rounded bg-orange-50 px-1.5 py-0.5 font-semibold text-orange-600">{{ $open }} offen</span>
                    @endif
                </div>
            </a>
        @empty
            @php
                $filterActive = $dateFrom !== '' || $dateTo !== '' || $filialeFilter !== '';
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500">
                @if ($filterActive)
                    Keine Veranstaltungen im gewählten Zeitraum bzw. für diese Filiale — Filter leeren zeigt alle kommenden.
                @else
                    Noch keine Veranstaltungen verarbeitet. Sie entstehen automatisch aus den ZAS-Lieferungen.
                @endif
            </div>
        @endforelse
    </div>

    <div class="hidden rounded-lg border border-gray-200 bg-white lg:block">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Zeitraum</th>
                    <th class="px-4 py-2 font-medium">Veranstaltung</th>
                    <th class="px-4 py-2 font-medium">Filiale</th>
                    <th class="px-4 py-2 font-medium">Kunde</th>
                    <th class="px-4 py-2 font-medium">Adresse</th>
                    <th class="px-4 py-2 font-medium">Disposition</th>
                    <th class="px-4 py-2 font-medium text-center">Kleidung</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($events as $event)
                    @php
                        $open = $event->assignments_count - $event->confirmed_count;
                    @endphp
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $event->starts_on?->format('d.m.Y') ?? '—' }}
                            @if ($event->ends_on && $event->starts_on && !$event->ends_on->isSameDay($event->starts_on))
                                – {{ $event->ends_on->format('d.m.Y') }}
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            {{ $event->name ?? $event->einsatz_ref }}
                            @if ($event->has_failed_send || $event->alarm_failed)
                                <span class="ml-1 text-red-600" title="Mindestens eine Zustellung fehlgeschlagen">⚠</span>
                            @endif
                            @if (($unreadByEvent[$event->id] ?? 0) > 0)
                                <a href="{{ route('recruiting.dispo.events.show', ['eventId' => $event->id]) }}" class="ml-1 inline-flex items-center gap-1 rounded-full bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white" title="Ungelesene Nachrichten von disponierten Mitarbeitern">💬 {{ $unreadByEvent[$event->id] }}</a>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $event->filiale_label ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $event->einsatzfirma ?? '—' }}</td>
                        <td class="px-4 py-2 max-w-[16rem]">
                            @if ($event->venue_text)
                                <span class="block truncate" title="{{ $event->venue_text }}">{{ \Illuminate\Support\Str::limit(str_replace(["\r", "\n"], ' ', $event->venue_text), 40) }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $event->assignments_count }} gesamt · {{ $event->confirmed_count }} bestätigt
                            @if ($open > 0)
                                <span class="ml-1 rounded bg-orange-50 px-1.5 py-0.5 text-xs text-orange-600">{{ $open }} offen</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            @if ($event->dresscode)
                                <span class="text-green-600">✓</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('recruiting.dispo.events.show', ['eventId' => $event->id]) }}" class="text-blue-600 hover:underline">Ansehen</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                            @php
                                $filterActive = $dateFrom !== '' || $dateTo !== '' || $filialeFilter !== '';
                                $emptyText = $filterActive
                                    ? 'Keine Veranstaltungen im gewählten Zeitraum bzw. für diese Filiale — Filter leeren zeigt alle kommenden.'
                                    : 'Noch keine Veranstaltungen verarbeitet. Sie entstehen automatisch aus den ZAS-Lieferungen.';
                            @endphp
                            {{ $emptyText }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
