{{-- Schulungsliste der Teamleiter-Ansicht (14.09.2026).
     Reine Anzeige — kein Anlegen, kein Bearbeiten, kein Loeschen. --}}
<div class="p-4 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-semibold text-[var(--ui-secondary)]">Schulungen bewerten</h1>
        <p class="text-sm text-[var(--ui-muted)] mt-1">
            Schulungen der letzten vier Wochen und alle kommenden. Öffne eine Schulung,
            um Anwesenheit und Bewertung einzutragen.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($this->interviews->isEmpty())
        <div class="rounded-lg border border-[var(--ui-border)] bg-white p-6 text-center text-sm text-[var(--ui-muted)]">
            Keine Schulungen in diesem Zeitraum.
        </div>
    @else
        <div class="rounded-lg border border-[var(--ui-border)] bg-white overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-[var(--ui-muted-5)] text-xs uppercase tracking-wide text-[var(--ui-muted)]">
                    <tr>
                        <th class="px-4 py-2 text-left">Schulung</th>
                        <th class="px-4 py-2 text-left">Datum</th>
                        <th class="px-4 py-2 text-left">Teilnehmer</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--ui-border)]">
                    @foreach ($this->interviews as $interview)
                        @php
                            $start = $interview->starts_at;
                            $istVergangen = $start && $start->isPast();
                        @endphp
                        <tr class="hover:bg-gray-50" wire:key="interview-{{ $interview->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-[var(--ui-secondary)]">
                                    {{ $interview->interviewType?->name ?? 'Schulung' }}
                                </div>
                                @if ($interview->location)
                                    <div class="text-xs text-[var(--ui-muted)]">{{ $interview->location }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($start)
                                    {{ $start->format('d.m.Y') }}
                                    <span class="text-[var(--ui-muted)]">{{ $start->format('H:i') }} Uhr</span>
                                @else
                                    <span class="text-[var(--ui-muted)]">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $interview->teilnehmer_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-ui-button
                                    variant="{{ $istVergangen ? 'primary' : 'secondary-outline' }}"
                                    size="xs"
                                    :href="route('recruiting.training-review.show', $interview->id)"
                                    wire:navigate
                                >
                                    Öffnen
                                </x-ui-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
