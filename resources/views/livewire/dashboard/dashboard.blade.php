<div class="h-full" wire:poll.15s="refreshDashboard">
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar icon="heroicon-o-briefcase">
            <x-slot name="title">
                <span class="flex items-center gap-2">
                    Recruiting Dashboard
                    <span class="relative flex h-2.5 w-2.5" title="Live-Updates aktiv">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                    </span>
                </span>
            </x-slot>
        </x-ui-page-navbar>
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'icon' => 'briefcase'],
            ['label' => 'Dashboard'],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container spacing="space-y-8">
        {{-- Stats --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        @svg('heroicon-o-briefcase', 'w-6 h-6 text-blue-600')
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-[var(--ui-secondary)]">{{ $this->positionCount }}</div>
                        <div class="text-sm text-[var(--ui-muted)]">Aktive Stellen</div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        @svg('heroicon-o-megaphone', 'w-6 h-6 text-green-600')
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-[var(--ui-secondary)]">{{ $this->postingCount }}</div>
                        <div class="text-sm text-[var(--ui-muted)]">Aktive Ausschreibungen</div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        @svg('heroicon-o-user-group', 'w-6 h-6 text-purple-600')
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-[var(--ui-secondary)]">{{ $this->applicantCount }}</div>
                        <div class="text-sm text-[var(--ui-muted)]">Aktive Bewerber</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Eingang --}}
        <x-ui-panel title="Eingang" subtitle="Bewerber ohne Stelle oder ohne CRM-Kontakt">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Bewerber</th>
                            <th class="px-4 py-3">Extra-Felder</th>
                            <th class="px-4 py-3">Kontakt</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->inboxApplicants as $applicant)
                            @php
                                $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                $positions = $applicant->postings->map(fn ($p) => $p->position?->title)->filter()->unique();
                                $isEnriching = in_array($applicant->id, $this->enrichingApplicantIds);
                                $extraCounts = $this->getExtraFieldCounts($applicant);
                                $waStatus = $this->getWhatsAppStatus($applicant);
                            @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                {{-- Bewerber + Stelle --}}
                                <td class="px-4 py-2.5">
                                    <div class="flex items-start gap-2.5">
                                        <div class="mt-1.5 flex-shrink-0">
                                            <span class="relative flex h-2.5 w-2.5" title="{{ $isEnriching ? 'Enrichment läuft...' : 'Neu im Eingang' }}">
                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                                            </span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-[var(--ui-secondary)]">
                                                    {{ $primaryContact?->full_name ?? 'Bewerber #' . $applicant->id }}
                                                </span>
                                                @if($isEnriching)
                                                    <x-ui-badge variant="danger" size="xs">Enrichment</x-ui-badge>
                                                @endif
                                                {{-- WhatsApp Status Icon --}}
                                                @if($waStatus['color'] !== 'none')
                                                    <span title="{{ $waStatus['window_open'] ? 'WhatsApp Fenster offen' : ($waStatus['color'] === 'yellow' ? 'WhatsApp verfügbar' : 'WhatsApp unbekannt') }}"
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
                                                @endif
                                            </div>
                                            @if($positions->isNotEmpty())
                                                <div class="text-xs text-[var(--ui-muted)] truncate">{{ $positions->implode(', ') }}</div>
                                            @else
                                                <div x-data="{ val: '' }" class="mt-0.5">
                                                    <x-ui-input-select
                                                        name="posting_{{ $applicant->id }}"
                                                        :options="$this->availablePostings"
                                                        optionValue="id"
                                                        optionLabel="title"
                                                        :nullable="true"
                                                        nullLabel="– Stelle wählen –"
                                                        size="sm"
                                                        x-model="val"
                                                        x-on:change="if (val) { $wire.assignPosting({{ $applicant->id }}, parseInt(val)); val = ''; }"
                                                    />
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                {{-- Extra-Felder --}}
                                <td class="px-4 py-2.5">
                                    @if($extraCounts['total'] > 0)
                                        <span class="text-xs {{ $extraCounts['filled'] === $extraCounts['total'] ? 'text-green-600 font-medium' : 'text-[var(--ui-muted)]' }}">
                                            {{ $extraCounts['filled'] }}/{{ $extraCounts['total'] }}
                                        </span>
                                    @else
                                        <span class="text-xs text-[var(--ui-muted)]">&ndash;</span>
                                    @endif
                                </td>
                                {{-- Kontakt --}}
                                <td class="px-4 py-2.5">
                                    @if($primaryContact)
                                        <span class="text-sm text-[var(--ui-secondary)]">{{ $primaryContact->full_name }}</span>
                                    @else
                                        <x-ui-badge variant="warning" size="xs">Kein Kontakt</x-ui-badge>
                                    @endif
                                </td>
                                {{-- Aktion --}}
                                <td class="px-4 py-2.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            wire:click="dismissApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber wirklich aussortieren?"
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                                            title="Aussortieren"
                                        >
                                            @svg('heroicon-o-x-mark', 'w-3.5 h-3.5')
                                        </button>
                                        <x-ui-button size="sm" variant="secondary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                            @svg('heroicon-o-arrow-right', 'w-4 h-4')
                                        </x-ui-button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                    <div class="flex flex-col items-center gap-2">
                                        @svg('heroicon-o-inbox', 'w-8 h-8 text-[var(--ui-muted)]/50')
                                        <span>Eingang leer — alle Bewerber sind zugeordnet</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui-panel>

        {{-- Manuelle Prüfung --}}
        @if($this->needsReviewApplicants->isNotEmpty())
        <x-ui-panel title="Manuelle Prüfung" subtitle="Enrichment durchgelaufen, aber kein CRM-Kontakt verknüpft — manuelle Zuordnung nötig">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Bewerber</th>
                            <th class="px-4 py-3">Extra-Felder</th>
                            <th class="px-4 py-3">Kontakt zuordnen</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @foreach($this->needsReviewApplicants as $applicant)
                            @php
                                $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                $positions = $applicant->postings->map(fn ($p) => $p->position?->title)->filter()->unique();
                                $extraCounts = $this->getExtraFieldCounts($applicant);
                            @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                <td class="px-4 py-2.5">
                                    <div class="flex items-start gap-2.5">
                                        <div class="mt-1.5 flex-shrink-0">
                                            <span class="relative flex h-2.5 w-2.5" title="Manuelle Prüfung nötig">
                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-orange-500"></span>
                                            </span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-[var(--ui-secondary)]">
                                                    {{ $primaryContact?->full_name ?? 'Bewerber #' . $applicant->id }}
                                                </span>
                                                <x-ui-badge variant="warning" size="xs">Kein Kontakt</x-ui-badge>
                                            </div>
                                            @if($positions->isNotEmpty())
                                                <div class="text-xs text-[var(--ui-muted)] truncate">{{ $positions->implode(', ') }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    @if($extraCounts['total'] > 0)
                                        <span class="text-xs {{ $extraCounts['filled'] === $extraCounts['total'] ? 'text-green-600 font-medium' : 'text-[var(--ui-muted)]' }}">
                                            {{ $extraCounts['filled'] }}/{{ $extraCounts['total'] }}
                                        </span>
                                    @else
                                        <span class="text-xs text-[var(--ui-muted)]">&ndash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    @if($primaryContact)
                                        <span class="text-sm text-[var(--ui-secondary)]">{{ $primaryContact->full_name }}</span>
                                    @else
                                        <x-ui-badge variant="warning" size="xs">Kein Kontakt</x-ui-badge>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            wire:click="retryEnrichment({{ $applicant->id }})"
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors"
                                            title="Erneut anreichern"
                                        >
                                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                        </button>
                                        <button
                                            wire:click="dismissApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber wirklich aussortieren?"
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                                            title="Aussortieren"
                                        >
                                            @svg('heroicon-o-x-mark', 'w-3.5 h-3.5')
                                        </button>
                                        <x-ui-button size="sm" variant="secondary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                            @svg('heroicon-o-arrow-right', 'w-4 h-4')
                                        </x-ui-button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui-panel>
        @endif

        {{-- In Bearbeitung --}}
        <x-ui-panel title="In Bearbeitung" subtitle="Enriched — AutoPilot läuft oder wartet auf Daten">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Phase</th>
                            <th class="px-4 py-3">Extra-Felder</th>
                            <th class="px-4 py-3">AutoPilot</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->activeApplicants as $applicant)
                            @php
                                $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                $positions = $applicant->postings->map(fn ($p) => $p->position?->title)->filter()->unique();
                                $extraCounts = $this->getExtraFieldCounts($applicant);
                                $primaryEmail = $primaryContact?->emailAddresses?->first()?->email_address;
                                $waStatus = $this->getWhatsAppStatus($applicant);
                                $phase = $applicant->phase;
                            @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                <td class="px-4 py-2.5">
                                    <div class="flex items-start gap-2.5">
                                        <div class="mt-1.5 flex-shrink-0">
                                            <span class="relative flex h-2.5 w-2.5" title="In Bearbeitung">
                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"></span>
                                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-yellow-500"></span>
                                            </span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-[var(--ui-secondary)] truncate">
                                                    {{ $primaryContact?->full_name ?? 'Bewerber #' . $applicant->id }}
                                                </span>
                                                @if($waStatus['color'] !== 'none')
                                                    <span title="{{ $waStatus['window_open'] ? 'WhatsApp Fenster offen' : ($waStatus['color'] === 'yellow' ? 'WhatsApp verfügbar' : 'WhatsApp unbekannt') }}"
                                                          class="inline-flex items-center flex-shrink-0 {{ $waStatus['color'] === 'green' ? 'text-green-500' : ($waStatus['color'] === 'yellow' ? 'text-yellow-500' : 'text-gray-400') }}">
                                                        @if($waStatus['color'] === 'green')
                                                            <span class="relative flex h-3.5 w-3.5">
                                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                                                @svg('heroicon-s-chat-bubble-left', 'relative w-3.5 h-3.5')
                                                            </span>
                                                        @else
                                                            @svg('heroicon-o-chat-bubble-left', 'w-3.5 h-3.5')
                                                        @endif
                                                    </span>
                                                @endif
                                            </div>
                                            @if($positions->isNotEmpty())
                                                <div class="text-xs text-[var(--ui-muted)] truncate">{{ $positions->implode(', ') }}</div>
                                            @endif
                                            @if($primaryEmail)
                                                <div class="text-xs text-[var(--ui-muted)] truncate">{{ $primaryEmail }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    @if($phase)
                                        <x-ui-badge variant="info" size="xs">{{ $phase->name }}</x-ui-badge>
                                    @else
                                        <span class="text-xs text-[var(--ui-muted)]">&ndash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    @if($extraCounts['total'] > 0)
                                        <span class="text-xs {{ $extraCounts['filled'] === $extraCounts['total'] ? 'text-green-600 font-medium' : 'text-[var(--ui-muted)]' }}">
                                            {{ $extraCounts['filled'] }}/{{ $extraCounts['total'] }}
                                        </span>
                                    @else
                                        <span class="text-xs text-[var(--ui-muted)]">&ndash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    @php
                                        $isActive = $applicant->auto_pilot;
                                        $channelType = $applicant->preferredCommsChannel?->type;
                                    @endphp
                                    <button
                                        wire:click="toggleAutoPilot({{ $applicant->id }})"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-1 text-xs transition-colors
                                            {{ $isActive ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'bg-[var(--ui-muted-5)] text-[var(--ui-muted)] hover:bg-[var(--ui-muted-10)] hover:text-[var(--ui-secondary)]' }}"
                                        title="AutoPilot{{ $isActive ? ' (aktiv via ' . ($channelType ?? '?') . ')' : '' }}"
                                    >
                                        @if($isActive)
                                            <span class="relative flex h-2 w-2">
                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                            </span>
                                        @endif
                                        @svg($channelType === 'whatsapp' ? 'heroicon-o-chat-bubble-left' : 'heroicon-o-envelope', 'w-3.5 h-3.5')
                                    </button>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if($phase && !$phase->auto_advance && $extraCounts['total'] > 0 && $extraCounts['filled'] === $extraCounts['total'])
                                            <button
                                                wire:click="advanceToNextPhase({{ $applicant->id }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors"
                                                title="Zur nächsten Phase"
                                            >
                                                @svg('heroicon-o-arrow-right-circle', 'w-3.5 h-3.5')
                                            </button>
                                        @endif
                                        <button
                                            wire:click="dismissApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber wirklich aussortieren?"
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                                            title="Aussortieren"
                                        >
                                            @svg('heroicon-o-x-mark', 'w-3.5 h-3.5')
                                        </button>
                                        <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                            Anzeigen
                                        </x-ui-button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                    <div class="flex flex-col items-center gap-2">
                                        @svg('heroicon-o-clock', 'w-8 h-8 text-[var(--ui-muted)]/50')
                                        <span>Keine Bewerber in Bearbeitung</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui-panel>

        {{-- Abgeschlossene Bewerbungen --}}
        <x-ui-panel title="Abgeschlossen" subtitle="Alle Phasen durchlaufen">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Stelle</th>
                            <th class="px-4 py-3">Eingegangen</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->completedApplicants as $applicant)
                            @php
                                $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                $positions = $applicant->postings->map(fn ($p) => $p->position?->title)->filter()->unique();
                                $primaryEmail = $primaryContact?->emailAddresses?->first()?->email_address;
                                $waStatus = $this->getWhatsAppStatus($applicant);
                            @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                {{-- Name + Email --}}
                                <td class="px-4 py-2.5">
                                    <div class="flex items-start gap-2.5">
                                        <div class="mt-1.5 flex-shrink-0">
                                            <div class="w-2.5 h-2.5 rounded-full bg-green-500"></div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-[var(--ui-secondary)] truncate">
                                                    {{ $primaryContact?->full_name ?? 'Bewerber #' . $applicant->id }}
                                                </span>
                                                {{-- WhatsApp Status Icon --}}
                                                @if($waStatus['color'] !== 'none')
                                                    <span title="{{ $waStatus['window_open'] ? 'WhatsApp Fenster offen' : ($waStatus['color'] === 'yellow' ? 'WhatsApp verfügbar' : 'WhatsApp unbekannt') }}"
                                                          class="inline-flex items-center flex-shrink-0 {{ $waStatus['color'] === 'green' ? 'text-green-500' : ($waStatus['color'] === 'yellow' ? 'text-yellow-500' : 'text-gray-400') }}">
                                                        @if($waStatus['color'] === 'green')
                                                            <span class="relative flex h-3.5 w-3.5">
                                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                                                @svg('heroicon-s-chat-bubble-left', 'relative w-3.5 h-3.5')
                                                            </span>
                                                        @else
                                                            @svg('heroicon-o-chat-bubble-left', 'w-3.5 h-3.5')
                                                        @endif
                                                    </span>
                                                @endif
                                            </div>
                                            @if($primaryEmail)
                                                <div class="text-xs text-[var(--ui-muted)] truncate">{{ $primaryEmail }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                {{-- Stelle --}}
                                <td class="px-4 py-2.5">
                                    @if($positions->isNotEmpty())
                                        <span class="text-sm text-[var(--ui-secondary)]">{{ $positions->implode(', ') }}</span>
                                    @else
                                        <span class="text-[var(--ui-muted)]">&ndash;</span>
                                    @endif
                                </td>
                                {{-- Eingegangen --}}
                                <td class="px-4 py-2.5 text-sm text-[var(--ui-muted)]">
                                    {{ $applicant->created_at?->format('d.m.Y') }}
                                </td>
                                {{-- Aktion --}}
                                <td class="px-4 py-2.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            wire:click="retryEnrichment({{ $applicant->id }})"
                                            wire:confirm="Bewerber zurück in den Eingang verschieben und Enrichment erneut starten?"
                                            class="inline-flex items-center gap-1.5 rounded px-2.5 py-1.5 text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 transition-colors"
                                            title="Zurück in den Eingang"
                                        >
                                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                        </button>
                                        <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                            Anzeigen
                                        </x-ui-button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                    <div class="flex flex-col items-center gap-2">
                                        @svg('heroicon-o-check-circle', 'w-8 h-8 text-[var(--ui-muted)]/50')
                                        <span>Keine abgeschlossenen Bewerbungen</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui-panel>
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Schnellzugriff" width="w-80" :defaultOpen="true" side="left">
            <div class="p-6 space-y-4">
                <x-ui-button variant="primary" size="sm" class="w-full justify-start" href="{{ route('recruiting.positions.index') }}" wire:navigate>
                    @svg('heroicon-o-briefcase', 'w-4 h-4') <span class="ml-2">Stellen</span>
                </x-ui-button>
                <x-ui-button variant="secondary" size="sm" class="w-full justify-start" href="{{ route('recruiting.postings.index') }}" wire:navigate>
                    @svg('heroicon-o-megaphone', 'w-4 h-4') <span class="ml-2">Ausschreibungen</span>
                </x-ui-button>
                <x-ui-button variant="secondary" size="sm" class="w-full justify-start" href="{{ route('recruiting.applicants.index') }}" wire:navigate>
                    @svg('heroicon-o-user-group', 'w-4 h-4') <span class="ml-2">Bewerber</span>
                </x-ui-button>
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
</div>
