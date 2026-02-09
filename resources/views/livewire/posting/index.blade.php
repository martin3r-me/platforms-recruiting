<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Ausschreibungen" icon="heroicon-o-megaphone" />
    </x-slot>

    <x-ui-page-container>
        <x-ui-panel title="Übersicht" subtitle="Ausschreibungen verwalten">
            <div class="flex justify-end items-center gap-2 mb-4">
                <x-ui-button variant="primary" size="sm" wire:click="openCreateModal">
                    @svg('heroicon-o-plus', 'w-4 h-4') Neu
                </x-ui-button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Titel</th>
                            <th class="px-4 py-3">Stelle</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Veröffentlicht</th>
                            <th class="px-4 py-3 text-right">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->postings as $posting)
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                <td class="px-4 py-3 font-semibold text-[var(--ui-secondary)]">{{ $posting->title }}</td>
                                <td class="px-4 py-3 text-[var(--ui-muted)]">{{ $posting->position?->title ?? '–' }}</td>
                                <td class="px-4 py-3">
                                    @php
                                        $variant = match($posting->status) {
                                            'published' => 'success',
                                            'closed' => 'secondary',
                                            default => 'warning',
                                        };
                                    @endphp
                                    <x-ui-badge variant="{{ $variant }}" size="xs">{{ ucfirst($posting->status) }}</x-ui-badge>
                                </td>
                                <td class="px-4 py-3 text-[var(--ui-muted)]">
                                    {{ $posting->published_at?->format('d.m.Y') ?? '–' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.postings.show', $posting) }}" wire:navigate>
                                        @svg('heroicon-o-pencil', 'w-3 h-3') Bearbeiten
                                    </x-ui-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        @svg('heroicon-o-megaphone', 'w-16 h-16 text-[var(--ui-muted)] mb-4')
                                        <div class="text-lg font-medium text-[var(--ui-secondary)] mb-1">Keine Ausschreibungen gefunden</div>
                                        <div class="text-sm text-[var(--ui-muted)]">Erstelle deine erste Ausschreibung</div>
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
        <x-slot name="header">Neue Ausschreibung</x-slot>
        <div class="space-y-4">
            <x-ui-input-select name="rec_position_id" label="Stelle" :options="$this->availablePositions" optionValue="id" optionLabel="title" wire:model.live="rec_position_id" required />
            <x-ui-input-text name="title" label="Titel" wire:model.live="title" required placeholder="Ausschreibungstitel" />
            <x-ui-input-textarea name="description" label="Beschreibung" wire:model.live="description" placeholder="Beschreibung (optional)" rows="3" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-2">
                <x-ui-button type="button" variant="secondary-outline" wire:click="$set('modalShow', false)">Abbrechen</x-ui-button>
                <x-ui-button type="button" variant="primary" wire:click="createPosting">Anlegen</x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" width="w-80" :defaultOpen="true" side="left">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">Suchen</h3>
                    <x-ui-input-text name="search" placeholder="Ausschreibung suchen…" wire:model.live.debounce.300ms="search" />
                </div>
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">Status</h3>
                    <select wire:model.live="statusFilter" class="w-full rounded-md border border-[var(--ui-border)] bg-white px-3 py-2 text-sm">
                        <option value="">Alle</option>
                        <option value="draft">Entwurf</option>
                        <option value="published">Veröffentlicht</option>
                        <option value="closed">Geschlossen</option>
                    </select>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
