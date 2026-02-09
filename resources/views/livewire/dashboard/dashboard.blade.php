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

        {{-- Recent Applicants --}}
        <x-ui-panel title="Neueste Bewerber" subtitle="Die letzten 5 Bewerbungen">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Datum</th>
                            <th class="px-4 py-3 text-right">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->recentApplicants as $applicant)
                            @php $primaryContact = $applicant->crmContactLinks->first()?->contact; @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                <td class="px-4 py-3 font-medium text-[var(--ui-secondary)]">
                                    {{ $primaryContact?->full_name ?? 'Kein Kontakt' }}
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
                                <td colspan="4" class="px-4 py-8 text-center text-[var(--ui-muted)]">Keine Bewerber vorhanden</td>
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
