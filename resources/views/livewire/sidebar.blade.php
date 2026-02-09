<div>
    {{-- Modul Header --}}
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Recruiting
    </div>

    {{-- Abschnitt: Dashboard --}}
    <x-ui-sidebar-list label="Übersicht">
        <x-ui-sidebar-item :href="route('recruiting.dashboard')">
            @svg('heroicon-o-home', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dashboard</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    {{-- Abschnitt: Recruiting --}}
    <x-ui-sidebar-list label="Recruiting">
        <x-ui-sidebar-item :href="route('recruiting.positions.index')">
            @svg('heroicon-o-briefcase', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Stellen</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.postings.index')">
            @svg('heroicon-o-megaphone', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Ausschreibungen</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.applicants.index')">
            @svg('heroicon-o-user-group', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Bewerber</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    {{-- Abschnitt: Einstellungen --}}
    <x-ui-sidebar-list label="Einstellungen">
        <x-ui-sidebar-item :href="route('recruiting.applicant-statuses.index')">
            @svg('heroicon-o-tag', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Bewerber-Status</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('recruiting.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Dashboard">
                @svg('heroicon-o-home', 'w-5 h-5')
            </a>
            <a href="{{ route('recruiting.positions.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Stellen">
                @svg('heroicon-o-briefcase', 'w-5 h-5')
            </a>
            <a href="{{ route('recruiting.postings.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Ausschreibungen">
                @svg('heroicon-o-megaphone', 'w-5 h-5')
            </a>
            <a href="{{ route('recruiting.applicants.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Bewerber">
                @svg('heroicon-o-user-group', 'w-5 h-5')
            </a>
            <a href="{{ route('recruiting.applicant-statuses.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Bewerber-Status">
                @svg('heroicon-o-tag', 'w-5 h-5')
            </a>
        </div>
    </div>

    {{-- Abschnitt: Neueste Bewerber --}}
    <div>
        <div class="mt-2" x-show="!collapsed">
            @if($this->recentApplicants->count() > 0)
                <x-ui-sidebar-list label="Neueste Bewerber">
                    @foreach($this->recentApplicants as $applicant)
                        <x-ui-sidebar-item :href="route('recruiting.applicants.show', ['applicant' => $applicant->id])">
                            @svg('heroicon-o-user', 'w-5 h-5 flex-shrink-0 text-[var(--ui-secondary)]')
                            <span class="truncate text-sm ml-2">{{ $applicant->crmContactLinks->first()?->contact?->full_name ?? 'Unbekannt' }}</span>
                        </x-ui-sidebar-item>
                    @endforeach
                </x-ui-sidebar-list>
            @else
                <div class="px-3 py-1 text-xs text-[var(--ui-muted)]">Keine Bewerber</div>
            @endif
        </div>
    </div>

    {{-- Statistiken --}}
    <div x-show="!collapsed" class="mt-4 p-3 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
        <div class="text-xs font-semibold text-[var(--ui-secondary)] uppercase tracking-wider mb-2">Übersicht</div>
        <div class="space-y-2">
            <div class="flex justify-between items-center text-sm">
                <span class="text-[var(--ui-muted)]">Stellen</span>
                <span class="font-medium text-[var(--ui-secondary)]">{{ $this->stats['total_positions'] }}</span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-[var(--ui-muted)]">Aktive Stellen</span>
                <span class="font-medium text-green-600">{{ $this->stats['active_positions'] }}</span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-[var(--ui-muted)]">Ausschreibungen</span>
                <span class="font-medium text-[var(--ui-secondary)]">{{ $this->stats['total_postings'] }}</span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-[var(--ui-muted)]">Aktive Ausschreibungen</span>
                <span class="font-medium text-green-600">{{ $this->stats['active_postings'] }}</span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-[var(--ui-muted)]">Bewerber</span>
                <span class="font-medium text-[var(--ui-secondary)]">{{ $this->stats['total_applicants'] }}</span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-[var(--ui-muted)]">Aktive Bewerber</span>
                <span class="font-medium text-green-600">{{ $this->stats['active_applicants'] }}</span>
            </div>
        </div>
    </div>
</div>
