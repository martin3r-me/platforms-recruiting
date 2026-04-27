<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Bewerber" icon="heroicon-o-user-group" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Bewerber'],
        ]">
            <x-slot name="left">
                <x-ui-button variant="ghost" size="sm" wire:click="$dispatch('open-applicant-settings')">
                    @svg('heroicon-o-cog-6-tooth', 'w-4 h-4')
                    <span>Einstellungen</span>
                </x-ui-button>
            </x-slot>
            <x-ui-button variant="primary" size="sm" wire:click="openCreateModal">
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Neuer Bewerber</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        <x-ui-panel title="Übersicht" subtitle="Bewerber verwalten">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">E-Mail</th>
                            <th class="px-4 py-3">Nachrichten</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Verantwortlicher</th>
                            <th class="px-4 py-3">Fortschritt</th>
                            <th class="px-4 py-3">AutoPilot</th>
                            <th class="px-4 py-3">Beworben am</th>
                            <th class="px-4 py-3 text-right">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->applicants as $applicant)
                            @php
                                $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                $primaryEmail = $primaryContact?->emailAddresses->first()?->email_address;
                                $primaryPhone = $primaryContact?->phoneNumbers->first(fn($p) => $p->is_active)?->international
                                    ?: $primaryContact?->phoneNumbers->first(fn($p) => $p->is_active)?->raw_input;
                                $positions = $applicant->postings->map(fn($p) => $p->position)->filter()->unique('id');
                                $apColor = $this->getAutoPilotColor($applicant);
                            @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                {{-- Name + Stellen-Badges --}}
                                <td class="px-4 py-3">
                                    @if($primaryContact)
                                        <div class="space-y-1">
                                            <div class="font-semibold text-[var(--ui-secondary)] flex items-center gap-2">
                                                {{ $primaryContact->full_name }}
                                                @if($applicant->is_active)
                                                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                                @endif
                                            </div>
                                            @if($positions->isNotEmpty())
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach($positions as $pos)
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-50 text-blue-700 border border-blue-200">
                                                            {{ $pos->title }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="flex flex-wrap gap-1">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200">
                                                        Initiativ
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-[var(--ui-muted)] italic">Kein Kontakt verknüpft</span>
                                    @endif
                                </td>
                                {{-- E-Mail & Telefon --}}
                                <td class="px-4 py-3">
                                    @if($primaryEmail)
                                        <div class="text-xs text-[var(--ui-muted)] flex items-center gap-1">
                                            @svg('heroicon-o-envelope', 'w-3 h-3')
                                            {{ $primaryEmail }}
                                        </div>
                                    @endif
                                    @if($primaryPhone)
                                        <div class="text-xs text-[var(--ui-muted)] flex items-center gap-1 {{ $primaryEmail ? 'mt-0.5' : '' }}">
                                            @svg('heroicon-o-phone', 'w-3 h-3')
                                            {{ $primaryPhone }}
                                        </div>
                                    @endif
                                    @if(!$primaryEmail && !$primaryPhone)
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Nachrichten --}}
                                <td class="px-4 py-3">
                                    @php $waStatus = $this->getWhatsAppStatus($applicant); @endphp
                                    @if($waStatus['color'] !== 'none')
                                        <div class="flex items-center gap-1">
                                            <span title="{{ $waStatus['window_open'] ? '💬 Fenster offen' . ($waStatus['last_message'] ? ' — ' . $waStatus['last_message'] : '') : ($waStatus['color'] === 'yellow' ? 'WhatsApp verfügbar' . ($waStatus['last_message'] ? ' — ' . $waStatus['last_message'] : '') : 'WhatsApp unbekannt') }}"
                                                  class="inline-flex items-center {{ $waStatus['color'] === 'green' ? 'text-green-500' : ($waStatus['color'] === 'yellow' ? 'text-yellow-500' : 'text-gray-400') }}">
                                                @if($waStatus['color'] === 'green')
                                                    <span class="relative flex h-3.5 w-3.5">
                                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                                        @svg('heroicon-s-chat-bubble-left', 'relative w-3.5 h-3.5')
                                                    </span>
                                                @else
                                                    @svg('heroicon-o-chat-bubble-left', 'w-3.5 h-3.5')
                                                @endif
                                            </span>
                                        </div>
                                        @if(!empty($waStatus['recent_messages']))
                                            <div class="mt-1 space-y-0.5">
                                                @foreach($waStatus['recent_messages'] as $msg)
                                                    <div class="flex items-center gap-1 text-[10px] leading-tight {{ $msg['direction'] === 'inbound' ? 'text-green-600' : 'text-[var(--ui-muted)]' }}">
                                                        @if($msg['direction'] === 'inbound')
                                                            @svg('heroicon-o-arrow-down-left', 'w-2.5 h-2.5 flex-shrink-0')
                                                        @else
                                                            @svg('heroicon-o-arrow-up-right', 'w-2.5 h-2.5 flex-shrink-0')
                                                        @endif
                                                        <span class="truncate">{{ $msg['body'] ?: '—' }}</span>
                                                        <span class="flex-shrink-0 text-[var(--ui-muted)]/60">{{ $msg['at'] }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Status --}}
                                <td class="px-4 py-3">
                                    @if($applicant->applicantStatus)
                                        <x-ui-badge variant="primary" size="xs">{{ $applicant->applicantStatus->name }}</x-ui-badge>
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Verantwortlicher --}}
                                <td class="px-4 py-3">
                                    @if($applicant->ownedByUser)
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 bg-[var(--ui-primary)] text-[var(--ui-on-primary)] rounded-full flex items-center justify-center text-xs font-medium">
                                                {{ strtoupper(substr($applicant->ownedByUser->name, 0, 1)) }}
                                            </div>
                                            <span class="text-sm">{{ $applicant->ownedByUser->fullname ?? $applicant->ownedByUser->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Fortschritt --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-24 bg-gray-200 rounded-full h-2">
                                            <div class="bg-blue-600 h-2 rounded-full" style="width: {{ $applicant->progress }}%"></div>
                                        </div>
                                        <span class="text-xs text-[var(--ui-muted)]">{{ $applicant->progress }}%</span>
                                    </div>
                                </td>
                                {{-- AutoPilot Icon + Ampel --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        {{-- AutoPilot Icon --}}
                                        @if($applicant->auto_pilot)
                                            @if($applicant->auto_pilot_completed_at)
                                                <div class="relative" title="AutoPilot abgeschlossen">
                                                    @svg('heroicon-s-cpu-chip', 'w-5 h-5 text-green-500')
                                                </div>
                                            @else
                                                <div class="relative" title="AutoPilot aktiv">
                                                    @svg('heroicon-s-cpu-chip', 'w-5 h-5 text-[var(--ui-primary)] animate-pulse')
                                                </div>
                                            @endif
                                        @else
                                            <div title="AutoPilot inaktiv">
                                                @svg('heroicon-o-cpu-chip', 'w-5 h-5 text-[var(--ui-muted)]')
                                            </div>
                                        @endif
                                        {{-- Ampel --}}
                                        <div class="flex items-center gap-1" title="{{ $applicant->autoPilotState?->name ?? 'Kein State' }}">
                                            <span class="w-2.5 h-2.5 rounded-full {{ $apColor === 'red' ? 'bg-red-500' : 'bg-gray-200' }}"></span>
                                            <span class="w-2.5 h-2.5 rounded-full {{ $apColor === 'yellow' ? 'bg-amber-400' : 'bg-gray-200' }}"></span>
                                            <span class="w-2.5 h-2.5 rounded-full {{ $apColor === 'green' ? 'bg-emerald-500' : 'bg-gray-200' }}"></span>
                                        </div>
                                    </div>
                                </td>
                                {{-- Beworben am --}}
                                <td class="px-4 py-3">
                                    @if($applicant->applied_at)
                                        <div class="flex items-center gap-1 text-sm">
                                            @svg('heroicon-o-calendar', 'w-4 h-4 text-[var(--ui-muted)]')
                                            <span>{{ $applicant->applied_at->format('d.m.Y') }}</span>
                                        </div>
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Aktionen --}}
                                <td class="px-4 py-3 text-right">
                                    <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.applicants.show', ['applicant' => $applicant->id]) }}" wire:navigate>
                                        @svg('heroicon-o-eye', 'w-3 h-3') Anzeigen
                                    </x-ui-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        @svg('heroicon-o-user-plus', 'w-16 h-16 text-[var(--ui-muted)] mb-4')
                                        <div class="text-lg font-medium text-[var(--ui-secondary)] mb-1">Keine Bewerber gefunden</div>
                                        <div class="text-sm text-[var(--ui-muted)]">Erstelle deinen ersten Bewerber</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui-panel>
    </x-ui-page-container>

    {{-- Create Applicant Modal --}}
    <x-ui-modal wire:model="modalShow" size="md">
        <x-slot name="header">Neuer Bewerber</x-slot>
        <div class="space-y-4">
            <x-ui-input-select name="contact_id" label="CRM-Kontakt (optional)" :options="$this->availableContacts" optionValue="id" optionLabel="display_name" :nullable="true" nullLabel="Ohne Kontakt" wire:model.live="contact_id" />
            <x-ui-input-select name="posting_id" label="Ausschreibung (optional)" :options="$this->availablePostings" optionValue="id" optionLabel="title" :nullable="true" nullLabel="Initiativbewerbung" wire:model.live="posting_id" />
            <x-ui-input-select name="rec_applicant_status_id" label="Bewerbungsstatus (optional)" :options="$this->availableStatuses" optionValue="id" optionLabel="name" :nullable="true" nullLabel="Kein Status" wire:model.live="rec_applicant_status_id" />
            <x-ui-input-date name="applied_at" label="Bewerbungsdatum" wire:model.live="applied_at" :nullable="true" />
            <x-ui-input-textarea name="notes" label="Notizen" wire:model.live="notes" placeholder="Zusätzliche Notizen (optional)" rows="3" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-2">
                <x-ui-button type="button" variant="secondary-outline" wire:click="closeCreateModal">Abbrechen</x-ui-button>
                <x-ui-button type="button" variant="primary" wire:click="createApplicant">Anlegen</x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>

    <livewire:recruiting.applicant.applicant-settings-modal />

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" width="w-80" :defaultOpen="true" side="left">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">Suchen</h3>
                    <x-ui-input-text name="search" placeholder="Name, E-Mail suchen…" wire:model.live.debounce.300ms="search" />
                </div>
                <x-ui-input-select
                    name="positionFilter"
                    label="Stelle"
                    :options="$this->availablePositions"
                    optionValue="id"
                    optionLabel="title"
                    :nullable="true"
                    nullLabel="Alle Stellen"
                    wire:model.live="positionFilter"
                />
                <x-ui-input-select
                    name="activityFilter"
                    label="Tätigkeit"
                    :options="$this->availableActivities->map(fn($a) => ['value' => $a, 'label' => $a])->values()"
                    optionValue="value"
                    optionLabel="label"
                    :nullable="true"
                    nullLabel="Alle Tätigkeiten"
                    wire:model.live="activityFilter"
                />
                <div class="space-y-2">
                    <label class="text-sm font-medium text-[var(--ui-secondary)]">Beworben am</label>
                    <x-ui-input-date
                        name="appliedFromFilter"
                        placeholder="von"
                        wire:model.live="appliedFromFilter"
                        :nullable="true"
                    />
                    <x-ui-input-date
                        name="appliedToFilter"
                        placeholder="bis"
                        wire:model.live="appliedToFilter"
                        :nullable="true"
                    />
                </div>
                <x-ui-input-select
                    name="statusFilter"
                    label="Status"
                    :options="$this->availableStatuses"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="Alle Status"
                    wire:model.live="statusFilter"
                />
                <x-ui-input-select
                    name="autoPilotStateFilter"
                    label="AutoPilot-State"
                    :options="$this->availableAutoPilotStates"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="Alle States"
                    wire:model.live="autoPilotStateFilter"
                />
                <x-ui-input-select
                    name="activeFilter"
                    label="Aktiv/Inaktiv"
                    :options="[['value' => '1', 'label' => 'Aktiv'], ['value' => '0', 'label' => 'Inaktiv']]"
                    optionValue="value"
                    optionLabel="label"
                    :nullable="true"
                    nullLabel="Alle"
                    wire:model.live="activeFilter"
                />
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">Aktionen</h3>
                    <x-ui-button variant="secondary" size="sm" wire:click="openCreateModal" class="w-full justify-start">
                        @svg('heroicon-o-plus', 'w-4 h-4') <span class="ml-2">Neuer Bewerber</span>
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
