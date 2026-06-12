<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$posting->title" icon="heroicon-o-megaphone" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Ausschreibungen', 'href' => route('recruiting.postings.index')],
            ['label' => $posting->title],
        ]">
            @if($this->isDirty)
                <x-ui-button variant="primary" size="sm" wire:click="save">
                    @svg('heroicon-o-check', 'w-4 h-4')
                    <span>Speichern</span>
                </x-ui-button>
            @endif
            @if($posting->status === 'draft')
                <x-ui-button variant="success" size="sm" wire:click="publish">
                    @svg('heroicon-o-megaphone', 'w-4 h-4')
                    <span>Veröffentlichen</span>
                </x-ui-button>
            @endif
            @if($posting->status === 'published')
                <x-ui-button variant="warning" size="sm" wire:click="close">
                    @svg('heroicon-o-x-mark', 'w-4 h-4')
                    <span>Schließen</span>
                </x-ui-button>
            @endif
            <x-ui-button variant="danger" size="sm" wire:click="deletePosting" wire:confirm="Ausschreibung wirklich löschen?">
                @svg('heroicon-o-trash', 'w-4 h-4')
                <span>Löschen</span>
            </x-ui-button>
        </x-ui-page-actionbar>
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

        {{-- Comms Channels --}}
        <x-ui-panel title="Dedizierter Kanal (Kampagne)" subtitle="Nur für exklusive Kampagnen-Kanäle. Reguläre Eingänge laufen über die Eingangskanäle (Bewerber-Einstellungen) und werden automatisch zugeordnet.">
            @if($posting->commsChannels->count() > 0)
                <div class="space-y-2 mb-4">
                    @foreach($posting->commsChannels as $channel)
                        <div class="flex items-center justify-between p-3 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                            <div class="flex items-center gap-3">
                                @php
                                    $channelIcon = match($channel->type) {
                                        'email' => 'heroicon-o-envelope',
                                        'whatsapp' => 'heroicon-o-chat-bubble-left-ellipsis',
                                        default => 'heroicon-o-signal',
                                    };
                                    $channelColor = match($channel->type) {
                                        'email' => 'text-blue-600',
                                        'whatsapp' => 'text-green-600',
                                        default => 'text-gray-600',
                                    };
                                @endphp
                                @svg($channelIcon, 'w-5 h-5 ' . $channelColor)
                                <div>
                                    <div class="font-medium text-sm text-[var(--ui-secondary)]">{{ $channel->name }}</div>
                                    <div class="text-xs text-[var(--ui-muted)]">{{ ucfirst($channel->type) }} &middot; {{ $channel->sender_identifier }}</div>
                                </div>
                                @if($channel->is_active)
                                    <x-ui-badge variant="success" size="xs">Aktiv</x-ui-badge>
                                @else
                                    <x-ui-badge variant="secondary" size="xs">Inaktiv</x-ui-badge>
                                @endif
                            </div>
                            <x-ui-button size="sm" variant="danger-outline" wire:click="unlinkChannel({{ $channel->id }})" wire:confirm="Channel-Verknüpfung entfernen?">
                                @svg('heroicon-o-x-mark', 'w-3 h-3')
                            </x-ui-button>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-4 text-[var(--ui-muted)] text-sm mb-4">
                    Keine Channels verknüpft. Verknüpfe einen Channel, damit eingehende Nachrichten automatisch Bewerbungen erzeugen.
                </div>
            @endif

            @if($this->availableChannels->count() > 0)
                <div class="border-t border-[var(--ui-border)]/40 pt-4">
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Channel hinzufügen</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach($this->availableChannels as $ch)
                            @php
                                $chIcon = match($ch->type) {
                                    'email' => 'heroicon-o-envelope',
                                    'whatsapp' => 'heroicon-o-chat-bubble-left-ellipsis',
                                    default => 'heroicon-o-signal',
                                };
                            @endphp
                            <button
                                wire:click="linkChannel({{ $ch->id }})"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full border border-[var(--ui-border)] bg-white hover:bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] transition-colors"
                            >
                                @svg($chIcon, 'w-3.5 h-3.5')
                                {{ $ch->name }} ({{ ucfirst($ch->type) }})
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-ui-panel>

        {{-- Externe Referenzen --}}
        <x-ui-panel title="Externe Referenzen" subtitle="Unter welcher ID/welchem Titel läuft diese Anzeige auf den Portalen? Eingehende Portal-Mails werden darüber automatisch dieser Ausschreibung zugeordnet.">
            @if($posting->externalRefs->count() > 0)
                <div class="space-y-2 mb-4">
                    @foreach($posting->externalRefs as $ref)
                        <div class="flex items-center justify-between p-3 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                            <div class="flex items-center gap-3">
                                @svg('heroicon-o-link', 'w-5 h-5 text-blue-600')
                                <div>
                                    <div class="font-medium text-sm text-[var(--ui-secondary)]">{{ $ref->sourcePlatform?->name ?? '–' }}</div>
                                    <div class="text-xs text-[var(--ui-muted)] font-mono">{{ $ref->external_ref }}</div>
                                </div>
                            </div>
                            <x-ui-button size="sm" variant="danger-outline" wire:click="removeExternalRef({{ $ref->id }})" wire:confirm="Referenz entfernen?">
                                @svg('heroicon-o-x-mark', 'w-3 h-3')
                            </x-ui-button>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-4 text-[var(--ui-muted)] text-sm mb-4">
                    Noch keine externen Referenzen hinterlegt.
                </div>
            @endif

            <div class="border-t border-[var(--ui-border)]/40 pt-4">
                <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Referenz hinzufügen</label>
                @if($this->availableSourcePlatforms->count() > 0)
                    <div class="flex flex-wrap gap-2 items-start">
                        @php
                            $sourceOptions = $this->availableSourcePlatforms->map(fn($p) => ['value' => (string) $p->id, 'label' => $p->name])->values()->all();
                        @endphp
                        <x-ui-input-select
                            name="newRefSourceId"
                            label=""
                            :options="$sourceOptions"
                            optionValue="value"
                            optionLabel="label"
                            wire:model="newRefSourceId"
                        />
                        <div class="flex-1 min-w-[200px]">
                            <input
                                type="text"
                                wire:model="newRefValue"
                                placeholder="Job-ID / Anzeigentitel"
                                class="w-full rounded-lg border border-[var(--ui-border)] bg-white px-3 py-2 text-sm text-[var(--ui-secondary)] placeholder-[var(--ui-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/30 focus:border-[var(--ui-primary)]"
                            />
                        </div>
                        <x-ui-button size="sm" variant="primary" wire:click="addExternalRef">
                            @svg('heroicon-o-plus', 'w-4 h-4')
                            <span>Hinzufügen</span>
                        </x-ui-button>
                    </div>
                    @error('newRefValue')
                        <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                    @enderror
                @else
                    <div class="text-sm text-[var(--ui-muted)]">
                        Keine aktiven Quell-Plattformen konfiguriert. Bitte zuerst Eingangskanäle in den Bewerber-Einstellungen anlegen.
                    </div>
                @endif
            </div>
        </x-ui-panel>

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

    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-6 space-y-3 text-sm text-[var(--ui-muted)]">
                Keine Aktivitäten verfügbar
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
