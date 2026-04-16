<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Verträge" icon="heroicon-o-document-text" />
    </x-slot>

    <x-ui-page-container>
        <div class="px-4 sm:px-6 lg:px-8">
            <x-ui-panel title="Verträge" subtitle="Übersicht aller Verträge">
                <div class="flex gap-2 mb-4 flex-wrap">
                    <select wire:model.live="filterStatus" class="text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="all">Alle Status</option>
                        <option value="pending">Ausstehend</option>
                        <option value="sent">Versendet</option>
                        <option value="in_progress">In Bearbeitung</option>
                        <option value="completed">Abgeschlossen</option>
                        <option value="needs_review">Prüfung nötig</option>
                    </select>
                    <select wire:model.live="filterTemplateId" class="text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="all">Alle Vorlagen</option>
                        @foreach($this->templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </select>
                    <x-ui-input-text name="search" placeholder="Suchen…" wire:model.live.debounce.300ms="search" class="flex-1 max-w-xs" />
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full table-auto border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                <th class="px-4 py-3">Kandidat</th>
                                <th class="px-4 py-3">Vorlage</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Unterschrift</th>
                                <th class="px-4 py-3">Versendet</th>
                                <th class="px-4 py-3">Abgeschlossen</th>
                                <th class="px-4 py-3">Notizen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/60">
                            @forelse($this->contracts as $contract)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        @if($contract->applicant)
                                            <a href="{{ route('recruiting.applicants.show', $contract->applicant) }}" wire:navigate class="text-blue-600 hover:underline">
                                                {{ $contract->applicant->crmContactLinks->first()?->contact?->full_name ?? 'Unbekannt' }}
                                            </a>
                                        @else
                                            <span class="text-[var(--ui-muted)]">—</span>
                                        @endif
                                    </td>
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
                                    <td class="px-4 py-3 text-xs">
                                        {{ $contract->sent_at?->format('d.m.Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{ $contract->completed_at?->format('d.m.Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-[var(--ui-muted)] max-w-[200px] truncate">
                                        {{ $contract->notes ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                        @svg('heroicon-o-document-text', 'w-10 h-10 text-[var(--ui-muted)] mx-auto mb-2')
                                        <div class="text-sm">Keine Verträge vorhanden</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui-panel>
        </div>
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" width="w-80" :defaultOpen="true">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-4">Statistiken</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Gesamt</span>
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->contracts->count() }}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Ausstehend</span>
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->contracts->where('status', 'pending')->count() }}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Abgeschlossen</span>
                            <span class="font-semibold text-green-600">{{ $this->contracts->where('status', 'completed')->count() }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-6 space-y-3 text-sm">
                <div class="text-[var(--ui-muted)]">Keine Aktivitäten verfügbar</div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
