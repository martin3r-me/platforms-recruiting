<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$position->title" icon="heroicon-o-briefcase" />
    </x-slot>

    <x-ui-page-container spacing="space-y-8">
        <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-8">
            <div class="flex items-center gap-2 mb-6">
                @svg('heroicon-o-briefcase', 'w-6 h-6 text-blue-600')
                <h2 class="text-xl font-bold text-[var(--ui-secondary)]">Stellen-Daten</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <x-ui-input-text name="position.title" label="Titel" wire:model.live="position.title" required />
                <x-ui-input-text name="position.department" label="Abteilung" wire:model.live="position.department" />
                <x-ui-input-text name="position.location" label="Standort" wire:model.live="position.location" />
                <x-ui-input-select name="position.owned_by_user_id" label="Verantwortlicher" :options="$this->teamUsers" optionValue="id" optionLabel="name" :nullable="true" nullLabel="Kein Verantwortlicher" wire:model.live="position.owned_by_user_id" />
                <x-ui-input-checkbox model="position.is_active" name="position.is_active" label="Aktiv" wire:model.live="position.is_active" />
            </div>
            <div class="mt-6">
                <x-ui-input-textarea name="position.description" label="Beschreibung" wire:model.live.debounce.500ms="position.description" placeholder="Beschreibung..." rows="4" />
            </div>
        </div>

        <x-core-extra-fields-section :definitions="$extraFieldDefinitions" />

        {{-- Postings --}}
        <x-ui-panel title="Ausschreibungen" subtitle="Ausschreibungen zu dieser Stelle">
            @if($position->postings->count() > 0)
                <div class="space-y-2">
                    @foreach($position->postings as $posting)
                        <div class="flex items-center justify-between p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                            <div>
                                <h4 class="font-medium text-[var(--ui-secondary)]">{{ $posting->title }}</h4>
                                <div class="text-sm text-[var(--ui-muted)]">
                                    <x-ui-badge variant="{{ $posting->status === 'published' ? 'success' : ($posting->status === 'closed' ? 'secondary' : 'warning') }}" size="xs">
                                        {{ ucfirst($posting->status) }}
                                    </x-ui-badge>
                                </div>
                            </div>
                            <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.postings.show', $posting) }}" wire:navigate>
                                Anzeigen
                            </x-ui-button>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-[var(--ui-muted)]">
                    <p>Keine Ausschreibungen vorhanden</p>
                </div>
            @endif
        </x-ui-panel>
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" width="w-80" :defaultOpen="true" side="left">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-4">Aktionen</h3>
                    <div class="space-y-2">
                        @if($this->isDirty)
                            <x-ui-button variant="primary" size="sm" wire:click="save" class="w-full">
                                @svg('heroicon-o-check', 'w-4 h-4') Änderungen speichern
                            </x-ui-button>
                        @endif
                        <x-ui-button variant="danger-outline" size="sm" wire:click="deletePosition" wire:confirm="Stelle wirklich löschen?" class="w-full">
                            @svg('heroicon-o-trash', 'w-4 h-4') Stelle löschen
                        </x-ui-button>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
