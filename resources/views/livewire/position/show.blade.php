<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$position->title" icon="heroicon-o-briefcase" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Stellen', 'href' => route('recruiting.positions.index')],
            ['label' => $position->title],
        ]">
            <x-slot name="left">
            </x-slot>
            <x-ui-button variant="primary" size="sm" wire:click="save">
                @svg('heroicon-o-check', 'w-4 h-4')
                <span>Speichern</span>
            </x-ui-button>
            <x-ui-button variant="danger" size="sm" wire:click="deletePosition" wire:confirm="Stelle wirklich löschen?">
                @svg('heroicon-o-trash', 'w-4 h-4')
                <span>Löschen</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container spacing="space-y-8">
        @if(session()->has('message'))
            <div class="p-4 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm font-medium">
                {{ session('message') }}
            </div>
        @endif

        @if($errors->any())
            <div class="p-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-8">
            <div class="flex items-center gap-2 mb-6">
                @svg('heroicon-o-briefcase', 'w-6 h-6 text-blue-600')
                <h2 class="text-xl font-bold text-[var(--ui-secondary)]">Stellen-Daten</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <x-ui-input-text name="position.title" label="Titel" wire:model.live="position.title" required />
                <x-ui-input-text name="position.department" label="Abteilung" wire:model.live="position.department" />
                <x-ui-input-text name="position.location" label="Standort" wire:model.live="position.location" />
                <x-ui-input-select name="position.hcm_job_title_id" label="HCM-Stelle" :options="$this->jobTitles" optionValue="id" optionLabel="name" :nullable="true" nullLabel="Keine HCM-Stelle" wire:model.live="position.hcm_job_title_id" />
                <x-ui-input-select name="position.owned_by_user_id" label="Verantwortlicher" :options="$this->teamUsers" optionValue="id" optionLabel="name" :nullable="true" nullLabel="Kein Verantwortlicher" wire:model.live="position.owned_by_user_id" />
                <x-ui-input-checkbox model="position.is_active" name="position.is_active" label="Aktiv" wire:model.live="position.is_active" />
            </div>
            <div class="mt-6">
                <x-ui-input-textarea name="position.description" label="Beschreibung" wire:model.live.debounce.500ms="position.description" placeholder="Beschreibung..." rows="4" />
            </div>
        </div>

        {{-- AutoPilot Settings --}}
        <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-8">
            <div class="flex items-center gap-2 mb-2">
                @svg('heroicon-o-paper-airplane', 'w-6 h-6 text-blue-600')
                <h2 class="text-xl font-bold text-[var(--ui-secondary)]">AutoPilot</h2>
            </div>
            <p class="text-sm text-[var(--ui-muted)] mb-6">Stellen-spezifische Overrides. Leere Felder nutzen die Team-Standardwerte.</p>

            <div class="space-y-4">
                {{-- Enabled Override --}}
                <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox"
                                   wire:model.live="autoPilotSettings.auto_pilot_enabled"
                                   class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                            <div>
                                <span class="text-sm font-medium text-[var(--ui-secondary)]">AutoPilot aktiviert</span>
                                <p class="text-xs text-[var(--ui-muted)] mt-0.5">Override: AutoPilot für diese Stelle ein/ausschalten</p>
                            </div>
                        </label>
                        @if(isset($autoPilotSettings['auto_pilot_enabled']))
                            <button wire:click="clearAutoPilotSetting('auto_pilot_enabled')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                        @endif
                    </div>
                </div>

                {{-- Auto-Start Override --}}
                <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox"
                                   wire:model.live="autoPilotSettings.auto_start_auto_pilot"
                                   class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                            <div>
                                <span class="text-sm font-medium text-[var(--ui-secondary)]">AutoPilot automatisch starten</span>
                                <p class="text-xs text-[var(--ui-muted)] mt-0.5">Override: Auto-Start für diese Stelle ein/ausschalten</p>
                            </div>
                        </label>
                        @if(isset($autoPilotSettings['auto_start_auto_pilot']))
                            <button wire:click="clearAutoPilotSetting('auto_start_auto_pilot')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                        @endif
                    </div>
                </div>

                {{-- Channel Priority --}}
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-sm font-medium text-[var(--ui-secondary)]">Kanal-Priorität</label>
                        @if(isset($autoPilotSettings['auto_pilot_channel_priority']))
                            <button wire:click="clearAutoPilotSetting('auto_pilot_channel_priority')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                        @endif
                    </div>
                    <select wire:model.live="autoPilotSettings.auto_pilot_channel_priority"
                            class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                        <option value="">Team-Default verwenden</option>
                        <option value="whatsapp_first">WhatsApp bevorzugt (Fallback Email)</option>
                        <option value="email_first">Email bevorzugt (Fallback WhatsApp)</option>
                        <option value="whatsapp_only">Nur WhatsApp</option>
                        <option value="email_only">Nur Email</option>
                    </select>
                </div>

                {{-- WA Account --}}
                @if($this->availableWhatsAppAccounts->isNotEmpty())
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-sm font-medium text-[var(--ui-secondary)]">WhatsApp Account</label>
                            @if(isset($autoPilotSettings['auto_pilot_wa_account_id']))
                                <button wire:click="clearAutoPilotSetting('auto_pilot_wa_account_id')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                            @endif
                        </div>
                        <x-ui-input-select
                            name="autoPilotSettings.auto_pilot_wa_account_id"
                            :options="$this->availableWhatsAppAccounts"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="Team-Default verwenden"
                            wire:model.live="autoPilotSettings.auto_pilot_wa_account_id"
                        />
                    </div>
                @endif

                {{-- WA Templates --}}
                @if($this->availableWhatsAppTemplates->isNotEmpty())
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-sm font-medium text-[var(--ui-secondary)]">WhatsApp Template — Erstkontakt</label>
                            @if(isset($autoPilotSettings['auto_pilot_wa_initial_template_id']))
                                <button wire:click="clearAutoPilotSetting('auto_pilot_wa_initial_template_id')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                            @endif
                        </div>
                        <x-ui-input-select
                            name="autoPilotSettings.auto_pilot_wa_initial_template_id"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="Team-Default verwenden"
                            wire:model.live="autoPilotSettings.auto_pilot_wa_initial_template_id"
                        />
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-sm font-medium text-[var(--ui-secondary)]">WhatsApp Template — Erinnerung</label>
                            @if(isset($autoPilotSettings['auto_pilot_wa_reminder_template_id']))
                                <button wire:click="clearAutoPilotSetting('auto_pilot_wa_reminder_template_id')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                            @endif
                        </div>
                        <x-ui-input-select
                            name="autoPilotSettings.auto_pilot_wa_reminder_template_id"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="Team-Default verwenden"
                            wire:model.live="autoPilotSettings.auto_pilot_wa_reminder_template_id"
                        />
                    </div>
                    {{-- Interview Booking Template --}}
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-sm font-medium text-[var(--ui-secondary)]">WhatsApp Template — Interview Buchung</label>
                            @if(isset($autoPilotSettings['interview_booking_wa_template_id']))
                                <button wire:click="clearAutoPilotSetting('interview_booking_wa_template_id')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                            @endif
                        </div>
                        <x-ui-input-select
                            name="autoPilotSettings.interview_booking_wa_template_id"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="Team-Default verwenden"
                            wire:model.live="autoPilotSettings.interview_booking_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] mt-1">Wird gesendet wenn alle Phasen abgeschlossen sind.</p>
                    </div>
                @endif

                {{-- Reminder Interval --}}
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-sm font-medium text-[var(--ui-secondary)]">Erinnerungsintervall (Stunden)</label>
                        @if(isset($autoPilotSettings['auto_pilot_reminder_interval_hours']) && $autoPilotSettings['auto_pilot_reminder_interval_hours'] !== null)
                            <button wire:click="clearAutoPilotSetting('auto_pilot_reminder_interval_hours')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                        @endif
                    </div>
                    <input type="number"
                           wire:model.live="autoPilotSettings.auto_pilot_reminder_interval_hours"
                           min="1" max="168"
                           placeholder="Team-Default verwenden"
                           class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                </div>

                {{-- Max Reminders --}}
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-sm font-medium text-[var(--ui-secondary)]">Max. Erinnerungen</label>
                        @if(isset($autoPilotSettings['auto_pilot_max_reminders']) && $autoPilotSettings['auto_pilot_max_reminders'] !== null)
                            <button wire:click="clearAutoPilotSetting('auto_pilot_max_reminders')" class="text-xs text-[var(--ui-primary)] hover:underline">Team-Default</button>
                        @endif
                    </div>
                    <input type="number"
                           wire:model.live="autoPilotSettings.auto_pilot_max_reminders"
                           min="1" max="10"
                           placeholder="Team-Default verwenden"
                           class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                </div>
            </div>
        </div>

        {{-- Phasen-Übersicht mit Extra-Feldern und Templates --}}
        @if($this->phases->count() > 0)
            <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-8">
                <div class="flex items-center gap-2 mb-2">
                    @svg('heroicon-o-queue-list', 'w-6 h-6 text-blue-600')
                    <h2 class="text-xl font-bold text-[var(--ui-secondary)]">Phasen</h2>
                </div>
                <p class="text-sm text-[var(--ui-muted)] mb-6">Phasen dieser Stelle mit Extra-Feldern und optionalen Template-Overrides.</p>

                <div class="space-y-6">
                    @foreach($this->phases as $phase)
                        @php $phaseFields = $phase->getExtraFieldDefinitions(); @endphp
                        <div class="p-5 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="inline-flex items-center justify-center w-6 h-6 text-xs font-bold rounded-full bg-blue-100 text-blue-700">{{ $phase->order }}</span>
                                <h3 class="text-base font-semibold text-[var(--ui-secondary)]">{{ $phase->name }}</h3>
                                <span class="text-xs text-[var(--ui-muted)]">{{ $phaseFields->count() }} Felder</span>
                                @if($phase->auto_advance)
                                    <x-ui-badge variant="success" size="xs">Auto-Advance</x-ui-badge>
                                @endif
                                @if($phase->completion_type === 'booking')
                                    <x-ui-badge variant="info" size="xs">Booking-Trigger</x-ui-badge>
                                @elseif($phase->completion_type === 'manual')
                                    <x-ui-badge variant="warning" size="xs">Manuell</x-ui-badge>
                                @endif
                                @php $phaseConfig = $phase->completion_config ?? []; @endphp
                                @if(($phaseConfig['switch_position_on_booking'] ?? false) === true)
                                    <x-ui-badge variant="primary" size="xs">Stellen-Wechsel</x-ui-badge>
                                @endif
                                @if(($phaseConfig['confirm_booking_on_completion'] ?? false) === true)
                                    <x-ui-badge variant="secondary" size="xs">Booking-Bestätigung</x-ui-badge>
                                @endif
                                @if(!$phase->show_in_dashboard)
                                    <x-ui-badge variant="muted" size="xs">Nicht im Dashboard</x-ui-badge>
                                @endif
                                <button wire:click="togglePhaseShowInDashboard({{ $phase->id }})"
                                        class="ml-auto text-xs text-[var(--ui-muted)] hover:text-[var(--ui-primary)] underline-offset-2 hover:underline"
                                        title="Sichtbarkeit im Dashboard umschalten">
                                    {{ $phase->show_in_dashboard ? 'Aus Dashboard ausblenden' : 'Im Dashboard anzeigen' }}
                                </button>
                            </div>

                            {{-- Extra-Felder Liste --}}
                            @if($phaseFields->count() > 0)
                                <div class="mb-4">
                                    <h4 class="text-xs font-semibold text-[var(--ui-muted)] uppercase tracking-wider mb-2">Extra-Felder</h4>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($phaseFields as $field)
                                            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md border {{ $field->is_required ? 'bg-blue-50 border-blue-200 text-blue-700' : 'bg-gray-50 border-gray-200 text-gray-600' }}">
                                                {{ $field->label }}
                                                <span class="text-[10px] opacity-60">({{ $field->type }})</span>
                                                @if($field->is_required)
                                                    <span class="text-red-400">*</span>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <p class="text-sm text-[var(--ui-muted)] mb-4">Keine Extra-Felder definiert.</p>
                            @endif

                            {{-- WA Template Overrides --}}
                            @if($this->availableWhatsAppTemplates->isNotEmpty())
                                <div class="pt-4 border-t border-[var(--ui-border)]/30">
                                    <h4 class="text-xs font-semibold text-[var(--ui-muted)] uppercase tracking-wider mb-3">WhatsApp Templates</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        {{-- Initial Template --}}
                                        <div>
                                            <div class="flex items-center justify-between mb-1">
                                                <label class="block text-sm font-medium text-[var(--ui-secondary)]">Erstkontakt</label>
                                                @if(isset($phaseAutoPilotSettings[$phase->id]['auto_pilot_wa_initial_template_id']))
                                                    <button wire:click="clearPhaseAutoPilotSetting({{ $phase->id }}, 'auto_pilot_wa_initial_template_id')" class="text-xs text-[var(--ui-primary)] hover:underline">Stellen-Default</button>
                                                @endif
                                            </div>
                                            <x-ui-input-select
                                                name="phaseAutoPilotSettings.{{ $phase->id }}.auto_pilot_wa_initial_template_id"
                                                :options="$this->availableWhatsAppTemplates"
                                                optionValue="id"
                                                optionLabel="label"
                                                :nullable="true"
                                                nullLabel="Stellen-/Team-Default"
                                                wire:model.live="phaseAutoPilotSettings.{{ $phase->id }}.auto_pilot_wa_initial_template_id"
                                            />
                                        </div>

                                        {{-- Reminder Template --}}
                                        <div>
                                            <div class="flex items-center justify-between mb-1">
                                                <label class="block text-sm font-medium text-[var(--ui-secondary)]">Erinnerung</label>
                                                @if(isset($phaseAutoPilotSettings[$phase->id]['auto_pilot_wa_reminder_template_id']))
                                                    <button wire:click="clearPhaseAutoPilotSetting({{ $phase->id }}, 'auto_pilot_wa_reminder_template_id')" class="text-xs text-[var(--ui-primary)] hover:underline">Stellen-Default</button>
                                                @endif
                                            </div>
                                            <x-ui-input-select
                                                name="phaseAutoPilotSettings.{{ $phase->id }}.auto_pilot_wa_reminder_template_id"
                                                :options="$this->availableWhatsAppTemplates"
                                                optionValue="id"
                                                optionLabel="label"
                                                :nullable="true"
                                                nullLabel="Stellen-/Team-Default"
                                                wire:model.live="phaseAutoPilotSettings.{{ $phase->id }}.auto_pilot_wa_reminder_template_id"
                                            />
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

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
                        <x-ui-button variant="primary" size="sm" wire:click="save" class="w-full">
                            @svg('heroicon-o-check', 'w-4 h-4') Änderungen speichern
                        </x-ui-button>
                        <x-ui-button variant="danger-outline" size="sm" wire:click="deletePosition" wire:confirm="Stelle wirklich löschen?" class="w-full">
                            @svg('heroicon-o-trash', 'w-4 h-4') Stelle löschen
                        </x-ui-button>
                    </div>
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
