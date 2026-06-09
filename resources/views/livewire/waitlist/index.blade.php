<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Warteliste Schulungstermine" icon="heroicon-o-clock" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Warteliste'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="px-4 sm:px-6 lg:px-8">
            <x-ui-panel title="Wartende Bewerber" subtitle="Bewerber, die auf einen freien Schulungstermin warten">
                {{-- Zähler pro Ort --}}
                <div class="flex flex-wrap gap-2 mb-6">
                    <button type="button" wire:click="selectOrt(null)"
                            class="px-3 py-1.5 rounded-full text-sm font-semibold {{ $selectedOrt === null ? 'bg-[var(--ui-primary)] text-white' : 'bg-[var(--ui-muted-5)] text-[var(--ui-secondary)]' }}">
                        Alle
                    </button>
                    @foreach($this->countsByOrt as $ort => $count)
                        <button type="button" wire:click="selectOrt(@js($ort))"
                                class="px-3 py-1.5 rounded-full text-sm font-semibold {{ $selectedOrt === $ort ? 'bg-[var(--ui-primary)] text-white' : 'bg-[var(--ui-muted-5)] text-[var(--ui-secondary)]' }}">
                            {{ $ort }} <span class="opacity-70">{{ $count }}</span>
                        </button>
                    @endforeach
                </div>

                {{-- Liste der Wartenden --}}
                <div class="border border-[var(--ui-border)]/40 rounded-lg divide-y divide-[var(--ui-border)]/40">
                    @forelse($this->entries as $entry)
                        <div class="flex items-center justify-between px-4 py-3">
                            <div>
                                <div class="font-medium text-[var(--ui-secondary)]">{{ $entry->applicant?->getContact()?->full_name ?? 'Bewerber #'.$entry->rec_applicant_id }}</div>
                                <div class="text-sm text-[var(--ui-muted)]">
                                    Wunschorte: {{ implode(', ', $entry->wunschorte ?? []) }} · seit {{ $entry->enrolled_at?->format('d.m.Y') }}
                                </div>
                            </div>
                            <div class="text-sm">
                                @if($entry->notified_at)
                                    <span class="text-emerald-600">benachrichtigt {{ $entry->notified_at->format('d.m.Y') }}</span>
                                @else
                                    <span class="text-[var(--ui-muted)]">wartet</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-[var(--ui-muted)]">Keine Wartenden.</div>
                    @endforelse
                </div>
            </x-ui-panel>
        </div>
    </x-ui-page-container>
</x-ui-page>
