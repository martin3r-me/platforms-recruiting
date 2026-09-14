{{-- Schulungsliste der Teamleiter-Ansicht (14.09.2026).
     Gleiches Seitengeruest wie die Nachbereitung (x-ui-page + Panel), damit
     sich die Ansicht nicht wie ein Fremdkoerper anfuehlt.

     Breadcrumbs OHNE Dashboard-Link: fuer eingeschraenkte Konten ist das
     Dashboard gesperrt, ein Link dorthin wuerde nur umleiten. --}}
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Schulungen bewerten" icon="heroicon-o-star" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Schulungen bewerten'],
        ]" />
    </x-slot>

    <x-ui-page-container width="full">
        <div class="px-4 sm:px-6 lg:px-8">
            <x-ui-panel
                title="Schulungen"
                subtitle="Die letzten vier Wochen und alle kommenden. Öffnen, um Anwesenheit und Bewertung einzutragen."
            >
                @if (session('success'))
                    <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">
                        {{ session('success') }}
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full table-auto border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                <th class="px-4 py-3">Schulung</th>
                                <th class="px-4 py-3">Datum</th>
                                <th class="px-4 py-3">Ort</th>
                                <th class="px-4 py-3">Teilnehmer</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/60">
                            @forelse ($this->interviews as $interview)
                                @php
                                    $start = $interview->starts_at;
                                    $istVergangen = $start && $start->isPast();
                                @endphp
                                <tr class="hover:bg-gray-50" wire:key="interview-{{ $interview->id }}">
                                    <td class="px-4 py-3 font-medium text-[var(--ui-secondary)]">
                                        {{ $interview->interviewType?->name ?? 'Schulung' }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if ($start)
                                            {{ $start->format('d.m.Y') }}
                                            <span class="text-[var(--ui-muted)]">{{ $start->format('H:i') }}</span>
                                        @else
                                            <span class="text-[var(--ui-muted)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $interview->location ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $interview->teilnehmer_count }}</td>
                                    <td class="px-4 py-3 text-right">
                                        @php
                                            $btnVariant = $istVergangen ? 'primary' : 'secondary-outline';
                                        @endphp
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
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                        <div class="text-sm">Keine Schulungen in diesem Zeitraum</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui-panel>
        </div>
    </x-ui-page-container>
</x-ui-page>
