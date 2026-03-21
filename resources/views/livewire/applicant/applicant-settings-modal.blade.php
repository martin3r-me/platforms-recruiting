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
                    @click="$wire.set('activeTab', 'service-hours')"
                    :class="$wire.activeTab === 'service-hours' ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-transparent text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:border-[var(--ui-border)]'"
                    class="whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                    wire:click="$set('activeTab', 'service-hours')"
                >
                    Service-Zeiten
                </button>
                <button
                    @click="$wire.set('activeTab', 'auto-pilot')"
                    :class="$wire.activeTab === 'auto-pilot' ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-transparent text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:border-[var(--ui-border)]'"
                    class="whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                    wire:click="$set('activeTab', 'auto-pilot')"
                >
                    AutoPilot
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

                    {{-- Standard-Ansprechpartner --}}
                    <x-ui-input-select
                        name="settings.default_contact_user_id"
                        label="Standard-Ansprechpartner"
                        :options="$teamUsers"
                        optionValue="id"
                        optionLabel="name"
                        :nullable="true"
                        nullLabel="Kein Standard-Ansprechpartner"
                        wire:model="settings.default_contact_user_id"
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

            @elseif($activeTab === 'service-hours')
            {{-- Service-Zeiten --}}
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-[var(--ui-secondary)]">Service Hours</h3>
                    <x-ui-button variant="primary-outline" size="sm" wire:click="toggleServiceHoursForm">
                        {{ $showServiceHoursForm ? 'Abbrechen' : '+ Service Hours hinzufügen' }}
                    </x-ui-button>
                </div>

                @if($showServiceHoursForm)
                    <div class="bg-[var(--ui-muted-5)] p-4 rounded-lg space-y-4 border border-[var(--ui-border)]/60">
                        <x-ui-form-grid :cols="2" :gap="4">
                            <x-ui-input-text
                                name="newServiceZeit.name"
                                label="Name"
                                wire:model="newServiceZeit.name"
                                placeholder="z. B. Mo-Fr 9-17 Uhr"
                                required
                                :errorKey="'newServiceZeit.name'"
                            />

                            <x-ui-input-text
                                name="newServiceZeit.description"
                                label="Beschreibung"
                                wire:model="newServiceZeit.description"
                                placeholder="Optionale Beschreibung"
                                :errorKey="'newServiceZeit.description'"
                            />
                        </x-ui-form-grid>

                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox"
                                       wire:model="newServiceZeit.is_active"
                                       class="w-4 h-4 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                                <span class="text-sm text-[var(--ui-secondary)]">Aktiv</span>
                            </label>

                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox"
                                       wire:model="newServiceZeit.use_auto_messages"
                                       class="w-4 h-4 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                                <span class="text-sm text-[var(--ui-secondary)]">Auto-Nachrichten verwenden</span>
                            </label>
                        </div>

                        {{-- Service Hours Zeitplan --}}
                        <div class="space-y-3">
                            <h4 class="text-sm font-medium text-[var(--ui-secondary)]">Öffnungszeiten</h4>
                            <div class="space-y-2">
                                @foreach(['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'] as $index => $dayName)
                                    @php
                                        $dayIndex = $index === 6 ? 0 : $index + 1;
                                    @endphp
                                    <div class="flex items-center justify-between p-3 bg-[var(--ui-surface)] border border-[var(--ui-border)]/40">
                                        <div class="flex items-center gap-3 flex-1">
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="checkbox"
                                                       wire:model="newServiceZeit.service_hours.{{ $index }}.enabled"
                                                       class="w-4 h-4 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                                                <span class="text-sm font-medium text-[var(--ui-secondary)] w-24">{{ $dayName }}</span>
                                            </label>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <input type="time"
                                                   wire:model="newServiceZeit.service_hours.{{ $index }}.start"
                                                   class="px-3 py-1.5 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                                            <span class="text-sm text-[var(--ui-muted)]">bis</span>
                                            <input type="time"
                                                   wire:model="newServiceZeit.service_hours.{{ $index }}.end"
                                                   class="px-3 py-1.5 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                                        </div>
                                        <input type="hidden"
                                               wire:model="newServiceZeit.service_hours.{{ $index }}.day"
                                               value="{{ $dayIndex }}">
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        @if($newServiceZeit['use_auto_messages'])
                            <div class="space-y-4">
                                <x-ui-input-textarea
                                    name="newServiceZeit.auto_message_inside"
                                    label="Nachricht während Service-Zeit"
                                    wire:model="newServiceZeit.auto_message_inside"
                                    rows="2"
                                    placeholder="z. B. Vielen Dank für Ihre Bewerbung. Wir melden uns innerhalb der nächsten 2 Stunden."
                                    :errorKey="'newServiceZeit.auto_message_inside'"
                                />

                                <x-ui-input-textarea
                                    name="newServiceZeit.auto_message_outside"
                                    label="Nachricht außerhalb Service-Zeit"
                                    wire:model="newServiceZeit.auto_message_outside"
                                    rows="2"
                                    placeholder="z. B. Vielen Dank für Ihre Bewerbung. Wir bearbeiten sie am nächsten Werktag."
                                    :errorKey="'newServiceZeit.auto_message_outside'"
                                />
                            </div>
                        @endif

                        <div class="d-flex justify-end">
                            <x-ui-button variant="success" size="sm" wire:click="addServiceHours">
                                Service Hours hinzufügen
                            </x-ui-button>
                        </div>
                    </div>
                @endif

                {{-- Bestehende Service Hours --}}
                <div class="space-y-2">
                    @forelse($serviceHours as $serviceHour)
                        <div class="flex items-center justify-between p-3 bg-[var(--ui-surface)] border border-[var(--ui-border)]/40">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <div class="w-3 h-3 rounded-full flex-shrink-0 {{ $serviceHour->is_active ? 'bg-[var(--ui-success)]' : 'bg-[var(--ui-muted)]' }}"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-[var(--ui-secondary)]">{{ $serviceHour->name }}</div>
                                    @if($serviceHour->description)
                                        <div class="text-xs text-[var(--ui-muted)] mt-0.5">{{ $serviceHour->description }}</div>
                                    @endif
                                    <div class="text-xs text-[var(--ui-muted)] mt-1">
                                        {{ $serviceHour->getFormattedSchedule() }}
                                    </div>
                                    @if($serviceHour->use_auto_messages)
                                        <div class="text-xs text-[var(--ui-primary)] mt-1">Auto-Nachrichten aktiv</div>
                                    @endif
                                </div>
                            </div>
                            <button wire:click="deleteServiceHours({{ $serviceHour->id }})"
                                    class="text-[var(--ui-danger)] hover:text-[var(--ui-danger)]/80 transition-colors flex-shrink-0 ml-3"
                                    title="Löschen">
                                @svg('heroicon-o-trash', 'w-4 h-4')
                            </button>
                        </div>
                    @empty
                        <div class="text-center py-8 text-[var(--ui-muted)]">
                            <p class="text-sm">Noch keine Service Hours definiert</p>
                        </div>
                    @endforelse
                </div>
            </div>
            @elseif($activeTab === 'auto-pilot')
            {{-- AutoPilot --}}
            <div class="space-y-4">
                <h3 class="text-lg font-medium text-[var(--ui-secondary)]">AutoPilot-Einstellungen</h3>
                <p class="text-xs text-[var(--ui-muted)]">Diese Werte gelten als Team-Standard. Einzelne Stellen können eigene Overrides konfigurieren.</p>

                <div class="space-y-4">
                    {{-- Enabled --}}
                    <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox"
                                   wire:model="settings.auto_pilot_enabled"
                                   class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                            <div>
                                <span class="text-sm font-medium text-[var(--ui-secondary)]">AutoPilot aktiviert</span>
                                <p class="text-xs text-[var(--ui-muted)] mt-0.5">Neue Bewerbungen werden automatisch per Template/Email kontaktiert</p>
                            </div>
                        </label>
                    </div>

                    {{-- Auto-Start AutoPilot --}}
                    @php
                        $templatesConfigured = !empty($settings['auto_pilot_wa_initial_template_id']) && !empty($settings['auto_pilot_wa_reminder_template_id']);
                    @endphp
                    <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                        <label class="flex items-center gap-3 {{ $templatesConfigured ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' }}">
                            <input type="checkbox"
                                   wire:model="settings.auto_start_auto_pilot"
                                   {{ $templatesConfigured ? '' : 'disabled' }}
                                   class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                            <div>
                                <span class="text-sm font-medium text-[var(--ui-secondary)]">AutoPilot automatisch starten</span>
                                <p class="text-xs text-[var(--ui-muted)] mt-0.5">AutoPilot wird nach erfolgreicher Enrichment automatisch aktiviert</p>
                                @if(!$templatesConfigured)
                                    <p class="text-xs text-[var(--ui-danger)] mt-1">Beide WhatsApp-Templates müssen konfiguriert sein</p>
                                @endif
                            </div>
                        </label>
                    </div>

                    {{-- Channel Priority --}}
                    <div>
                        <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Kanal-Priorität</label>
                        <select wire:model="settings.auto_pilot_channel_priority"
                                class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                            <option value="whatsapp_first">WhatsApp bevorzugt (Fallback Email)</option>
                            <option value="email_first">Email bevorzugt (Fallback WhatsApp)</option>
                            <option value="whatsapp_only">Nur WhatsApp</option>
                            <option value="email_only">Nur Email</option>
                        </select>
                    </div>

                    {{-- WA Account --}}
                    @if(!empty($this->availableWhatsAppAccounts))
                        <x-ui-input-select
                            name="settings.auto_pilot_wa_account_id"
                            label="WhatsApp Account"
                            :options="$this->availableWhatsAppAccounts"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Account wählen –"
                            wire:model.live="settings.auto_pilot_wa_account_id"
                        />
                    @endif

                    {{-- WA Templates --}}
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            name="settings.auto_pilot_wa_initial_template_id"
                            label="WhatsApp Template — Erstkontakt"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model="settings.auto_pilot_wa_initial_template_id"
                        />

                        <x-ui-input-select
                            name="settings.auto_pilot_wa_reminder_template_id"
                            label="WhatsApp Template — Erinnerung"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model="settings.auto_pilot_wa_reminder_template_id"
                        />
                    @else
                        <div class="p-3 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40 text-sm text-[var(--ui-muted)]">
                            Keine WhatsApp Templates verfügbar. Templates werden über die WhatsApp-Integration synchronisiert.
                        </div>
                    @endif

                    {{-- Reminder Interval --}}
                    <div>
                        <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Erinnerungsintervall (Stunden)</label>
                        <input type="number"
                               wire:model="settings.auto_pilot_reminder_interval_hours"
                               min="1" max="168"
                               class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                        <p class="text-xs text-[var(--ui-muted)] mt-1">Wie viele Stunden zwischen Erinnerungen gewartet wird</p>
                    </div>

                    {{-- Max Reminders --}}
                    <div>
                        <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Max. Erinnerungen</label>
                        <input type="number"
                               wire:model="settings.auto_pilot_max_reminders"
                               min="1" max="10"
                               class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                        <p class="text-xs text-[var(--ui-muted)] mt-1">Nach Erreichen des Maximums wird der Status auf "Prüfung erforderlich" gesetzt</p>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="success" wire:click="save">Speichern</x-ui-button>
    </x-slot>
</x-ui-modal>
