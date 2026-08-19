<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Mitarbeiter" icon="heroicon-o-identification" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Mitarbeiter'],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="full">
        {{-- Filter-Bar --}}
        <div class="bg-white border border-[var(--ui-border)] rounded-lg p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Suche</label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name, Email oder Telefon"
                        class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm"
                    />
                </div>

                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Stelle</label>
                    <select
                        wire:model.live="positionFilter"
                        class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white"
                    >
                        <option value="">— alle —</option>
                        @foreach($this->positions as $pos)
                            <option value="{{ $pos->id }}">{{ $pos->title }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Status</label>
                    <select
                        wire:model.live="activeFilter"
                        class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white"
                    >
                        <option value="active">Aktiv</option>
                        <option value="inactive">Inaktiv</option>
                        <option value="all">Alle</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Auf MA gestellt von</label>
                    <input
                        type="date"
                        wire:model.live="maSinceFrom"
                        class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm"
                    />
                </div>

                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">bis</label>
                    <input
                        type="date"
                        wire:model.live="maSinceTo"
                        class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm"
                    />
                </div>

                @if($search !== '' || $positionFilter || $activeFilter !== 'active' || $maSinceFrom !== '' || $maSinceTo !== '')
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="text-xs text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] underline px-2 py-1.5"
                    >
                        Filter zurücksetzen
                    </button>
                @endif
            </div>
        </div>

        {{-- Liste --}}
        <div class="mt-4">
            @if($this->employees->isEmpty())
                <div class="bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg p-8 text-center text-sm text-[var(--ui-muted)]">
                    Keine Mitarbeiter gefunden.
                </div>
            @else
                <div class="bg-white border border-[var(--ui-border)] rounded-lg divide-y divide-[var(--ui-border)] overflow-hidden">
                    @foreach($this->employees as $emp)
                        @php
                            $name = trim(($emp->first_name ?? '') . ' ' . ($emp->last_name ?? '')) ?: 'Mitarbeiter #' . $emp->id;
                        @endphp
                        <a href="{{ route('recruiting.employees.show', $emp->id) }}" wire:navigate
                           class="flex items-center justify-between gap-4 p-4 hover:bg-[var(--ui-muted-5)] transition">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                @svg('heroicon-o-identification', 'w-9 h-9 text-emerald-600 flex-shrink-0')
                                <div class="min-w-0">
                                    <div class="font-medium text-[var(--ui-secondary)] truncate">{{ $name }}</div>
                                    <div class="text-xs text-[var(--ui-muted)] flex flex-wrap gap-x-3 mt-0.5">
                                        @if($emp->position)
                                            <span>{{ $emp->position->title }}</span>
                                        @endif
                                        @if($emp->email)
                                            <span>{{ $emp->email }}</span>
                                        @endif
                                        @if($emp->phone)
                                            <span>{{ $emp->phone }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                @if($emp->employed_since)
                                    <span class="text-xs text-[var(--ui-muted)]">seit {{ $emp->employed_since->format('d.m.Y') }}</span>
                                @endif
                                @if($emp->hrData?->status_ma_since)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200"
                                          title="In ZAS auf Status MA gestellt am {{ $emp->hrData->status_ma_since->format('d.m.Y') }}">
                                        MA seit {{ $emp->hrData->status_ma_since->format('d.m.Y') }}
                                    </span>
                                @endif
                                @if($emp->is_active)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        Aktiv
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-600">
                                        Inaktiv
                                    </span>
                                @endif
                                @svg('heroicon-o-chevron-right', 'w-4 h-4 text-[var(--ui-muted)]')
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-2 text-xs text-[var(--ui-muted)] text-right">
                    {{ $this->employees->count() }} Mitarbeiter
                </div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
