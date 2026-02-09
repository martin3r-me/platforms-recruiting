<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Stellen" icon="heroicon-o-briefcase" />
    </x-slot>

    <x-ui-page-container>
        <x-ui-panel title="Übersicht" subtitle="Stellen verwalten">
            <div class="flex justify-end items-center gap-2 mb-4">
                <x-ui-button variant="primary" size="sm" wire:click="openCreateModal">
                    @svg('heroicon-o-plus', 'w-4 h-4') Neu
                </x-ui-button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3 cursor-pointer" wire:click="sortBy('title')">Titel</th>
                            <th class="px-4 py-3">Abteilung</th>
                            <th class="px-4 py-3">Standort</th>
                            <th class="px-4 py-3">Ausschreibungen</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->positions as $position)
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                <td class="px-4 py-3 font-semibold text-[var(--ui-secondary)]">{{ $position->title }}</td>
                                <td class="px-4 py-3 text-[var(--ui-muted)]">{{ $position->department ?? '–' }}</td>
                                <td class="px-4 py-3 text-[var(--ui-muted)]">{{ $position->location ?? '–' }}</td>
                                <td class="px-4 py-3">
                                    <x-ui-badge variant="secondary" size="xs">{{ $position->postings_count }}</x-ui-badge>
                                </td>
                                <td class="px-4 py-3">
                                    <x-ui-badge variant="{{ $position->is_active ? 'success' : 'secondary' }}" size="xs">
                                        {{ $position->is_active ? 'Aktiv' : 'Inaktiv' }}
                                    </x-ui-badge>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.positions.show', $position) }}" wire:navigate>
                                        @svg('heroicon-o-pencil', 'w-3 h-3') Bearbeiten
                                    </x-ui-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        @svg('heroicon-o-briefcase', 'w-16 h-16 text-[var(--ui-muted)] mb-4')
                                        <div class="text-lg font-medium text-[var(--ui-secondary)] mb-1">Keine Stellen gefunden</div>
                                        <div class="text-sm text-[var(--ui-muted)]">Erstelle deine erste Stelle</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui-panel>
    </x-ui-page-container>

    <x-ui-modal wire:model="modalShow" size="md">
        <x-slot name="header">Neue Stelle</x-slot>
        <div class="space-y-4">
            <x-ui-input-text name="title" label="Titel" wire:model.live="title" required placeholder="Stellentitel" />
            <x-ui-input-text name="department" label="Abteilung" wire:model.live="department" placeholder="Abteilung (optional)" />
            <x-ui-input-text name="location" label="Standort" wire:model.live="location" placeholder="Standort (optional)" />
            <x-ui-input-textarea name="description" label="Beschreibung" wire:model.live="description" placeholder="Beschreibung (optional)" rows="3" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-2">
                <x-ui-button type="button" variant="secondary-outline" wire:click="$set('modalShow', false)">Abbrechen</x-ui-button>
                <x-ui-button type="button" variant="primary" wire:click="createPosition">Anlegen</x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Schnellzugriff" width="w-80" :defaultOpen="true" side="left">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">Suchen</h3>
                    <x-ui-input-text name="search" placeholder="Stelle suchen…" wire:model.live.debounce.300ms="search" />
                </div>
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">Aktionen</h3>
                    <x-ui-button variant="secondary" size="sm" wire:click="openCreateModal" class="w-full justify-start">
                        @svg('heroicon-o-plus', 'w-4 h-4') <span class="ml-2">Neue Stelle</span>
                    </x-ui-button>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
