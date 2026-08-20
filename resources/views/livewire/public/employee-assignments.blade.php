<div class="mx-auto max-w-lg p-4 space-y-4">
    @if ($tokenInvalid)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            Dieser Link ist ungültig. Bitte melde dich bei deinem Ansprechpartner.
        </div>
    @else
        <div>
            <h1 class="text-xl font-bold text-gray-900">Hallo {{ $firstName }} 👋</h1>
            <p class="text-sm text-gray-500">Hier sind deine anstehenden Einsätze.</p>
        </div>

        @forelse ($this->eventGroups as $group)
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 bg-gray-50 px-4 py-3">
                    <div class="text-base font-bold text-gray-900">{{ $group['name'] }}</div>
                </div>

                <div class="space-y-3 p-4">
                    @if ($group['adresse'])
                        <div class="rounded-lg bg-gray-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ort / Adresse</div>
                            <div class="mt-1 whitespace-pre-line text-sm text-gray-800">{{ $group['adresse'] }}</div>
                        </div>
                    @endif
                    @if ($group['zusatz_ort'])
                        <div class="rounded-lg bg-gray-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Anfahrt / wo genau</div>
                            <div class="mt-1 whitespace-pre-line text-sm text-gray-800">{{ $group['zusatz_ort'] }}</div>
                        </div>
                    @endif
                    @if ($group['kleidung'])
                        <div class="rounded-lg bg-gray-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kleidung / Infos</div>
                            <div class="mt-1 whitespace-pre-line text-sm text-gray-800">{{ $group['kleidung'] }}</div>
                        </div>
                    @endif

                    @foreach ($group['days'] as $day)
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold text-gray-900">{{ $day['datum'] }}</div>
                                @if ($day['confirmed'])
                                    <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">✓ bestätigt</span>
                                @endif
                            </div>
                            @if ($day['arrival'])
                                <div class="mt-2 rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-900">
                                    Bitte sei um {{ $day['arrival'] }} Uhr da
                                </div>
                            @endif
                            <div class="mt-2 text-sm text-gray-600">
                                @if ($day['taetigkeit'])<span class="font-medium text-gray-800">{{ $day['taetigkeit'] }}</span> · @endif
                                @if ($day['von'])Schicht {{ $day['von'] }}@if ($day['bis'])–{{ $day['bis'] }}@endif Uhr @endif
                            </div>
                            @if ($day['individual_note'])
                                <div class="mt-2 rounded-md border border-amber-100 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Hinweis für dich</div>
                                    <div class="mt-0.5 whitespace-pre-line">{{ $day['individual_note'] }}</div>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($group['all_confirmed'])
                        <div class="rounded-lg bg-green-50 p-3 text-center text-sm font-semibold text-green-800">✓ Bestätigt — danke!</div>
                    @else
                        <button wire:click="confirm({{ $group['event_id'] }})"
                                class="w-full rounded-xl bg-green-600 px-4 py-3.5 text-base font-bold text-white shadow-sm hover:bg-green-700 active:bg-green-800">
                            @if (count($group['days']) > 1) Alle {{ count($group['days']) }} Einsätze bestätigen @else Einsatz bestätigen @endif
                        </button>
                        <p class="text-center text-xs text-gray-500">Bitte bestätige bis {{ $group['days'][0]['deadline'] }} Uhr — sonst wird deine Einbuchung storniert.</p>
                    @endif
                </div>

                @if ($group['contact_line'])
                    <div class="border-t border-gray-100 px-4 py-3 text-center text-sm text-gray-600">
                        Dein Ansprechpartner ist <span class="font-medium text-gray-800">{{ $group['contact_line'] }}</span>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-600">
                Aktuell liegen keine offenen Einsätze für dich vor.
            </div>
        @endforelse

        @if ($this->pastEventGroups !== [])
            <div class="pt-2">
                <button wire:click="$toggle('showPast')" class="flex w-full items-center justify-between rounded-lg px-1 py-2 text-sm text-gray-500 hover:text-gray-700">
                    <span>Vergangene Einsätze</span>
                    <span class="text-xs">{{ $showPast ? '▲ ausblenden' : '▼ anzeigen' }}</span>
                </button>
                @if ($showPast)
                    <div class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-100 bg-white">
                        @foreach ($this->pastEventGroups as $group)
                            <div class="px-3 py-2 text-sm">
                                <div class="font-medium text-gray-700">{{ $group['name'] }}</div>
                                <div class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-gray-500">
                                    @foreach ($group['days'] as $day)
                                        <span>{{ $day['datum'] }}@if ($day['confirmed']) ✓@endif</span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
