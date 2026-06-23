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
                <button
                    @click="$wire.set('activeTab', 'sources')"
                    :class="$wire.activeTab === 'sources' ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-transparent text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:border-[var(--ui-border)]'"
                    class="whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                    wire:click="$set('activeTab', 'sources')"
                >
                    Eingangs-Quellen
                </button>
                <button
                    @click="$wire.set('activeTab', 'intake-channels')"
                    :class="$wire.activeTab === 'intake-channels' ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-transparent text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:border-[var(--ui-border)]'"
                    class="whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                    wire:click="$set('activeTab', 'intake-channels')"
                >
                    Eingangskanäle
                </button>
                <button
                    @click="$wire.set('activeTab', 'payroll')"
                    :class="$wire.activeTab === 'payroll' ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-transparent text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:border-[var(--ui-border)]'"
                    class="whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                    wire:click="$set('activeTab', 'payroll')"
                >
                    Lohnbuchhaltung
                </button>
                <button
                    @click="$wire.set('activeTab', 'comms')"
                    :class="$wire.activeTab === 'comms' ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-transparent text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:border-[var(--ui-border)]'"
                    class="whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors"
                    wire:click="$set('activeTab', 'comms')"
                >
                    Kommunikation
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

                    {{-- Mindestlohn --}}
                    <x-ui-input-text
                        name="settings.minimum_wage_hourly"
                        label="Gesetzlicher Mindestlohn (€/h)"
                        wire:model="settings.minimum_wage_hourly"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="13.90"
                        hint="Wird in Verträgen als Stundenlohn-Platzhalter eingesetzt. Bei Gesetzesänderung hier anpassen."
                    />
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

                    {{-- Interview Booking Template --}}
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            name="settings.interview_booking_wa_template_id"
                            label="WhatsApp Template — Interview Buchung"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model="settings.interview_booking_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] -mt-2">Wird automatisch gesendet wenn alle Phasen abgeschlossen sind. Der Interview-Buchungslink wird als URL-Button übergeben.</p>
                    @endif

                    {{-- Interview Waitlist Template (Termin frei geworden) --}}
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            name="settings.interview_waitlist_wa_template_id"
                            label="WhatsApp Template — Termin frei geworden (Warteliste)"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model="settings.interview_waitlist_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] -mt-2">Wird an wartende Bewerber gesendet, sobald in einem ihrer Wunschorte ein Schulungstermin frei wird. Der Buchungslink wird als URL-Button übergeben.</p>
                    @endif

                    {{-- Contract Portal Template --}}
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            name="settings.contract_wa_template_id"
                            label="WhatsApp Template — Vertrags-Portal"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model="settings.contract_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] -mt-2">Wird beim Klick auf "Portal per WhatsApp senden" genutzt. Der Portal-Link (Übersicht aller zugewiesenen Verträge zum Unterschreiben) wird als URL-Button-Parameter übergeben. Ohne Konfiguration fällt der Versand auf einen Kopier-Link zurück.</p>
                    @endif

                    {{-- Employee Portal Template (neuer kombinierter Flow) --}}
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            name="settings.employee_portal_wa_template_id"
                            label="WhatsApp Template — Mitarbeiter-Portal"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model="settings.employee_portal_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] -mt-2">Wird beim Klick auf "Portallink versenden" (Schulungsnachbereitung) und beim HR-Backend-Resend genutzt. Sendet den MA-Portal-Link nach Vertragsversand. Vertrags-WA wird in diesem Flow unterdrueckt – der MA sieht die Vertraege direkt im Portal.</p>
                    @endif

                    {{-- Enrichment Template --}}
                    <div class="pt-4 mt-4 border-t border-[var(--ui-border)]/40">
                        <h4 class="text-sm font-medium text-[var(--ui-secondary)] mb-3">Enrichment</h4>

                        <div class="space-y-4">
                            <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox"
                                           wire:model.live="settings.send_initial_whatsapp_template"
                                           class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                                    <div>
                                        <span class="text-sm font-medium text-[var(--ui-secondary)]">WhatsApp-Template nach Enrichment senden</span>
                                        <p class="text-xs text-[var(--ui-muted)] mt-0.5">Sendet ein Template direkt nach der Datenextraktion, noch bevor der AutoPilot startet</p>
                                    </div>
                                </label>
                            </div>

                            @if(!empty($settings['send_initial_whatsapp_template']) && !empty($this->availableWhatsAppTemplates))
                                <x-ui-input-select
                                    name="settings.enrichment_wa_template_id"
                                    label="WhatsApp Template — nach Enrichment"
                                    :options="$this->availableWhatsAppTemplates"
                                    optionValue="id"
                                    optionLabel="label"
                                    :nullable="true"
                                    nullLabel="– Template wählen –"
                                    wire:model="settings.enrichment_wa_template_id"
                                />
                            @endif
                        </div>
                    </div>

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

            @elseif($activeTab === 'sources')
            {{-- Eingangs-Quellen --}}
            <div class="space-y-4">
                <div>
                    <h3 class="text-lg font-medium text-[var(--ui-secondary)]">Eingangs-Quellen</h3>
                    <p class="text-sm text-[var(--ui-muted)] mt-1">
                        Pflege hier von welchen Plattformen Bewerbungen kommen. Beim Eingang wird der Sender automatisch erkannt und der Bewerber der passenden Quelle zugeordnet.
                    </p>
                    <p class="text-xs text-[var(--ui-muted)] mt-1">
                        Pattern-Beispiele: <code>@indeedemail.com</code> (Domain) oder <code>website@mitarbeiter.rheingedeck.de</code> (volle Adresse). Spezifischere Patterns gewinnen.
                    </p>
                </div>

                <div class="flex justify-end">
                    <x-ui-button variant="primary-outline" size="sm" wire:click="toggleSourceForm">
                        {{ $showSourceForm ? 'Abbrechen' : ($editingSourceId ? 'Bearbeiten abbrechen' : '+ Neue Quelle anlegen') }}
                    </x-ui-button>
                </div>

                @if($showSourceForm)
                    <div class="bg-[var(--ui-muted-5)] p-4 rounded-lg space-y-4 border border-[var(--ui-border)]/60">
                        <x-ui-form-grid :cols="2" :gap="4">
                            <x-ui-input-text
                                name="newSource.name"
                                label="Name"
                                wire:model="newSource.name"
                                placeholder="z.B. INDEED, Webseite, Kleinanzeigen"
                                required
                                :errorKey="'newSource.name'"
                            />
                            <x-ui-input-text
                                name="newSource.url"
                                label="URL (optional)"
                                wire:model="newSource.url"
                                placeholder="https://de.indeed.com"
                                :errorKey="'newSource.url'"
                            />
                        </x-ui-form-grid>

                        <x-ui-input-text
                            name="newSource.match_pattern"
                            label="Match-Pattern (Sender-Adresse)"
                            wire:model="newSource.match_pattern"
                            placeholder="@indeedemail.com  oder  website@mitarbeiter.rheingedeck.de"
                            required
                            :errorKey="'newSource.match_pattern'"
                            hint="Substring-Match auf den FROM-Header. Längere/spezifischere Patterns gewinnen vor kürzeren."
                        />

                        <div class="flex items-center gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox"
                                       wire:model="newSource.is_active"
                                       class="w-4 h-4 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                                <span class="text-sm text-[var(--ui-secondary)]">Aktiv</span>
                            </label>

                            <div class="flex items-center gap-2">
                                <label class="text-sm text-[var(--ui-secondary)]">Priorität</label>
                                <input type="number"
                                       wire:model="newSource.priority"
                                       min="1" max="1000"
                                       class="w-20 px-2 py-1 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                                <span class="text-xs text-[var(--ui-muted)]">(kleiner = wichtiger)</span>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <x-ui-button variant="success" size="sm" wire:click="saveSource">
                                {{ $editingSourceId ? 'Änderungen speichern' : 'Quelle anlegen' }}
                            </x-ui-button>
                        </div>
                    </div>
                @endif

                @if(empty($sourcePlatforms))
                    <div class="text-center py-8 text-[var(--ui-muted)] text-sm border border-dashed border-[var(--ui-border)]/40 rounded-lg">
                        Noch keine Eingangs-Quellen definiert. Lege oben eine an, um eingehende Bewerbungen automatisch zuzuordnen.
                    </div>
                @else
                    <div class="border border-[var(--ui-border)]/60 rounded-lg overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-[var(--ui-muted-5)] text-xs uppercase text-[var(--ui-muted)]">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium">Name</th>
                                    <th class="px-3 py-2 text-left font-medium">URL</th>
                                    <th class="px-3 py-2 text-left font-medium">Pattern</th>
                                    <th class="px-3 py-2 text-center font-medium">Prio</th>
                                    <th class="px-3 py-2 text-center font-medium">Aktiv</th>
                                    <th class="px-3 py-2 text-right font-medium">Aktionen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--ui-border)]/40">
                                @foreach($sourcePlatforms as $source)
                                    <tr class="hover:bg-[var(--ui-muted-5)]">
                                        <td class="px-3 py-2 font-medium text-[var(--ui-secondary)]">{{ $source['name'] }}</td>
                                        <td class="px-3 py-2 text-[var(--ui-muted)]">
                                            @if(!empty($source['url']))
                                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="text-[var(--ui-primary)] hover:underline">{{ $source['url'] }}</a>
                                            @else
                                                –
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 font-mono text-xs text-[var(--ui-secondary)]">{{ $source['match_pattern'] }}</td>
                                        <td class="px-3 py-2 text-center text-[var(--ui-muted)]">{{ $source['priority'] }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <button wire:click="toggleSourceActive({{ $source['id'] }})" class="inline-flex">
                                                @if($source['is_active'])
                                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                                                @else
                                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-300"></span>
                                                @endif
                                            </button>
                                        </td>
                                        <td class="px-3 py-2 text-right space-x-2">
                                            <button wire:click="editSource({{ $source['id'] }})"
                                                    class="text-xs text-[var(--ui-primary)] hover:underline">Bearbeiten</button>
                                            <button wire:click="deleteSource({{ $source['id'] }})"
                                                    wire:confirm="Wirklich löschen?"
                                                    class="text-xs text-red-600 hover:underline">Löschen</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @elseif($activeTab === 'intake-channels')
            {{-- Eingangskanäle --}}
            <div class="space-y-4">
                <div>
                    <h3 class="text-lg font-medium text-[var(--ui-secondary)]">Eingangskanäle</h3>
                    <p class="text-sm text-[var(--ui-muted)] mt-1">
                        Lege fest, auf welchen Kanälen Bewerbungen eingehen. Nur markierte Kanäle erzeugen Bewerber. Optional kann pro Kanal eine Fallback-Ausschreibung gesetzt werden, die greift, wenn keine automatische Zuordnung möglich ist.
                    </p>
                </div>

                @if(empty($intakeChannels))
                    <div class="text-center py-8 text-[var(--ui-muted)] text-sm border border-dashed border-[var(--ui-border)]/40 rounded-lg">
                        Keine CRM-Kanäle für dieses Team gefunden.
                    </div>
                @else
                    <div class="border border-[var(--ui-border)]/60 rounded-lg overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-[var(--ui-muted-5)] text-xs uppercase text-[var(--ui-muted)]">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium">Kanal</th>
                                    <th class="px-3 py-2 text-left font-medium">Typ</th>
                                    <th class="px-3 py-2 text-center font-medium">Bewerbungs-Eingang</th>
                                    <th class="px-3 py-2 text-left font-medium">Fallback-Ausschreibung</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--ui-border)]/40">
                                @foreach($intakeChannels as $channel)
                                    <tr class="hover:bg-[var(--ui-muted-5)]">
                                        <td class="px-3 py-2 font-medium text-[var(--ui-secondary)]">{{ $channel['name'] }}</td>
                                        <td class="px-3 py-2 text-[var(--ui-muted)] text-xs">{{ $channel['type'] }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <input type="checkbox"
                                                   wire:click="toggleIntakeChannel({{ $channel['channel_id'] }})"
                                                   @checked($channel['is_intake'])
                                                   class="w-4 h-4 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)] cursor-pointer">
                                        </td>
                                        <td class="px-3 py-2">
                                            @if($channel['is_intake'])
                                                @php
                                                    $currentPostingId = $channel['default_posting_id'];
                                                @endphp
                                                <select
                                                    wire:change="setIntakeDefaultPosting({{ $channel['channel_id'] }}, $event.target.value)"
                                                    class="w-full px-2 py-1 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                                                    <option value="" @selected($currentPostingId === null)>— keine —</option>
                                                    @foreach($this->openPostings as $posting)
                                                        <option value="{{ $posting['id'] }}" @selected($currentPostingId == $posting['id'])>{{ $posting['title'] }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <span class="text-xs text-[var(--ui-muted)]">–</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @elseif($activeTab === 'payroll')
            {{-- Lohnbuchhaltung: welche Felder sollen als lohnrelevant getrackt werden? --}}
            <div class="space-y-4">
                <h3 class="text-lg font-medium text-[var(--ui-secondary)]">Lohnrelevante Felder</h3>
                <p class="text-sm text-[var(--ui-muted)]">
                    Aenderungen an den hier ausgewaehlten Mitarbeiter-Feldern werden auf der Seite
                    <em>Lohnrelevante Aenderungen</em> gesammelt und koennen als CSV an die Lohnbuchhaltung
                    uebergeben werden. Initiale Befuellung (leer → Wert) wird nicht getrackt.
                </p>

                @foreach($this->payrollFieldGroups as $groupLabel => $fields)
                    <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                        <div class="text-xs font-semibold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">{{ $groupLabel }}</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                            @foreach($fields as $fieldKey => $fieldLabel)
                                <label class="flex items-center gap-2 cursor-pointer text-sm">
                                    <input type="checkbox"
                                           wire:model="settings.employee_payroll_tracked_fields"
                                           value="{{ $fieldKey }}"
                                           class="w-4 h-4 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                                    <span class="text-[var(--ui-secondary)]">{{ $fieldLabel }}</span>
                                    <span class="text-xs text-[var(--ui-muted)] font-mono">{{ $fieldKey }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            @elseif($activeTab === 'comms')
            {{-- Kommunikation: Eskalations-Schwellen für das WhatsApp 24h-Fenster --}}
            <div class="space-y-5">
                <div>
                    <h3 class="text-lg font-medium text-[var(--ui-secondary)]">Kommunikations-Übersicht & Eskalation</h3>
                    <p class="text-sm text-[var(--ui-muted)] mt-1">
                        Steuert die Ampel der Kommunikations-Übersicht. WhatsApp lässt freie Antworten nur
                        innerhalb von 24h nach der letzten eingehenden Nachricht zu — die Schwellen beziehen
                        sich auf die <strong>Restzeit</strong> in diesem Fenster.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- Gelb-Schwelle --}}
                    <x-ui-input-text
                        type="number"
                        name="settings.comms_window_yellow_hours_left"
                        label="Gelb ab Restzeit (Stunden)"
                        wire:model="settings.comms_window_yellow_hours_left"
                    />
                    {{-- Rot-Schwelle --}}
                    <x-ui-input-text
                        type="number"
                        name="settings.comms_window_red_hours_left"
                        label="Rot ab Restzeit (Stunden)"
                        wire:model="settings.comms_window_red_hours_left"
                    />
                </div>
                <p class="text-xs text-[var(--ui-muted)] -mt-2">
                    Grün = Fenster offen mit viel Zeit · Gelb = läuft bald ab · Rot = nur noch wenige Stunden
                    offen (jetzt antworten) · Verpasst = Fenster geschlossen und unbeantwortet.
                </p>

                {{-- Eskalations-Verantwortlicher --}}
                <x-ui-input-select
                    name="settings.comms_escalation_user_id"
                    label="Eskalations-Verantwortliche/r"
                    :options="$teamUsers"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="– Niemand –"
                    wire:model="settings.comms_escalation_user_id"
                />
                <p class="text-xs text-[var(--ui-muted)] -mt-2">
                    Sieht verpasste/rote Konversationen team-weit — greift auch, wenn der zuständige Owner
                    krank oder im Urlaub ist.
                </p>

                {{-- Eingangsbestätigungs-Template ("wir melden uns") --}}
                @if(!empty($this->availableWhatsAppTemplates))
                    <x-ui-input-select
                        name="settings.comms_holding_template_id"
                        label="WhatsApp Template — Eingangsbestätigung (wir melden uns)"
                        :options="$this->availableWhatsAppTemplates"
                        optionValue="id"
                        optionLabel="label"
                        :nullable="true"
                        nullLabel="– Template wählen –"
                        wire:model="settings.comms_holding_template_id"
                    />
                    <p class="text-xs text-[var(--ui-muted)] -mt-2">
                        Wird in der Kommunikations-Übersicht über „Eingangsbestätigung an Markierte senden"
                        an mehrere markierte Kontakte gleichzeitig verschickt („deine Nachricht wird bearbeitet,
                        wir melden uns"). Eine Body-Variable <span class="font-mono">@{{name}}</span> bzw.
                        <span class="font-mono">@{{vorname}}</span> wird automatisch mit dem Vornamen gefüllt.
                    </p>
                @else
                    <div class="p-3 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40 text-sm text-[var(--ui-muted)]">
                        Keine WhatsApp Templates verfügbar. Templates werden über die WhatsApp-Integration synchronisiert.
                    </div>
                @endif
            </div>
            @endif
        </div>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="success" wire:click="save">Speichern</x-ui-button>
    </x-slot>
</x-ui-modal>
