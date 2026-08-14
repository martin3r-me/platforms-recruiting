<div class="mx-auto max-w-lg p-4 space-y-4">
    @if ($tokenInvalid)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            Dieser Link ist ungültig. Bitte melde dich bei deinem Ansprechpartner.
        </div>
    @else
        <h1 class="text-lg font-semibold">Hallo {{ $firstName }}, deine Einsätze</h1>

        @forelse ($this->eventGroups as $group)
            <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
                <div>
                    <div class="text-base font-semibold">{{ $group['name'] }}</div>
                    @if ($group['venue_text'])
                        <div class="mt-1 whitespace-pre-line text-sm text-gray-600">{{ $group['venue_text'] }}</div>
                    @endif
                </div>

                <div class="divide-y divide-gray-100 rounded border border-gray-100">
                    @foreach ($group['days'] as $day)
                        <div class="flex items-start justify-between gap-2 p-2 text-sm">
                            <div>
                                <div class="font-medium">{{ $day['datum'] }}</div>
                                @if ($day['arrival'])
                                    <div class="font-semibold">Sei um {{ $day['arrival'] }} Uhr da</div>
                                @endif
                                <div class="text-gray-600">
                                    @if ($day['taetigkeit']){{ $day['taetigkeit'] }} · @endif
                                    @if ($day['von']){{ $day['von'] }}@if ($day['bis'])–{{ $day['bis'] }}@endif Uhr @endif
                                </div>
                            </div>
                            @if ($day['confirmed'])
                                <span class="shrink-0 rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800">✓</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($group['dresscode'])
                    <div class="text-sm"><span class="font-medium">Kleidung:</span> <span class="whitespace-pre-line">{{ $group['dresscode'] }}</span></div>
                @endif
                @if ($group['anfahrt'])
                    <div class="text-sm"><span class="font-medium">Anfahrt:</span> <span class="whitespace-pre-line">{{ $group['anfahrt'] }}</span></div>
                @endif

                @if ($group['all_confirmed'])
                    <div class="rounded bg-green-50 p-3 text-center text-sm font-medium text-green-800">✓ Bestätigt — danke!</div>
                @else
                    <button wire:click="confirm({{ $group['event_id'] }})"
                            class="w-full rounded bg-green-600 px-4 py-3 text-base font-semibold text-white hover:bg-green-700">
                        @if (count($group['days']) > 1) Alle {{ count($group['days']) }} Einsätze bestätigen @else Einsatz bestätigen @endif
                    </button>
                    <p class="text-xs text-gray-500">Bitte bestätige bis {{ $group['days'][0]['deadline'] }} Uhr — sonst wird deine Einbuchung storniert.</p>
                @endif

                @if ($group['contact_line'])
                    <p class="text-xs text-gray-500">{{ $group['contact_line'] }}</p>
                @endif
            </div>
        @empty
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600">
                Aktuell liegen keine offenen Einsätze für dich vor.
            </div>
        @endforelse
    @endif
</div>
