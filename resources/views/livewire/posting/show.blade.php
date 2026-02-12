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
                <x-ui-input-date name="posting.published_at" label="Startdatum" wire:model.live="posting.published_at" :nullable="true" />
                <x-ui-input-date name="posting.closes_at" label="Enddatum" wire:model.live="posting.closes_at" :nullable="true" />
            </div>
            @if($posting->published_at && $posting->closes_at)
                @php
                    $days = \Carbon\Carbon::parse($posting->published_at)->diffInDays(\Carbon\Carbon::parse($posting->closes_at));
                @endphp
                <div class="mt-3 text-sm text-[var(--ui-muted)]">
                    Laufzeit: {{ $days }} Tage
                </div>
            @endif
            <div class="mt-6"
                x-data="{
                    editor: null,
                    debounceTimer: null,
                    boot() {
                        const Editor = window.ToastUIEditor;
                        if (!Editor) return false;

                        if (this.editor && typeof this.editor.destroy === 'function') {
                            this.editor.destroy();
                        }

                        this.editor = new Editor({
                            el: this.$refs.editorEl,
                            height: '300px',
                            initialEditType: 'wysiwyg',
                            hideModeSwitch: true,
                            usageStatistics: false,
                            placeholder: 'Beschreibung...',
                            toolbarItems: [
                                ['heading', 'bold', 'italic', 'strike'],
                                ['ul', 'ol', 'task', 'quote'],
                                ['link', 'code', 'codeblock', 'hr'],
                            ],
                            initialValue: @js($description),
                        });

                        this.editor.on('change', () => {
                            clearTimeout(this.debounceTimer);
                            this.debounceTimer = setTimeout(() => {
                                $wire.set('description', this.editor.getMarkdown());
                            }, 900);
                        });

                        return true;
                    },
                    init() {
                        if (!this.boot()) {
                            window.addEventListener('toastui:ready', () => this.boot(), { once: true });
                        }
                    },
                }"
            >
                <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Beschreibung</label>
                <div class="posting-editor-shell">
                    <div wire:ignore x-ref="editorEl"></div>
                </div>
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

@push('styles')
<style>
    .posting-editor-shell {
        position: relative;
        z-index: 1;
    }

    .posting-editor-shell .toastui-editor-defaultUI {
        border: 1px solid var(--ui-border);
        border-radius: 12px;
        overflow: hidden;
        position: relative;
        z-index: 1;
    }

    .posting-editor-shell .toastui-editor-toolbar {
        background: color-mix(in srgb, var(--ui-muted-5) 70%, transparent);
        border-bottom: 1px solid var(--ui-border);
        position: relative;
        z-index: 1;
    }

    .posting-editor-shell .toastui-editor-popup,
    .posting-editor-shell .toastui-editor-dropdown,
    .posting-editor-shell .toastui-editor-contents .toastui-editor-popup,
    .posting-editor-shell .toastui-editor-contents .toastui-editor-dropdown {
        z-index: 40 !important;
    }

    .posting-editor-shell .toastui-editor-contents {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        font-size: 17px;
        line-height: 1.7;
    }

    .posting-editor-shell .toastui-editor-defaultUI-toolbar button {
        border-radius: 8px;
    }

    .posting-editor-shell .toastui-editor-mode-switch {
        display: none !important;
    }
</style>
@endpush
