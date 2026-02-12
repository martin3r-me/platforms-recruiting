<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="($applicant->getContact()?->full_name ?? 'Bewerber #' . $applicant->id)" icon="heroicon-o-user-plus" />
    </x-slot>

    <x-ui-page-container spacing="space-y-8">
        {{-- Header --}}
        @php
            $primaryContact = $applicant->crmContactLinks->first()?->contact;
            $primaryEmail = $primaryContact?->emailAddresses->first()?->email_address;
            $primaryPhone = $primaryContact?->phoneNumbers->first()?->phone_number;
        @endphp
        <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-8">
            <div class="flex items-start justify-between mb-6">
                <div class="flex-1 min-w-0">
                    <h1 class="text-3xl font-bold text-[var(--ui-secondary)] mb-4 tracking-tight">
                        {{ $primaryContact?->full_name ?? 'Bewerber #' . $applicant->id }}
                    </h1>
                    <div class="flex items-center gap-6 text-sm text-[var(--ui-muted)] flex-wrap">
                        @if($applicant->applicantStatus)
                            <span class="flex items-center gap-2">
                                @svg('heroicon-o-clipboard-document-list', 'w-4 h-4')
                                <span class="font-medium text-[var(--ui-secondary)]">Status:</span>
                                {{ $applicant->applicantStatus->name }}
                            </span>
                        @endif
                        @if($applicant->applied_at)
                            <span class="flex items-center gap-2">
                                @svg('heroicon-o-calendar', 'w-4 h-4')
                                Beworben am {{ $applicant->applied_at->format('d.m.Y') }}
                            </span>
                        @endif
                        @if($primaryEmail)
                            <span class="flex items-center gap-2">
                                @svg('heroicon-o-envelope', 'w-4 h-4')
                                {{ $primaryEmail }}
                            </span>
                        @endif
                        @if($primaryPhone)
                            <span class="flex items-center gap-2">
                                @svg('heroicon-o-phone', 'w-4 h-4')
                                {{ $primaryPhone }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div
                        x-data="{ copied: false }"
                        class="flex items-center gap-2 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg cursor-pointer transition-colors"
                        x-on:click="navigator.clipboard.writeText('{{ $this->publicUrl }}'); copied = true; setTimeout(() => copied = false, 2000)"
                        title="Public-Link kopieren"
                    >
                        @svg('heroicon-o-link', 'w-4 h-4 text-gray-500')
                        <span class="text-xs font-medium text-gray-600">Public-Link</span>
                        <template x-if="!copied">
                            @svg('heroicon-o-clipboard-document', 'w-4 h-4 text-gray-400')
                        </template>
                        <template x-if="copied">
                            @svg('heroicon-o-check', 'w-4 h-4 text-emerald-500')
                        </template>
                    </div>
                    <x-ui-badge variant="{{ $applicant->is_active ? 'success' : 'secondary' }}" size="lg">
                        {{ $applicant->is_active ? 'Aktiv' : 'Inaktiv' }}
                    </x-ui-badge>
                </div>
            </div>

            {{-- Fortschrittsbalken --}}
            <div class="mt-4">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-medium text-[var(--ui-secondary)]">Fortschritt</span>
                    <span class="text-sm text-[var(--ui-muted)]">{{ $applicant->progress }}%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <div class="bg-blue-600 h-3 rounded-full transition-all" style="width: {{ $applicant->progress }}%"></div>
                </div>
            </div>
        </div>

        {{-- Bewerber-Daten --}}
        <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-8">
            <div class="flex items-center gap-2 mb-6">
                @svg('heroicon-o-clipboard-document-list', 'w-6 h-6 text-blue-600')
                <h2 class="text-xl font-bold text-[var(--ui-secondary)]">Bewerber-Daten</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <x-ui-input-select
                    name="applicant.rec_applicant_status_id"
                    label="Bewerbungsstatus"
                    :options="$this->availableStatuses"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="Kein Status"
                    wire:model.live="applicant.rec_applicant_status_id"
                />

                <x-ui-input-select
                    name="applicant.owned_by_user_id"
                    label="Verantwortlicher"
                    :options="$this->teamUsers"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="Kein Verantwortlicher"
                    wire:model.live="applicant.owned_by_user_id"
                />

                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Fortschritt (%)</label>
                    <div class="text-sm text-[var(--ui-muted)]">{{ $applicant->progress }}%</div>
                </div>

                <x-ui-input-date
                    name="applicant.applied_at"
                    label="Bewerbungsdatum"
                    wire:model.live="applicant.applied_at"
                    :nullable="true"
                />

                <x-ui-input-checkbox
                    model="applicant.is_active"
                    name="applicant.is_active"
                    label="Aktiv"
                    wire:model.live="applicant.is_active"
                />

                <div>
                    <div class="flex items-center gap-3">
                        <x-ui-input-checkbox
                            model="applicant.auto_pilot"
                            name="applicant.auto_pilot"
                            label="AutoPilot"
                            wire:model.live="applicant.auto_pilot"
                        />
                        @if($applicant->autoPilotState)
                            @php
                                $stateVariant = match($applicant->autoPilotState->code ?? '') {
                                    'completed' => 'success',
                                    'review_needed' => 'warning',
                                    'waiting_for_applicant' => 'info',
                                    default => 'secondary',
                                };
                            @endphp
                            <x-ui-badge variant="{{ $stateVariant }}" size="sm">
                                {{ $applicant->autoPilotState->name }}
                            </x-ui-badge>
                        @endif
                    </div>
                </div>

                @if($applicant->auto_pilot_completed_at)
                    <div>
                        <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">AutoPilot erledigt am</label>
                        <div class="text-sm text-[var(--ui-muted)]">{{ $applicant->auto_pilot_completed_at->format('d.m.Y H:i') }}</div>
                    </div>
                @endif
            </div>

            <div class="mt-6">
                <x-ui-input-textarea
                    name="applicant.notes"
                    label="Notizen"
                    wire:model.live.debounce.500ms="applicant.notes"
                    placeholder="Notizen zum Bewerber..."
                    rows="4"
                />
            </div>
        </div>

        <x-core-extra-fields-section
            :definitions="$extraFieldDefinitions"
        />

        <!-- Zugeordnete Stellen -->
        <x-ui-panel title="Zugeordnete Stellen" subtitle="Ausschreibungen und Stellen, für die sich der Bewerber beworben hat">
            @if($applicant->postings->count() > 0)
                <div class="space-y-4">
                    @foreach($applicant->postings as $posting)
                        <div class="flex items-center justify-between p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-blue-600 text-white rounded-lg flex items-center justify-center">
                                    @svg('heroicon-o-briefcase', 'w-5 h-5')
                                </div>
                                <div>
                                    <h4 class="font-medium text-[var(--ui-secondary)]">{{ $posting->title }}</h4>
                                    @if($posting->position)
                                        <p class="text-sm text-[var(--ui-muted)]">Stelle: {{ $posting->position->title }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui-badge variant="{{ $posting->status === 'published' ? 'success' : 'secondary' }}" size="sm">{{ $posting->status }}</x-ui-badge>
                                @if($posting->pivot->applied_at)
                                    <span class="text-xs text-[var(--ui-muted)]">{{ \Carbon\Carbon::parse($posting->pivot->applied_at)->format('d.m.Y') }}</span>
                                @endif
                                <x-ui-button
                                    size="sm"
                                    variant="danger-outline"
                                    wire:click="unlinkPosting({{ $posting->id }})"
                                    wire:confirm="Ausschreibung-Zuordnung wirklich entfernen?"
                                >
                                    @svg('heroicon-o-x-mark', 'w-4 h-4')
                                </x-ui-button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    @svg('heroicon-o-briefcase', 'w-12 h-12 text-[var(--ui-muted)] mx-auto mb-4')
                    <h4 class="text-lg font-medium text-[var(--ui-secondary)] mb-2">Initiativbewerbung</h4>
                    <p class="text-[var(--ui-muted)] mb-4">Dieser Bewerber ist keiner Ausschreibung zugeordnet.</p>
                    <x-ui-button variant="secondary" wire:click="linkPosting">
                        @svg('heroicon-o-link', 'w-4 h-4')
                        Ausschreibung zuordnen
                    </x-ui-button>
                </div>
            @endif
        </x-ui-panel>

        <!-- Posting Link Modal -->
        <x-ui-modal
            size="sm"
            model="postingLinkModalShow"
        >
            <x-slot name="header">
                Ausschreibung zuordnen
            </x-slot>

            <div class="space-y-4">
                <x-ui-input-select
                    name="postingLinkForm.posting_id"
                    label="Ausschreibung auswählen"
                    :options="$this->availablePostings"
                    optionValue="id"
                    optionLabel="title"
                    :nullable="true"
                    nullLabel="– Ausschreibung auswählen –"
                    wire:model.live="postingLinkForm.posting_id"
                    required
                />
            </div>

            <x-slot name="footer">
                <div class="d-flex justify-end gap-2">
                    <x-ui-button
                        type="button"
                        variant="secondary-outline"
                        wire:click="$set('postingLinkModalShow', false)"
                    >
                        Abbrechen
                    </x-ui-button>
                    <x-ui-button type="button" variant="primary" wire:click="savePostingLink">
                        Zuordnen
                    </x-ui-button>
                </div>
            </x-slot>
        </x-ui-modal>

        <!-- Verknüpfte Kontakte -->
        <x-ui-panel title="Verknüpfte Kontakte" subtitle="CRM-Kontakte die mit diesem Bewerber verknüpft sind">
            @if($applicant->crmContactLinks->count() > 0)
                <div class="space-y-4">
                    @foreach($applicant->crmContactLinks as $link)
                        <div class="flex items-center justify-between p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-[var(--ui-primary)] text-[var(--ui-on-primary)] rounded-lg flex items-center justify-center">
                                    @svg('heroicon-o-user', 'w-5 h-5')
                                </div>
                                <div>
                                    <h4 class="font-medium text-[var(--ui-secondary)]">
                                        <a href="{{ route('crm.contacts.show', ['contact' => $link->contact->id]) }}"
                                           class="hover:underline text-[var(--ui-primary)]"
                                           wire:navigate>
                                            {{ $link->contact->full_name }}
                                        </a>
                                    </h4>
                                    @if($link->contact->emailAddresses->where('is_primary', true)->first())
                                        <p class="text-sm text-[var(--ui-muted)]">
                                            {{ $link->contact->emailAddresses->where('is_primary', true)->first()->email_address }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui-badge variant="primary" size="sm">Kontakt</x-ui-badge>
                                <x-ui-button
                                    size="sm"
                                    variant="danger-outline"
                                    wire:click="unlinkContact({{ $link->contact->id }})"
                                    wire:confirm="Kontakt-Verknüpfung wirklich entfernen?"
                                >
                                    @svg('heroicon-o-x-mark', 'w-4 h-4')
                                </x-ui-button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    @svg('heroicon-o-user', 'w-12 h-12 text-[var(--ui-muted)] mx-auto mb-4')
                    <h4 class="text-lg font-medium text-[var(--ui-secondary)] mb-2">Keine Kontakte verknüpft</h4>
                    <p class="text-[var(--ui-muted)] mb-4">Dieser Bewerber hat noch keine CRM-Kontakte.</p>
                    <div class="flex gap-3 justify-center">
                        <x-ui-button variant="secondary" wire:click="linkContact">
                            @svg('heroicon-o-link', 'w-4 h-4')
                            Kontakt verknüpfen
                        </x-ui-button>
                        <x-ui-button variant="secondary" wire:click="addContact">
                            @svg('heroicon-o-plus', 'w-4 h-4')
                            Neuen Kontakt erstellen
                        </x-ui-button>
                    </div>
                </div>
            @endif
        </x-ui-panel>

    <!-- Contact Link Modal -->
    <x-ui-modal
        size="sm"
        model="contactLinkModalShow"
    >
        <x-slot name="header">
            Kontakt verknüpfen
        </x-slot>

        <div class="space-y-4">
            <x-ui-input-select
                name="contactLinkForm.contact_id"
                label="Kontakt auswählen"
                :options="$availableContacts"
                optionValue="id"
                optionLabel="full_name"
                :nullable="true"
                nullLabel="– Kontakt auswählen –"
                wire:model.live="contactLinkForm.contact_id"
                required
            />
        </div>

        <x-slot name="footer">
            <div class="d-flex justify-end gap-2">
                <x-ui-button
                    type="button"
                    variant="secondary-outline"
                    wire:click="closeContactLinkModal"
                >
                    Abbrechen
                </x-ui-button>
                <x-ui-button type="button" variant="primary" wire:click="saveContactLink">
                    Verknüpfen
                </x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>

    <!-- Contact Create Modal -->
    <x-ui-modal
        size="lg"
        model="contactCreateModalShow"
    >
        <x-slot name="header">
            Neuen Kontakt erstellen
        </x-slot>

        <div class="space-y-4">
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                <div class="d-flex items-center gap-2 mb-2">
                    @svg('heroicon-o-information-circle', 'w-5 h-5 text-blue-600')
                    <h4 class="font-medium text-blue-900">Hinweis</h4>
                </div>
                <p class="text-blue-700 text-sm">Der neue Kontakt wird automatisch mit diesem Bewerber verknüpft.</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-text
                    name="contactForm.first_name"
                    label="Vorname"
                    wire:model.live="contactForm.first_name"
                    required
                    placeholder="Vorname eingeben"
                />

                <x-ui-input-text
                    name="contactForm.last_name"
                    label="Nachname"
                    wire:model.live="contactForm.last_name"
                    required
                    placeholder="Nachname eingeben"
                />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-text
                    name="contactForm.middle_name"
                    label="Zweiter Vorname"
                    wire:model.live="contactForm.middle_name"
                    placeholder="Zweiter Vorname (optional)"
                />

                <x-ui-input-text
                    name="contactForm.nickname"
                    label="Spitzname"
                    wire:model.live="contactForm.nickname"
                    placeholder="Spitzname (optional)"
                />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-date
                    name="contactForm.birth_date"
                    label="Geburtsdatum"
                    wire:model.live="contactForm.birth_date"
                    placeholder="Geburtsdatum (optional)"
                    :nullable="true"
                />
            </div>

            <x-ui-input-textarea
                name="contactForm.notes"
                label="Notizen"
                wire:model.live="contactForm.notes"
                placeholder="Zusätzliche Notizen (optional)"
                rows="3"
            />
        </div>

        <x-slot name="footer">
            <div class="d-flex justify-end gap-2">
                <x-ui-button
                    type="button"
                    variant="secondary-outline"
                    wire:click="closeContactCreateModal"
                >
                    Abbrechen
                </x-ui-button>
                <x-ui-button type="button" variant="primary" wire:click="saveContact">
                    Kontakt erstellen
                </x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" width="w-80" :defaultOpen="true" side="left">
            <div class="p-6 space-y-6">
                {{-- Aktionen --}}
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-4">Aktionen</h3>
                    <div class="space-y-2">
                        @if($this->isDirty)
                            <x-ui-button variant="primary" size="sm" wire:click="save" class="w-full">
                                <span class="inline-flex items-center gap-2">
                                    @svg('heroicon-o-check', 'w-4 h-4')
                                    Änderungen speichern
                                </span>
                            </x-ui-button>
                        @endif
                        <x-ui-button variant="secondary" size="sm" wire:click="linkPosting" class="w-full">
                            <span class="inline-flex items-center gap-2">
                                @svg('heroicon-o-briefcase', 'w-4 h-4')
                                Ausschreibung zuordnen
                            </span>
                        </x-ui-button>
                        <x-ui-button variant="secondary" size="sm" wire:click="linkContact" class="w-full">
                            <span class="inline-flex items-center gap-2">
                                @svg('heroicon-o-link', 'w-4 h-4')
                                Kontakt verknüpfen
                            </span>
                        </x-ui-button>
                        <x-ui-button variant="secondary" size="sm" wire:click="addContact" class="w-full">
                            <span class="inline-flex items-center gap-2">
                                @svg('heroicon-o-user-plus', 'w-4 h-4')
                                Kontakt erstellen
                            </span>
                        </x-ui-button>
                        <x-ui-button
                            variant="danger-outline"
                            size="sm"
                            wire:click="deleteApplicant"
                            wire:confirm="Bewerbung wirklich unwiderruflich löschen?"
                            class="w-full"
                        >
                            <span class="inline-flex items-center gap-2">
                                @svg('heroicon-o-trash', 'w-4 h-4')
                                Bewerbung löschen
                            </span>
                        </x-ui-button>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-6 space-y-3 text-sm">
                @if($this->autoPilotLogs->isEmpty())
                    <div class="text-[var(--ui-muted)]">Keine Aktivitäten verfügbar</div>
                @else
                    <div class="relative">
                        <div class="absolute left-3 top-0 bottom-0 w-px bg-[var(--ui-border)]/40"></div>
                        <div class="space-y-4">
                            @foreach($this->autoPilotLogs as $log)
                                @php
                                    $icon = match($log->type) {
                                        'run_started' => 'heroicon-o-play',
                                        'state_changed' => 'heroicon-o-arrow-path',
                                        'email_sent' => 'heroicon-o-envelope',
                                        'completed' => 'heroicon-o-check-circle',
                                        'error' => 'heroicon-o-exclamation-triangle',
                                        default => 'heroicon-o-document-text',
                                    };
                                    $iconColor = match($log->type) {
                                        'run_started' => 'text-blue-500',
                                        'state_changed' => 'text-amber-500',
                                        'email_sent' => 'text-indigo-500',
                                        'completed' => 'text-green-500',
                                        'error' => 'text-red-500',
                                        default => 'text-gray-400',
                                    };
                                @endphp
                                <div class="relative flex gap-3 pl-1">
                                    <div class="flex-shrink-0 w-5 h-5 rounded-full bg-white flex items-center justify-center z-10">
                                        @svg($icon, 'w-4 h-4 ' . $iconColor)
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[var(--ui-secondary)] leading-snug break-words">{{ \Illuminate\Support\Str::limit($log->summary, 120) }}</p>
                                        <p class="text-xs text-[var(--ui-muted)] mt-0.5">{{ $log->created_at->diffForHumans() }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
