<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Recruiting Dashboard" icon="heroicon-o-briefcase" />
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
                            <th class="px-4 py-3">Stelle</th>
                            <th class="px-4 py-3">Kontakt</th>
                            <th class="px-4 py-3">Datum</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->inboxApplicants as $applicant)
                            @php
                                $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                $positions = $applicant->postings->map(fn ($p) => $p->position?->title)->filter()->unique();
                            @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-2.5 h-2.5 rounded-full bg-red-500 flex-shrink-0"></div>
                                        <span class="font-medium text-[var(--ui-secondary)]">
                                            {{ $primaryContact?->full_name ?? 'Bewerber #' . $applicant->id }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if($applicant->postings->isNotEmpty())
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($positions as $posTitle)
                                                <x-ui-badge variant="info" size="xs">{{ $posTitle }}</x-ui-badge>
                                            @endforeach
                                        </div>
                                    @else
                                        <div x-data="{ val: '' }">
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
                                </td>
                                <td class="px-4 py-3">
                                    @if($primaryContact)
                                        <span class="text-sm text-[var(--ui-secondary)]">{{ $primaryContact->full_name }}</span>
                                    @else
                                        <div x-data="{ val: '' }">
                                            <x-ui-input-select
                                                name="contact_{{ $applicant->id }}"
                                                :options="$this->availableContacts"
                                                optionValue="id"
                                                optionLabel="display_name"
                                                :nullable="true"
                                                nullLabel="– Kontakt wählen –"
                                                size="sm"
                                                x-model="val"
                                                x-on:change="if (val) { $wire.linkExistingContact({{ $applicant->id }}, parseInt(val)); val = ''; }"
                                            />
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-[var(--ui-muted)]">
                                    {{ $applicant->created_at->format('d.m.Y') }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui-button size="sm" variant="secondary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                        @svg('heroicon-o-arrow-right', 'w-4 h-4')
                                    </x-ui-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-[var(--ui-muted)]">
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

        {{-- Zugeordnete Bewerber --}}
        <x-ui-panel title="Bewerber" subtitle="Bewerber mit Stelle und CRM-Kontakt">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Stelle</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Datum</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->assignedApplicants as $applicant)
                            @php
                                $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                $positions = $applicant->postings->map(fn ($p) => $p->position?->title)->filter()->unique();
                            @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $applicant->progress === 100 ? 'bg-green-500' : 'bg-yellow-500' }}"></div>
                                        <div class="min-w-0">
                                            <div class="font-medium text-[var(--ui-secondary)] truncate">
                                                {{ $primaryContact?->full_name ?? 'Bewerber #' . $applicant->id }}
                                            </div>
                                            @if($primaryContact?->emailAddresses?->first())
                                                <div class="text-xs text-[var(--ui-muted)] truncate">{{ $primaryContact->emailAddresses->first()->email_address }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($positions as $posTitle)
                                            <x-ui-badge variant="info" size="xs">{{ $posTitle }}</x-ui-badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if($applicant->applicantStatus)
                                        <x-ui-badge variant="primary" size="xs">{{ $applicant->applicantStatus->name }}</x-ui-badge>
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-[var(--ui-muted)]">
                                    {{ $applicant->created_at->format('d.m.Y') }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                        Anzeigen
                                    </x-ui-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-[var(--ui-muted)]">Keine zugeordneten Bewerber</td>
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
</x-ui-page>
