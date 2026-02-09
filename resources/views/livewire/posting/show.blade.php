<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$posting->title" icon="heroicon-o-megaphone" />
    </x-slot>

    <x-ui-page-container spacing="space-y-8">
        <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-8">
            <div class="flex items-center gap-2 mb-6">
                @svg('heroicon-o-megaphone', 'w-6 h-6 text-green-600')
                <h2 class="text-xl font-bold text-[var(--ui-secondary)]">Ausschreibung</h2>
                @php
                    $statusVariant = match($posting->status) {
                        'published' => 'success',
                        'closed' => 'secondary',
                        default => 'warning',
                    };
                @endphp
                <x-ui-badge variant="{{ $statusVariant }}" size="sm">{{ ucfirst($posting->status) }}</x-ui-badge>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <x-ui-input-text name="posting.title" label="Titel" wire:model.live="posting.title" required />
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Stelle</label>
                    <div class="text-sm text-[var(--ui-muted)]">{{ $posting->position?->title ?? '–' }}</div>
                </div>
                <x-ui-input-select name="posting.status" label="Status" :options="[['value' => 'draft', 'label' => 'Entwurf'], ['value' => 'published', 'label' => 'Veröffentlicht'], ['value' => 'closed', 'label' => 'Geschlossen']]" optionValue="value" optionLabel="label" wire:model.live="posting.status" />
                <x-ui-input-checkbox model="posting.is_active" name="posting.is_active" label="Aktiv" wire:model.live="posting.is_active" />
                <x-ui-input-date name="posting.published_at" label="Veröffentlicht am" wire:model.live="posting.published_at" :nullable="true" />
                <x-ui-input-date name="posting.closes_at" label="Endet am" wire:model.live="posting.closes_at" :nullable="true" />
            </div>
            <div class="mt-6">
                <x-ui-input-textarea name="posting.description" label="Beschreibung" wire:model.live.debounce.500ms="posting.description" placeholder="Beschreibung..." rows="4" />
            </div>
        </div>

        {{-- Applicants --}}
        <x-ui-panel title="Bewerber" subtitle="Bewerber auf diese Ausschreibung">
            @if($posting->applicants->count() > 0)
                <div class="space-y-2">
                    @foreach($posting->applicants as $applicant)
                        @php $contact = $applicant->crmContactLinks->first()?->contact; @endphp
                        <div class="flex items-center justify-between p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                            <div class="font-medium text-[var(--ui-secondary)]">
                                {{ $contact?->full_name ?? 'Bewerber #' . $applicant->id }}
                            </div>
                            <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                Anzeigen
                            </x-ui-button>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8 text-[var(--ui-muted)]">Keine Bewerber</div>
            @endif
        </x-ui-panel>
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Aktionen" width="w-80" :defaultOpen="true" side="left">
            <div class="p-6 space-y-6">
                <div class="space-y-2">
                    @if($this->isDirty)
                        <x-ui-button variant="primary" size="sm" wire:click="save" class="w-full">
                            @svg('heroicon-o-check', 'w-4 h-4') Speichern
                        </x-ui-button>
                    @endif
                    @if($posting->status === 'draft')
                        <x-ui-button variant="success" size="sm" wire:click="publish" class="w-full">
                            @svg('heroicon-o-megaphone', 'w-4 h-4') Veröffentlichen
                        </x-ui-button>
                    @endif
                    @if($posting->status === 'published')
                        <x-ui-button variant="warning" size="sm" wire:click="close" class="w-full">
                            @svg('heroicon-o-x-mark', 'w-4 h-4') Schließen
                        </x-ui-button>
                    @endif
                    <x-ui-button variant="danger-outline" size="sm" wire:click="deletePosting" wire:confirm="Ausschreibung wirklich löschen?" class="w-full">
                        @svg('heroicon-o-trash', 'w-4 h-4') Löschen
                    </x-ui-button>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
