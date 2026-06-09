<div wire:poll.30s>
    {{-- Modul Header --}}
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Recruiting
    </div>

    {{-- Abschnitt: Dashboard --}}
    <x-ui-sidebar-list label="Übersicht">
        <x-ui-sidebar-item :href="route('recruiting.dashboard')">
            @svg('heroicon-o-home', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dashboard</span>
            <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-[var(--ui-muted-5)] text-[var(--ui-muted)]">{{ $this->stats['active_applicants'] }}</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.dashboard.legacy')">
            @svg('heroicon-o-archive-box', 'w-4 h-4 text-[var(--ui-muted)]')
            <span class="ml-2 text-sm text-[var(--ui-muted)]">Dashboard-alt</span>
            @if(($this->stats['legacy_applicants'] ?? 0) > 0)
                <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-[var(--ui-muted-5)] text-[var(--ui-muted)]">{{ $this->stats['legacy_applicants'] }}</span>
            @endif
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.dashboard.parked')">
            @svg('heroicon-o-pause', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Geparkt</span>
            @if($this->stats['parked_applicants'] > 0)
                <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-600">{{ $this->stats['parked_applicants'] }}</span>
            @endif
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.dashboard.hr-desk')">
            @svg('heroicon-o-shield-check', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">HR-Schreibtisch</span>
            @if($this->stats['hr_desk_applicants'] > 0)
                <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-600">{{ $this->stats['hr_desk_applicants'] }}</span>
            @endif
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.inbox.index')">
            @svg('heroicon-o-inbox', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Eingangs-Inbox</span>
            @if($this->stats['unrouted_applicants'] > 0)
                <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-orange-50 text-orange-600">{{ $this->stats['unrouted_applicants'] }}</span>
            @endif
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
        <x-ui-sidebar-item :href="route('recruiting.employees.index')">
            @svg('heroicon-o-identification', 'w-4 h-4 text-emerald-600')
            <span class="ml-2 text-sm">Mitarbeiter</span>
            @if($this->stats['active_employees'] > 0)
                <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">{{ $this->stats['active_employees'] }}</span>
            @endif
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.employees.payroll-changes')">
            @svg('heroicon-o-banknotes', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Lohnrelevante Änderungen</span>
            @if(($this->stats['pending_payroll_changes'] ?? 0) > 0)
                <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-600">{{ $this->stats['pending_payroll_changes'] }}</span>
            @endif
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.interview-schedule.index')">
            @svg('heroicon-o-calendar-days', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Interview-Termine</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.interview-types.index')">
            @svg('heroicon-o-chat-bubble-left-right', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Gesprächsarten</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.interview-waitlist.index')">
            @svg('heroicon-o-clock', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Warteliste</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.contracts.index')">
            @svg('heroicon-o-document-text', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Verträge</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('recruiting.contract-templates.index')">
            @svg('heroicon-o-document-duplicate', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Vertragsvorlagen</span>
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
            <a href="{{ route('recruiting.employees.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-emerald-600 hover:bg-[var(--ui-muted-5)]" title="Mitarbeiter">
                @svg('heroicon-o-identification', 'w-5 h-5')
            </a>
            <a href="{{ route('recruiting.interview-schedule.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Interview-Termine">
                @svg('heroicon-o-calendar-days', 'w-5 h-5')
            </a>
            <a href="{{ route('recruiting.interview-types.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Gesprächsarten">
                @svg('heroicon-o-chat-bubble-left-right', 'w-5 h-5')
            </a>
            <a href="{{ route('recruiting.contracts.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Verträge">
                @svg('heroicon-o-document-text', 'w-5 h-5')
            </a>
            <a href="{{ route('recruiting.contract-templates.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="Vertragsvorlagen">
                @svg('heroicon-o-document-duplicate', 'w-5 h-5')
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
