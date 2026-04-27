<div class="h-full" wire:poll.15s="refreshDashboard">
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar icon="heroicon-o-briefcase">
            <x-slot name="title">
                <span class="flex items-center gap-2">
                    {{ $this->showParked ? 'Geparkte Bewerber' : 'Recruiting Dashboard' }}
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
            ['label' => $this->showParked ? 'Geparkt' : 'Dashboard'],
        ]">
            <x-slot name="left">
                <select wire:model.live="positionFilter"
                        class="text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                    <option value="">Alle Stellen</option>
                    @foreach($this->positions as $pos)
                        <option value="{{ $pos->id }}">{{ $pos->title }}</option>
                    @endforeach
                </select>
                <select wire:model.live="activityFilter"
                        class="text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                    <option value="">Alle Tätigkeiten</option>
                    @foreach($this->availableActivities as $act)
                        <option value="{{ $act }}">{{ $act }}</option>
                    @endforeach
                </select>
                @if($this->phases->isNotEmpty())
                    <select wire:model.live="phaseFilter"
                            class="text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                        <option value="">Alle Phasen</option>
                        @foreach($this->phases as $phase)
                            <option value="{{ $this->positionFilter ? $phase->id : $phase->order }}">{{ $phase->name }}</option>
                        @endforeach
                    </select>
                @endif
                {{-- Datums-Range mit Quick-Buttons --}}
                <div class="flex items-center gap-1 flex-wrap">
                    <button type="button" wire:click="applyDatePreset('this_week')"
                            class="text-xs px-2 py-1 rounded border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] transition-colors">Woche</button>
                    <button type="button" wire:click="applyDatePreset('this_month')"
                            class="text-xs px-2 py-1 rounded border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] transition-colors">Monat</button>
                    <button type="button" wire:click="applyDatePreset('last_month')"
                            class="text-xs px-2 py-1 rounded border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] transition-colors">Letzter Monat</button>
                    <button type="button" wire:click="applyDatePreset('q1')"
                            class="text-xs px-2 py-1 rounded border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] transition-colors">Q1</button>
                    <button type="button" wire:click="applyDatePreset('q2')"
                            class="text-xs px-2 py-1 rounded border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] transition-colors">Q2</button>
                    <button type="button" wire:click="applyDatePreset('q3')"
                            class="text-xs px-2 py-1 rounded border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] transition-colors">Q3</button>
                    <button type="button" wire:click="applyDatePreset('q4')"
                            class="text-xs px-2 py-1 rounded border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] transition-colors">Q4</button>
                    <input type="date" wire:model.live="filterFrom" title="Von"
                           class="text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] px-2 py-1 focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]" />
                    <span class="text-xs text-[var(--ui-muted)]">bis</span>
                    <input type="date" wire:model.live="filterTo" title="Bis"
                           class="text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] px-2 py-1 focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]" />
                    @if($this->filterFrom || $this->filterTo)
                        <button type="button" wire:click="applyDatePreset('clear')"
                                class="text-xs text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] transition-colors px-1"
                                title="Zeitraum zurücksetzen">
                            @svg('heroicon-o-x-mark', 'w-4 h-4')
                        </button>
                    @endif
                </div>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container spacing="space-y-8">
        {{-- Stuck-Indikatoren (Block C): operativ relevant, gehört nach oben --}}
        @if(!$this->showParked)
            @php $stuck = $this->stuckCounts; @endphp
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white rounded-lg border {{ $stuck['autopilot_stuck'] > 0 ? 'border-amber-300' : 'border-[var(--ui-border)]/60' }} p-5">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 {{ $stuck['autopilot_stuck'] > 0 ? 'bg-amber-100' : 'bg-gray-100' }} rounded-lg flex items-center justify-center flex-shrink-0">
                            @svg('heroicon-o-cpu-chip', 'w-5 h-5 ' . ($stuck['autopilot_stuck'] > 0 ? 'text-amber-600' : 'text-gray-400'))
                        </div>
                        <div class="min-w-0">
                            <div class="text-2xl font-bold {{ $stuck['autopilot_stuck'] > 0 ? 'text-amber-700' : 'text-[var(--ui-secondary)]' }}">{{ $stuck['autopilot_stuck'] }}</div>
                            <div class="text-sm font-medium text-[var(--ui-secondary)]">AutoPilot hängt</div>
                            <div class="text-xs text-[var(--ui-muted)]">letzter Reminder &gt; 5 Tage</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg border {{ $stuck['interview_no_contract'] > 0 ? 'border-orange-300' : 'border-[var(--ui-border)]/60' }} p-5">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 {{ $stuck['interview_no_contract'] > 0 ? 'bg-orange-100' : 'bg-gray-100' }} rounded-lg flex items-center justify-center flex-shrink-0">
                            @svg('heroicon-o-calendar-days', 'w-5 h-5 ' . ($stuck['interview_no_contract'] > 0 ? 'text-orange-600' : 'text-gray-400'))
                        </div>
                        <div class="min-w-0">
                            <div class="text-2xl font-bold {{ $stuck['interview_no_contract'] > 0 ? 'text-orange-700' : 'text-[var(--ui-secondary)]' }}">{{ $stuck['interview_no_contract'] }}</div>
                            <div class="text-sm font-medium text-[var(--ui-secondary)]">Interview gebucht, kein Vertrag</div>
                            <div class="text-xs text-[var(--ui-muted)]">Booking &gt; 3 Tage offen</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg border {{ $stuck['contract_sent_not_signed'] > 0 ? 'border-red-300' : 'border-[var(--ui-border)]/60' }} p-5">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 {{ $stuck['contract_sent_not_signed'] > 0 ? 'bg-red-100' : 'bg-gray-100' }} rounded-lg flex items-center justify-center flex-shrink-0">
                            @svg('heroicon-o-document-text', 'w-5 h-5 ' . ($stuck['contract_sent_not_signed'] > 0 ? 'text-red-600' : 'text-gray-400'))
                        </div>
                        <div class="min-w-0">
                            <div class="text-2xl font-bold {{ $stuck['contract_sent_not_signed'] > 0 ? 'text-red-700' : 'text-[var(--ui-secondary)]' }}">{{ $stuck['contract_sent_not_signed'] }}</div>
                            <div class="text-sm font-medium text-[var(--ui-secondary)]">Vertrag versendet, nicht unterschrieben</div>
                            <div class="text-xs text-[var(--ui-muted)]">versendet &gt; 3 Tage</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Stats --}}
        @if($this->positionFilter)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-6">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            @svg('heroicon-o-megaphone', 'w-6 h-6 text-green-600')
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-[var(--ui-secondary)]">{{ $this->postingCount }}</div>
                            <div class="text-sm text-[var(--ui-muted)]">Ausschreibungen</div>
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
                            <div class="text-sm text-[var(--ui-muted)]">Bewerber</div>
                        </div>
                    </div>
                </div>
            </div>
        @else
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
        @endif

        {{-- Time-to-Hire (Bewerbung → Vertrag unterschrieben) --}}
        @php $tth = $this->timeToHire; @endphp
        @if($tth['count'] > 0)
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center">
                            @svg('heroicon-o-clock', 'w-5 h-5 text-emerald-600')
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-[var(--ui-secondary)]">{{ $tth['median'] }} <span class="text-sm font-normal text-[var(--ui-muted)]">Tage</span></div>
                            <div class="text-sm text-[var(--ui-muted)]">Time-to-Hire (Median)</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-emerald-50 rounded-lg flex items-center justify-center">
                            @svg('heroicon-o-chart-bar', 'w-5 h-5 text-emerald-500')
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-[var(--ui-secondary)]">{{ $tth['avg'] }} <span class="text-sm font-normal text-[var(--ui-muted)]">Tage</span></div>
                            <div class="text-sm text-[var(--ui-muted)]">Time-to-Hire (Ø)</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg border border-[var(--ui-border)]/60 p-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center">
                            @svg('heroicon-o-check-badge', 'w-5 h-5 text-blue-500')
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-[var(--ui-secondary)]">{{ $tth['count'] }}</div>
                            <div class="text-sm text-[var(--ui-muted)]">Verträge unterschrieben (im Zeitraum)</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Statistik nach Standort/Stelle --}}
        @php
            $rangeSubtitle = ($this->filterFrom || $this->filterTo)
                ? trim(($this->filterFrom ? \Carbon\Carbon::parse($this->filterFrom)->format('d.m.Y') : '…') . ' – ' . ($this->filterTo ? \Carbon\Carbon::parse($this->filterTo)->format('d.m.Y') : '…'))
                : 'Alle Zeiträume';
        @endphp
        @if(!$this->showParked && count($this->positionStats) > 0)
        <x-ui-panel title="Übersicht nach Stelle" :subtitle="$rangeSubtitle">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Stelle</th>
                            <th class="px-4 py-3 text-center">Bewerbungen</th>
                            <th class="px-4 py-3 text-center">Kontaktdaten</th>
                            <th class="px-4 py-3 text-center">Registriert</th>
                            <th class="px-4 py-3 text-center">In Schulung</th>
                            <th class="px-4 py-3 text-center">Bestätigt</th>
                            <th class="px-4 py-3 text-center">Vertrag unterschrieben</th>
                            <th class="px-4 py-3 text-center">Conversion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @foreach($this->positionStats as $stat)
                        <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                            <td class="px-4 py-2.5 font-medium text-[var(--ui-secondary)]">
                                {{ $stat['position_title'] }}
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['total'] > 0 ? 'bg-blue-50 text-blue-700' : 'bg-gray-50 text-gray-400' }}">
                                    {{ $stat['total'] }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['contacted'] > 0 ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-50 text-gray-400' }}">
                                    {{ $stat['contacted'] }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['completed'] > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-50 text-gray-400' }}">
                                    {{ $stat['completed'] }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['booked'] > 0 ? 'bg-purple-50 text-purple-700' : 'bg-gray-50 text-gray-400' }}">
                                    {{ $stat['booked'] }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['confirmed'] > 0 ? 'bg-green-50 text-green-700' : 'bg-gray-50 text-gray-400' }}">
                                    {{ $stat['confirmed'] }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['signed'] > 0 ? 'bg-teal-50 text-teal-700' : 'bg-gray-50 text-gray-400' }}">
                                    {{ $stat['signed'] }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center text-xs font-semibold {{ $stat['conversion'] >= 50 ? 'text-emerald-600' : ($stat['conversion'] >= 20 ? 'text-amber-600' : 'text-[var(--ui-muted)]') }}">
                                {{ $stat['conversion'] }} %
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-bold border-t-2 border-[var(--ui-border)]">
                            <td class="px-4 py-2.5 text-[var(--ui-secondary)]">Gesamt</td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-blue-100 text-blue-800">
                                    {{ $this->positionStatsUniqueTotals['total'] ?? 0 }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-indigo-100 text-indigo-800">
                                    {{ $this->positionStatsUniqueTotals['contacted'] ?? 0 }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-emerald-100 text-emerald-800">
                                    {{ $this->positionStatsUniqueTotals['completed'] ?? 0 }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-purple-100 text-purple-800">
                                    {{ $this->positionStatsUniqueTotals['booked'] ?? 0 }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-green-100 text-green-800">
                                    {{ $this->positionStatsUniqueTotals['confirmed'] ?? 0 }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-teal-100 text-teal-800">
                                    {{ $this->positionStatsUniqueTotals['signed'] ?? 0 }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-center text-xs font-bold {{ ($this->positionStatsUniqueTotals['conversion'] ?? 0) >= 50 ? 'text-emerald-700' : (($this->positionStatsUniqueTotals['conversion'] ?? 0) >= 20 ? 'text-amber-700' : 'text-[var(--ui-secondary)]') }}">
                                {{ $this->positionStatsUniqueTotals['conversion'] ?? 0 }} %
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-ui-panel>
        @endif

        {{-- Statistik nach Tätigkeit --}}
        @if(!$this->showParked && count($this->activityStats) > 0)
        <x-ui-panel title="Übersicht nach Tätigkeit" :subtitle="$rangeSubtitle">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Tätigkeit</th>
                            <th class="px-4 py-3 text-center">Bewerbungen</th>
                            <th class="px-4 py-3 text-center">Kontaktdaten</th>
                            <th class="px-4 py-3 text-center">Registriert</th>
                            <th class="px-4 py-3 text-center">In Schulung</th>
                            <th class="px-4 py-3 text-center">Bestätigt</th>
                            <th class="px-4 py-3 text-center">Vertrag unterschrieben</th>
                            <th class="px-4 py-3 text-center">Conversion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @foreach($this->activityStats as $stat)
                        <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                            <td class="px-4 py-2.5 font-medium text-[var(--ui-secondary)]">{{ $stat['activity'] }}</td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['total'] > 0 ? 'bg-blue-50 text-blue-700' : 'bg-gray-50 text-gray-400' }}">{{ $stat['total'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['contacted'] > 0 ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-50 text-gray-400' }}">{{ $stat['contacted'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['completed'] > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-50 text-gray-400' }}">{{ $stat['completed'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['booked'] > 0 ? 'bg-purple-50 text-purple-700' : 'bg-gray-50 text-gray-400' }}">{{ $stat['booked'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['confirmed'] > 0 ? 'bg-green-50 text-green-700' : 'bg-gray-50 text-gray-400' }}">{{ $stat['confirmed'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-medium {{ $stat['signed'] > 0 ? 'bg-teal-50 text-teal-700' : 'bg-gray-50 text-gray-400' }}">{{ $stat['signed'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center text-xs font-semibold {{ $stat['conversion'] >= 50 ? 'text-emerald-600' : ($stat['conversion'] >= 20 ? 'text-amber-600' : 'text-[var(--ui-muted)]') }}">
                                {{ $stat['conversion'] }} %
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-bold border-t-2 border-[var(--ui-border)]">
                            <td class="px-4 py-2.5 text-[var(--ui-secondary)]">Gesamt</td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-blue-100 text-blue-800">{{ $this->activityStatsUniqueTotals['total'] ?? 0 }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-indigo-100 text-indigo-800">{{ $this->activityStatsUniqueTotals['contacted'] ?? 0 }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-emerald-100 text-emerald-800">{{ $this->activityStatsUniqueTotals['completed'] ?? 0 }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-purple-100 text-purple-800">{{ $this->activityStatsUniqueTotals['booked'] ?? 0 }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-green-100 text-green-800">{{ $this->activityStatsUniqueTotals['confirmed'] ?? 0 }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-flex items-center justify-center min-w-[2rem] rounded-full px-2 py-0.5 text-xs font-bold bg-teal-100 text-teal-800">{{ $this->activityStatsUniqueTotals['signed'] ?? 0 }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center text-xs font-bold {{ ($this->activityStatsUniqueTotals['conversion'] ?? 0) >= 50 ? 'text-emerald-700' : (($this->activityStatsUniqueTotals['conversion'] ?? 0) >= 20 ? 'text-amber-700' : 'text-[var(--ui-secondary)]') }}">
                                {{ $this->activityStatsUniqueTotals['conversion'] ?? 0 }} %
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-ui-panel>
        @endif

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
                                        @if($this->showParked)
                                            <button
                                                wire:click="unparkApplicant({{ $applicant->id }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors"
                                                title="Zurückholen"
                                            >
                                                @svg('heroicon-o-play', 'w-3.5 h-3.5')
                                            </button>
                                        @else
                                            <button
                                                wire:click="parkApplicant({{ $applicant->id }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors"
                                                title="Parken"
                                            >
                                                @svg('heroicon-o-pause', 'w-3.5 h-3.5')
                                            </button>
                                        @endif
                                        <button
                                            wire:click="deleteApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber endgültig löschen? Dies kann nicht rückgängig gemacht werden."
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                                            title="Löschen"
                                        >
                                            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                        </button>
                                        <button
                                            wire:click="deleteAndBlacklistApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber löschen und CRM-Kontakt blacklisten? Dies kann nicht rückgängig gemacht werden."
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-100 text-red-700 hover:bg-red-200 transition-colors"
                                            title="Löschen + Blacklist"
                                        >
                                            @svg('heroicon-o-no-symbol', 'w-3.5 h-3.5')
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
                                        @if($this->showParked)
                                            <button
                                                wire:click="unparkApplicant({{ $applicant->id }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors"
                                                title="Zurückholen"
                                            >
                                                @svg('heroicon-o-play', 'w-3.5 h-3.5')
                                            </button>
                                        @else
                                            <button
                                                wire:click="parkApplicant({{ $applicant->id }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors"
                                                title="Parken"
                                            >
                                                @svg('heroicon-o-pause', 'w-3.5 h-3.5')
                                            </button>
                                        @endif
                                        <button
                                            wire:click="deleteApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber endgültig löschen? Dies kann nicht rückgängig gemacht werden."
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                                            title="Löschen"
                                        >
                                            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                        </button>
                                        <button
                                            wire:click="deleteAndBlacklistApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber löschen und CRM-Kontakt blacklisten? Dies kann nicht rückgängig gemacht werden."
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-100 text-red-700 hover:bg-red-200 transition-colors"
                                            title="Löschen + Blacklist"
                                        >
                                            @svg('heroicon-o-no-symbol', 'w-3.5 h-3.5')
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

        {{-- Phasen-Panels (immer gruppiert) --}}
        @foreach($this->phases as $phase)
            @if(!$this->phaseFilter || $this->phaseFilter == ($this->positionFilter ? $phase->id : $phase->order))
                @php $phaseApplicants = $this->phasedApplicants[$phase->id] ?? collect(); @endphp
                <x-ui-panel :title="$phase->name" :subtitle="'Phase ' . $phase->order . ' · ' . count($phaseApplicants) . ' Bewerber'">
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto border-collapse text-sm">
                            <thead>
                                <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Extra-Felder</th>
                                    <th class="px-4 py-3">AutoPilot</th>
                                    <th class="px-4 py-3 text-right"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--ui-border)]/60">
                                @forelse($phaseApplicants as $applicant)
                                    @php
                                        $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                        $positions = $applicant->postings->map(fn ($p) => $p->position?->title)->filter()->unique();
                                        $extraCounts = $this->getExtraFieldCounts($applicant);
                                        $primaryEmail = $primaryContact?->emailAddresses?->first()?->email_address;
                                        $waStatus = $this->getWhatsAppStatus($applicant);
                                    @endphp
                                    <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                        <td class="px-4 py-2.5">
                                            <div class="flex items-start gap-2.5">
                                                <div class="mt-1.5 flex-shrink-0">
                                                    <span class="relative flex h-2.5 w-2.5" title="{{ $phase->name }}">
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
                                                    @if(!$this->positionFilter && $positions->isNotEmpty())
                                                        <div class="text-xs text-[var(--ui-muted)] truncate">{{ $positions->implode(', ') }}</div>
                                                    @endif
                                                    @if($primaryEmail)
                                                        <div class="text-xs text-[var(--ui-muted)] truncate">{{ $primaryEmail }}</div>
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
                                                <button
                                                    wire:click="retryEnrichment({{ $applicant->id }})"
                                                    wire:confirm="Bewerber zurück in den Eingang verschieben und Enrichment erneut starten?"
                                                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors"
                                                    title="Erneut anreichern"
                                                >
                                                    @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                                </button>
                                                <button
                                                    wire:click="advanceToNextPhase({{ $applicant->id }})"
                                                    wire:confirm="Bewerber zur nächsten Phase verschieben?"
                                                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors"
                                                    title="Zur nächsten Phase"
                                                >
                                                    @svg('heroicon-o-arrow-right-circle', 'w-3.5 h-3.5')
                                                </button>
                                                @if($this->showParked)
                                                    <button
                                                        wire:click="unparkApplicant({{ $applicant->id }})"
                                                        class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors"
                                                        title="Zurückholen"
                                                    >
                                                        @svg('heroicon-o-play', 'w-3.5 h-3.5')
                                                    </button>
                                                @else
                                                    <button
                                                        wire:click="parkApplicant({{ $applicant->id }})"
                                                        class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors"
                                                        title="Parken"
                                                    >
                                                        @svg('heroicon-o-pause', 'w-3.5 h-3.5')
                                                    </button>
                                                @endif
                                                <button
                                                    wire:click="deleteApplicant({{ $applicant->id }})"
                                                    wire:confirm="Bewerber endgültig löschen? Dies kann nicht rückgängig gemacht werden."
                                                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                                                    title="Löschen"
                                                >
                                                    @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                                </button>
                                                <button
                                                    wire:click="deleteAndBlacklistApplicant({{ $applicant->id }})"
                                                    wire:confirm="Bewerber löschen und CRM-Kontakt blacklisten? Dies kann nicht rückgängig gemacht werden."
                                                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-100 text-red-700 hover:bg-red-200 transition-colors"
                                                    title="Löschen + Blacklist"
                                                >
                                                    @svg('heroicon-o-no-symbol', 'w-3.5 h-3.5')
                                                </button>
                                                <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                                    Anzeigen
                                                </x-ui-button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-[var(--ui-muted)]">Keine Bewerber in dieser Phase</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui-panel>
            @endif
        @endforeach

        {{-- Bewerber ohne Phase --}}
        @if(!empty($this->phasedApplicants['no_phase']))
            @php $noPhasApplicants = $this->phasedApplicants['no_phase']; @endphp
            <x-ui-panel title="Ohne Phase" subtitle="{{ count($noPhasApplicants) }} Bewerber ohne Phasen-Zuordnung">
                <div class="overflow-x-auto">
                    <table class="w-full table-auto border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Extra-Felder</th>
                                <th class="px-4 py-3">AutoPilot</th>
                                <th class="px-4 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/60">
                            @foreach($noPhasApplicants as $applicant)
                                @php
                                    $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                    $positions = $applicant->postings->map(fn ($p) => $p->position?->title)->filter()->unique();
                                    $extraCounts = $this->getExtraFieldCounts($applicant);
                                    $waStatus = $this->getWhatsAppStatus($applicant);
                                @endphp
                                <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-start gap-2.5">
                                            <div class="mt-1.5 flex-shrink-0">
                                                <span class="relative flex h-2.5 w-2.5" title="Ohne Phase">
                                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-gray-400 opacity-75"></span>
                                                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-gray-500"></span>
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
                                            <button
                                                wire:click="retryEnrichment({{ $applicant->id }})"
                                                wire:confirm="Bewerber zurück in den Eingang verschieben und Enrichment erneut starten?"
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-blue-50 text-blue-600 hover:bg-blue-100 transition-colors"
                                                title="Erneut anreichern"
                                            >
                                                @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                            </button>
                                            @if($this->showParked)
                                                <button
                                                    wire:click="unparkApplicant({{ $applicant->id }})"
                                                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors"
                                                    title="Zurückholen"
                                                >
                                                    @svg('heroicon-o-play', 'w-3.5 h-3.5')
                                                </button>
                                            @else
                                                <button
                                                    wire:click="parkApplicant({{ $applicant->id }})"
                                                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors"
                                                    title="Parken"
                                                >
                                                    @svg('heroicon-o-pause', 'w-3.5 h-3.5')
                                                </button>
                                            @endif
                                            <button
                                                wire:click="deleteApplicant({{ $applicant->id }})"
                                                wire:confirm="Bewerber endgültig löschen? Dies kann nicht rückgängig gemacht werden."
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                                                title="Löschen"
                                            >
                                                @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                            </button>
                                            <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.applicants.show', $applicant) }}" wire:navigate>
                                                Anzeigen
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
                                        <a href="{{ route('recruiting.interview-schedule.index') }}" wire:navigate
                                            class="inline-flex items-center gap-1.5 rounded px-2.5 py-1.5 text-xs font-medium bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-colors"
                                            title="Interview buchen"
                                        >
                                            @svg('heroicon-o-calendar-days', 'w-3.5 h-3.5')
                                        </a>
                                        <button
                                            wire:click="retryEnrichment({{ $applicant->id }})"
                                            wire:confirm="Bewerber zurück in den Eingang verschieben und Enrichment erneut starten?"
                                            class="inline-flex items-center gap-1.5 rounded px-2.5 py-1.5 text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 transition-colors"
                                            title="Zurück in den Eingang"
                                        >
                                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                        </button>
                                        @if($this->showParked)
                                            <button
                                                wire:click="unparkApplicant({{ $applicant->id }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors"
                                                title="Zurückholen"
                                            >
                                                @svg('heroicon-o-play', 'w-3.5 h-3.5')
                                            </button>
                                        @else
                                            <button
                                                wire:click="parkApplicant({{ $applicant->id }})"
                                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors"
                                                title="Parken"
                                            >
                                                @svg('heroicon-o-pause', 'w-3.5 h-3.5')
                                            </button>
                                        @endif
                                        <button
                                            wire:click="deleteApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber endgültig löschen? Dies kann nicht rückgängig gemacht werden."
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
                                            title="Löschen"
                                        >
                                            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                        </button>
                                        <button
                                            wire:click="deleteAndBlacklistApplicant({{ $applicant->id }})"
                                            wire:confirm="Bewerber löschen und CRM-Kontakt blacklisten? Dies kann nicht rückgängig gemacht werden."
                                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs bg-red-100 text-red-700 hover:bg-red-200 transition-colors"
                                            title="Löschen + Blacklist"
                                        >
                                            @svg('heroicon-o-no-symbol', 'w-3.5 h-3.5')
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
                @if($this->showParked)
                    <x-ui-button variant="primary" size="sm" class="w-full justify-start" href="{{ route('recruiting.dashboard') }}" wire:navigate>
                        @svg('heroicon-o-home', 'w-4 h-4') <span class="ml-2">Dashboard</span>
                    </x-ui-button>
                @else
                    <x-ui-button variant="primary" size="sm" class="w-full justify-start" href="{{ route('recruiting.dashboard.parked') }}" wire:navigate>
                        @svg('heroicon-o-pause', 'w-4 h-4') <span class="ml-2">Geparkte Bewerber</span>
                    </x-ui-button>
                @endif
                <x-ui-button variant="secondary" size="sm" class="w-full justify-start" href="{{ route('recruiting.positions.index') }}" wire:navigate>
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
