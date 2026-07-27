<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="($applicant->getContact()?->full_name ?? 'Bewerber #' . $applicant->id)" icon="heroicon-o-user-plus" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Bewerber', 'href' => route('recruiting.dashboard')],
            ['label' => ($applicant->getContact()?->full_name ?? 'Bewerber #' . $applicant->id)],
        ]">
            @if($this->isDirty)
                <x-ui-button variant="primary" size="sm" wire:click="save">
                    @svg('heroicon-o-check', 'w-4 h-4')
                    <span>Speichern</span>
                </x-ui-button>
            @endif
            <x-ui-button variant="danger" size="sm" wire:click="deleteApplicant" wire:confirm="Bewerbung wirklich unwiderruflich löschen?">
                @svg('heroicon-o-trash', 'w-4 h-4')
                <span>Löschen</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container spacing="space-y-8">
        @if($applicant->duplicate_of_applicant_id)
            <div class="p-3 bg-amber-50 border border-amber-200 rounded text-sm text-amber-900 flex items-center gap-2">
                @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 shrink-0')
                <span>
                    Mögliche Dublette von
                    <a href="{{ route('recruiting.applicants.show', $applicant->duplicate_of_applicant_id) }}" class="underline font-medium" wire:navigate>
                        Bewerber #{{ $applicant->duplicate_of_applicant_id }}
                    </a>
                    (gleiche Telefonnummer) — Auto-Pilot gestoppt. Echte Dublette: diesen Datensatz deaktivieren. Geteilte Nummer (kein Duplikat): Auto-Pilot abschalten und manuell betreuen.
                </span>
            </div>
        @endif
        {{-- Header --}}
        @php
            $primaryContact = $applicant->crmContactLinks->first()?->contact;
            $primaryEmail = $primaryContact?->emailAddresses->first()?->email_address;
            $primaryPhone = $primaryContact?->phoneNumbers->first()?->international;
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
                    <button wire:click="openTemplateModal" class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 rounded-lg transition-colors" title="WhatsApp Template senden">
                        @svg('heroicon-o-paper-airplane', 'w-4 h-4 text-emerald-600')
                        <span class="text-xs font-medium text-emerald-700">Template senden</span>
                    </button>
                    <button
                        wire:click="sendInterviewBookingLink"
                        wire:loading.attr="disabled"
                        wire:target="sendInterviewBookingLink"
                        class="flex items-center gap-2 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors"
                        title="Interview-Buchungslink per WhatsApp senden"
                    >
                        <span wire:loading.remove wire:target="sendInterviewBookingLink" class="flex items-center gap-2">
                            @svg('heroicon-o-calendar-days', 'w-4 h-4 text-blue-600')
                            <span class="text-xs font-medium text-blue-700">Interview-Link</span>
                        </span>
                        <span wire:loading wire:target="sendInterviewBookingLink" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            <span class="text-xs font-medium text-blue-700">Senden...</span>
                        </span>
                    </button>
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
                    <div
                        x-data="{ copied: false }"
                        class="flex items-center gap-2 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 rounded-lg cursor-pointer transition-colors"
                        x-on:click="navigator.clipboard.writeText('{{ $this->interviewBookingUrl }}'); copied = true; setTimeout(() => copied = false, 2000)"
                        title="Interview-Buchungslink kopieren"
                    >
                        @svg('heroicon-o-calendar-days', 'w-4 h-4 text-blue-500')
                        <span class="text-xs font-medium text-blue-600">Interview-Link</span>
                        <template x-if="!copied">
                            @svg('heroicon-o-clipboard-document', 'w-4 h-4 text-blue-400')
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

                @if($applicant->enrichment_status)
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">AutoPilot</label>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2">
                            @php
                                $isActive = $applicant->auto_pilot;
                                $channelType = $applicant->preferredCommsChannel?->type;
                            @endphp
                            <button
                                type="button"
                                wire:click="toggleAutoPilot()"
                                class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors {{ $isActive ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 ring-1 ring-emerald-200' : 'bg-[var(--ui-muted-5)] text-[var(--ui-muted)] hover:bg-[var(--ui-muted-10)] hover:text-[var(--ui-secondary)]' }}"
                                title="AutoPilot{{ $isActive ? ' (aktiv via ' . ($channelType ?? '?') . ')' : '' }}"
                            >
                                @if($isActive)
                                    <span class="relative flex h-2.5 w-2.5">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                                    </span>
                                @endif
                                @svg($channelType === 'whatsapp' ? 'heroicon-o-chat-bubble-left' : 'heroicon-o-envelope', 'w-4 h-4')
                                <span>AutoPilot</span>
                            </button>
                        </div>
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
                @endif

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
            :model="$applicant"
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

        {{-- Verträge --}}
        <x-ui-panel title="Verträge" subtitle="Zugewiesene Verträge für diesen Bewerber">
            <div class="flex flex-wrap items-center justify-end gap-2 mb-4">
                @if($applicant->contracts->whereIn('status', ['pending', 'sent', 'in_progress'])->count() > 0)
                    <x-ui-button variant="primary" size="sm" wire:click="sendApplicantPortal">
                        @svg('heroicon-o-paper-airplane', 'w-4 h-4') Portal per WhatsApp senden
                    </x-ui-button>
                    <x-ui-button variant="secondary" size="sm" wire:click="generateApplicantPortalLink">
                        @svg('heroicon-o-link', 'w-4 h-4') Portal-Link
                    </x-ui-button>
                @endif
                <x-ui-button variant="primary" size="sm" wire:click="openAssignContractModal">
                    @svg('heroicon-o-plus', 'w-4 h-4') Vertrag zuweisen
                </x-ui-button>
            </div>

            @if($applicant->contracts->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full table-auto border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                <th class="px-4 py-3">Vorlage</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Unterschrift</th>
                                <th class="px-4 py-3">Versendet</th>
                                <th class="px-4 py-3">Abgeschlossen</th>
                                <th class="px-4 py-3">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/60">
                            @foreach($applicant->contracts as $contract)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <span class="font-medium">{{ $contract->contractTemplate?->name ?? '—' }}</span>
                                        @if($contract->contractTemplate?->code)
                                            <span class="text-xs text-[var(--ui-muted)] ml-1">({{ $contract->contractTemplate->code }})</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @php
                                            $statusConfig = match($contract->status) {
                                                'pending' => ['label' => 'Ausstehend', 'variant' => 'secondary'],
                                                'sent' => ['label' => 'Versendet', 'variant' => 'info'],
                                                'in_progress' => ['label' => 'In Bearbeitung', 'variant' => 'warning'],
                                                'completed' => ['label' => 'Abgeschlossen', 'variant' => 'success'],
                                                'needs_review' => ['label' => 'Prüfung nötig', 'variant' => 'danger'],
                                                'cancelled' => ['label' => 'Storniert', 'variant' => 'secondary'],
                                                default => ['label' => $contract->status, 'variant' => 'secondary'],
                                            };
                                        @endphp
                                        <x-ui-badge variant="{{ $statusConfig['variant'] }}" size="sm">
                                            {{ $statusConfig['label'] }}
                                        </x-ui-badge>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($contract->signature_data)
                                            <span class="text-green-600 flex items-center gap-1">
                                                @svg('heroicon-o-check-circle', 'w-4 h-4')
                                                @if($contract->signed_at)
                                                    <span class="text-xs">{{ $contract->signed_at->format('d.m.Y') }}</span>
                                                @endif
                                            </span>
                                        @elseif($contract->contractTemplate?->requires_signature)
                                            <span class="text-[var(--ui-muted)] flex items-center gap-1">
                                                @svg('heroicon-o-pencil', 'w-4 h-4')
                                                <span class="text-xs">Ausstehend</span>
                                            </span>
                                        @else
                                            <span class="text-[var(--ui-muted)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-[var(--ui-muted)]">
                                        {{ $contract->sent_at?->format('d.m.Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-[var(--ui-muted)]">
                                        {{ $contract->completed_at?->format('d.m.Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <x-ui-button size="xs" variant="secondary-outline" wire:click="openContractFields({{ $contract->id }})">
                                                @svg('heroicon-o-adjustments-horizontal', 'w-3.5 h-3.5') Felder
                                            </x-ui-button>
                                            @if($contract->status === 'completed')
                                                <a href="{{ route('recruiting.public.contract-pdf', ['token' => $this->applicantPublicToken, 'contractId' => $contract->id]) }}"
                                                   target="_blank"
                                                   class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md border border-emerald-300 text-emerald-800 bg-emerald-50 hover:bg-emerald-100 transition-colors">
                                                    @svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5') PDF
                                                </a>
                                            @endif
                                            <x-ui-button size="xs" variant="secondary-outline" wire:click="generateContractLink({{ $contract->id }})">
                                                @svg('heroicon-o-link', 'w-3.5 h-3.5') Einzel-Link
                                            </x-ui-button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($portalLinkUrl)
                    <div class="mt-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg" x-data="{ copied: false }">
                        <div class="flex items-center gap-2 mb-2">
                            @svg('heroicon-o-link', 'w-4 h-4 text-emerald-700')
                            <span class="text-sm font-medium text-emerald-900">Portal-Link (alle Verträge)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="text" value="{{ $portalLinkUrl }}" readonly
                                   class="flex-1 px-3 py-2 text-xs bg-white border border-emerald-200 rounded font-mono text-emerald-900" />
                            <x-ui-button size="sm" variant="primary-outline"
                                x-on:click="navigator.clipboard.writeText('{{ $portalLinkUrl }}'); copied = true; setTimeout(() => copied = false, 2000)">
                                <span x-show="!copied">Kopieren</span>
                                <span x-show="copied" x-cloak>Kopiert!</span>
                            </x-ui-button>
                        </div>
                    </div>
                @endif

                @if($contractLinkUrl)
                    <div class="mt-4 p-4 bg-indigo-50 border border-indigo-200 rounded-lg" x-data="{ copied: false }">
                        <div class="flex items-center gap-2 mb-2">
                            @svg('heroicon-o-link', 'w-4 h-4 text-indigo-700')
                            <span class="text-sm font-medium text-indigo-900">Einzel-Signaturlink</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="text" value="{{ $contractLinkUrl }}" readonly
                                   class="flex-1 px-3 py-2 text-xs bg-white border border-indigo-200 rounded font-mono text-indigo-900" />
                            <x-ui-button size="sm" variant="primary-outline"
                                x-on:click="navigator.clipboard.writeText('{{ $contractLinkUrl }}'); copied = true; setTimeout(() => copied = false, 2000)">
                                <span x-show="!copied">Kopieren</span>
                                <span x-show="copied" x-cloak>Kopiert!</span>
                            </x-ui-button>
                        </div>
                    </div>
                @endif
            @else
                <div class="text-center py-8">
                    @svg('heroicon-o-document-text', 'w-12 h-12 text-[var(--ui-muted)] mx-auto mb-4')
                    <h4 class="text-lg font-medium text-[var(--ui-secondary)] mb-2">Keine Verträge zugewiesen</h4>
                    <p class="text-[var(--ui-muted)] mb-4">Weise eine Vertragsvorlage zu, um einen Vertrag für diesen Bewerber zu erstellen.</p>
                    <x-ui-button variant="primary" wire:click="openAssignContractModal">
                        @svg('heroicon-o-plus', 'w-4 h-4') Vertrag zuweisen
                    </x-ui-button>
                </div>
            @endif
        </x-ui-panel>

        {{-- Assign Contract Modal --}}
        <x-ui-modal size="sm" model="assignContractModalShow">
            <x-slot name="header">Vertrag zuweisen</x-slot>
            <div class="p-4 space-y-4">
                @if(!$applicant->crmContactLinks->first()?->contact)
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded text-sm text-amber-900">
                        @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline') Bewerber hat keinen verknüpften CRM-Kontakt. Bitte zuerst Kontakt anlegen/verknüpfen — sonst bleiben Kontakt-Felder im Vertrag leer.
                    </div>
                @endif

                <x-ui-input-select
                    name="selectedContractTemplateId"
                    label="Vertragsvorlage"
                    :options="$this->availableContractTemplates->map(fn($t) => ['id' => $t->id, 'label' => $t->name . ($t->code ? ' (' . $t->code . ')' : '')])->toArray()"
                    optionValue="id"
                    optionLabel="label"
                    :nullable="true"
                    nullLabel="— Vorlage wählen —"
                    wire:model.live="selectedContractTemplateId"
                    required
                    errorKey="selectedContractTemplateId"
                />

                @php
                    $selectedTemplate = $selectedContractTemplateId
                        ? $this->availableContractTemplates->firstWhere('id', (int) $selectedContractTemplateId)
                        : null;
                    $willAutoAttachIfsg = $selectedTemplate
                        && $selectedTemplate->code
                        && str_starts_with($selectedTemplate->code, 'AV-')
                        && !$applicant->contracts->contains(fn($c) =>
                            $c->contractTemplate?->code === 'IFSG'
                            && in_array($c->status, ['pending', 'sent', 'in_progress'])
                        );
                @endphp

                @if($willAutoAttachIfsg)
                    <div class="p-3 bg-blue-50 border border-blue-200 rounded text-sm text-blue-900">
                        @svg('heroicon-o-information-circle', 'w-4 h-4 inline') Das Infektionsschutzgesetz (IFSG) wird automatisch ebenfalls zugewiesen.
                    </div>
                @endif
            </div>
            <x-slot name="footer">
                <div class="flex items-center justify-end gap-2">
                    <x-ui-button variant="secondary" wire:click="closeAssignContractModal">Abbrechen</x-ui-button>
                    <x-ui-button variant="primary" wire:click="assignContract" :disabled="!$selectedContractTemplateId">Zuweisen</x-ui-button>
                </div>
            </x-slot>
        </x-ui-modal>

        {{-- Duplicate Contract Modal --}}
        <x-ui-modal size="sm" model="duplicateContractModalShow">
            <x-slot name="header">Vertrag bereits zugewiesen</x-slot>
            <div class="p-4 space-y-3">
                <p class="text-sm text-[var(--ui-secondary)]">
                    Für diese Vorlage existiert bereits ein aktiver Vertrag (Status: ausstehend/versendet/in Bearbeitung). Möchtest du ihn ersetzen?
                </p>
                <p class="text-xs text-[var(--ui-muted)]">
                    Bei "Ersetzen" wird der bestehende Vertrag auf <code>cancelled</code> gesetzt und ein neuer angelegt. Alter Vertrag bleibt referenzierbar.
                </p>
            </div>
            <x-slot name="footer">
                <div class="flex items-center justify-end gap-2">
                    <x-ui-button variant="secondary" wire:click="cancelAssignDuplicate">Abbrechen</x-ui-button>
                    <x-ui-button variant="primary" wire:click="confirmAssignReplaceDuplicate">Alten stornieren, neuen anlegen</x-ui-button>
                </div>
            </x-slot>
        </x-ui-modal>

        {{-- Contract Fields Modal --}}
        <x-ui-modal size="lg" model="contractFieldsModalShow">
            <x-slot name="header">Vertragsfelder bearbeiten</x-slot>
            <div class="p-4 space-y-4">
                @if(count($contractFieldDefinitions) === 0)
                    <p class="text-sm text-[var(--ui-muted)]">Keine Felder für diesen Vertrag definiert.</p>
                @else
                    <p class="text-xs text-[var(--ui-muted)]">
                        Wenn du ein Vertragsbeginn-Datum setzt und Vertragsende leer lässt, wird das Ende automatisch berechnet: +1 Jahr, Anfang Monat, −1 Tag.
                    </p>
                    @foreach($contractFieldDefinitions as $field)
                        @php
                            $inputType = match($field['type'] ?? 'text') {
                                'date' => 'date',
                                'number' => 'number',
                                default => 'text',
                            };
                        @endphp
                        <x-ui-input-text
                            name="contractFieldValues.{{ $field['name'] }}"
                            :label="$field['label']"
                            wire:model="contractFieldValues.{{ $field['name'] }}"
                            type="{{ $inputType }}"
                        />
                    @endforeach
                @endif
            </div>
            <x-slot name="footer">
                <div class="flex items-center justify-end gap-2">
                    <x-ui-button variant="secondary" wire:click="closeContractFieldsModal">Abbrechen</x-ui-button>
                    <x-ui-button variant="primary" wire:click="saveContractFields">Speichern</x-ui-button>
                </div>
            </x-slot>
        </x-ui-modal>

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

        {{-- Inline Kommunikation (Email + WhatsApp) --}}
        @if(class_exists(\Platform\Crm\Livewire\InlineComms::class))
            <livewire:crm.inline-comms
                :context-type="get_class($applicant)"
                :context-id="$applicant->id"
                :subject="($primaryContact?->full_name ?? 'Bewerber #' . $applicant->id)"
                :recipients="array_values(array_filter([$primaryEmail, $primaryPhone]))"
                :key="'inline-comms-' . $applicant->id"
            />
        @endif

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
                <x-ui-input-text
                    name="contactForm.email"
                    label="E-Mail"
                    wire:model.live="contactForm.email"
                    placeholder="E-Mail-Adresse (optional)"
                    type="email"
                />

                <x-ui-input-text
                    name="contactForm.phone"
                    label="Mobilnummer"
                    wire:model.live="contactForm.phone"
                    placeholder="+49 170 1234567 (optional)"
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

    <!-- WhatsApp Template Modal -->
    <x-ui-modal
        size="lg"
        model="templateModalShow"
    >
        <x-slot name="header">
            <div class="flex items-center gap-2">
                @svg('heroicon-o-paper-airplane', 'w-5 h-5 text-emerald-600')
                WhatsApp Template senden
            </div>
        </x-slot>

        <div class="space-y-4">
            <x-ui-input-select
                name="selectedTemplateId"
                label="Template auswählen"
                :options="$this->availableWhatsAppTemplates"
                optionValue="id"
                optionLabel="label"
                :nullable="true"
                nullLabel="– Template auswählen –"
                wire:model.live="selectedTemplateId"
                required
            />

            @if($selectedTemplateId)
                @php
                    $selectedTemplate = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($selectedTemplateId);
                    $bodyText = '';
                    $hasUrlBtn = false;
                    if ($selectedTemplate) {
                        foreach ($selectedTemplate->components ?? [] as $comp) {
                            if (($comp['type'] ?? '') === 'BODY') {
                                $bodyText = $comp['text'] ?? '';
                            }
                            if (($comp['type'] ?? '') === 'BUTTONS') {
                                foreach ($comp['buttons'] ?? [] as $btn) {
                                    if (($btn['type'] ?? '') === 'URL') {
                                        $hasUrlBtn = true;
                                    }
                                }
                            }
                        }
                    }
                @endphp

                @if($bodyText)
                    <div>
                        <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Template-Text</label>
                        <div class="p-3 bg-[var(--ui-muted-5)] rounded-lg text-sm text-[var(--ui-secondary)] whitespace-pre-wrap border border-[var(--ui-border)]/40">{{ $bodyText }}</div>
                    </div>
                @endif

                @if(count($templateBodyParamDefs) > 0)
                    <div class="space-y-3">
                        <label class="block text-sm font-medium text-[var(--ui-secondary)]">Parameter</label>
                        @foreach($templateBodyParamDefs as $param)
                            <x-ui-input-text
                                name="templateParams.{{ $param['name'] }}"
                                label="{{ $param['name'] }}"
                                wire:model.live="templateParams.{{ $param['name'] }}"
                                placeholder="{{ $param['example'] ?: $param['name'] }}"
                                required
                            />
                        @endforeach
                    </div>
                @endif

                @if($hasUrlBtn)
                    <div class="flex items-center gap-2 p-3 bg-blue-50 rounded-lg border border-blue-200">
                        @svg('heroicon-o-link', 'w-4 h-4 text-blue-600')
                        <span class="text-sm text-blue-700">Der Bewerbungslink wird automatisch eingefügt.</span>
                    </div>
                @endif
            @endif
        </div>

        <x-slot name="footer">
            <div class="d-flex justify-end gap-2">
                <x-ui-button
                    type="button"
                    variant="secondary-outline"
                    wire:click="$set('templateModalShow', false)"
                >
                    Abbrechen
                </x-ui-button>
                <x-ui-button
                    type="button"
                    variant="primary"
                    wire:click="sendManualTemplate"
                    :disabled="!$selectedTemplateId || (count($templateBodyParamDefs) > 0 && collect($templateParams)->contains(''))"
                >
                    @svg('heroicon-o-paper-airplane', 'w-4 h-4')
                    Senden
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
                                        <p class="text-xs text-[var(--ui-muted)] mt-0.5">{{ $log->created_at->timezone(auth()->user()->timezone ?? config('app.timezone', 'Europe/Berlin'))->diffForHumans() }}</p>
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
