<x-ui-modal size="lg" model="modalShow">
    <x-slot name="header">
        Bewerber-Einstellungen
    </x-slot>

    <div class="flex-grow-1 overflow-y-auto">
        {{-- Tabs --}}
        <div class="border-b border-[var(--ui-border)]/40 mb-6 px-4 pt-4">
            <nav class="-mb-px flex space-x-6" aria-label="Tabs">
                <button
                    @click="$wire.set('activeTab', 'general')"
                    :class="$wire.activeTab === 'general' ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-transparent text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:border-[var(--ui-border)]'"
                    class="whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                    wire:click="$set('activeTab', 'general')"
                >
                    Allgemein
                </button>
                <button
                    @click="$wire.set('activeTab', 'channels')"
                    :class="$wire.activeTab === 'channels' ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-transparent text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:border-[var(--ui-border)]'"
                    class="whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                    wire:click="$set('activeTab', 'channels')"
                >
                    Kanäle
                </button>
            </nav>
        </div>

        <div class="p-4 space-y-6">
            @if($activeTab === 'general')
            {{-- Allgemein --}}
            <div class="space-y-4">
                <h3 class="text-lg font-medium text-[var(--ui-secondary)]">Allgemeine Einstellungen</h3>

                <div class="space-y-4">
                    {{-- Duzen --}}
                    <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox"
                                   wire:model="settings.use_informal_address"
                                   class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                            <div>
                                <span class="text-sm font-medium text-[var(--ui-secondary)]">Informelle Anrede (Duzen)</span>
                                <p class="text-xs text-[var(--ui-muted)] mt-0.5">Bewerber werden in der Kommunikation geduzt</p>
                            </div>
                        </label>
                    </div>

                    {{-- Standard-Status --}}
                    <x-ui-input-select
                        name="settings.default_status_id"
                        label="Standard-Status für neue Bewerber"
                        :options="$this->availableStatuses"
                        optionValue="id"
                        optionLabel="name"
                        :nullable="true"
                        nullLabel="Kein Standard-Status"
                        wire:model="settings.default_status_id"
                    />

                    {{-- Auto-Assign Owner --}}
                    <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox"
                                   wire:model="settings.auto_assign_owner"
                                   class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                            <div>
                                <span class="text-sm font-medium text-[var(--ui-secondary)]">Ersteller automatisch als Besitzer zuweisen</span>
                                <p class="text-xs text-[var(--ui-muted)] mt-0.5">Der Ersteller eines Bewerbers wird automatisch als Besitzer eingetragen</p>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            @elseif($activeTab === 'channels')
            {{-- Kanäle --}}
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-[var(--ui-secondary)]">Verknüpfte Kanäle</h3>
                </div>

                <p class="text-sm text-[var(--ui-muted)]">
                    Eingehende E-Mails auf verknüpften Kanälen erstellen automatisch einen neuen Bewerber.
                </p>

                <div class="space-y-2">
                    @forelse($availableChannels as $channel)
                        @php
                            $isLinked = in_array($channel['id'], $linkedChannelIds);
                        @endphp
                        <div class="flex items-center justify-between p-3 bg-[var(--ui-surface)] border border-[var(--ui-border)]/40 rounded-lg">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <div class="w-3 h-3 rounded-full flex-shrink-0 {{ $isLinked ? 'bg-[var(--ui-success)]' : 'bg-[var(--ui-muted)]' }}"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-[var(--ui-secondary)]">{{ $channel['sender_identifier'] }}</div>
                                    @if($channel['name'])
                                        <div class="text-xs text-[var(--ui-muted)] mt-0.5">{{ $channel['name'] }}</div>
                                    @endif
                                </div>
                            </div>
                            <button
                                wire:click="toggleChannel({{ $channel['id'] }})"
                                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20
                                    {{ $isLinked ? 'bg-[var(--ui-primary)]' : 'bg-[var(--ui-muted-5)]' }}"
                            >
                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out
                                    {{ $isLinked ? 'translate-x-5' : 'translate-x-0' }}"></span>
                            </button>
                        </div>
                    @empty
                        <div class="text-center py-8 text-[var(--ui-muted)]">
                            <p class="text-sm">Keine E-Mail-Kanäle verfügbar</p>
                            <p class="text-xs mt-1">Erstellen Sie zuerst einen E-Mail-Kanal in den Kommunikations-Einstellungen.</p>
                        </div>
                    @endforelse
                </div>
            </div>
            @endif
        </div>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="success" wire:click="save">Speichern</x-ui-button>
    </x-slot>
</x-ui-modal>
