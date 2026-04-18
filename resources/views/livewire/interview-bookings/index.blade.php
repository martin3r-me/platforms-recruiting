<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Interview-Buchungen" icon="heroicon-o-clipboard-document-list" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Interview-Termine', 'href' => route('recruiting.interview-schedule.index')],
            ['label' => 'Buchungen'],
        ]">
            <x-ui-button variant="primary" size="sm" wire:click="openBookModal">
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Kandidat buchen</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        <div class="px-4 sm:px-6 lg:px-8">
            {{-- Termin-Info --}}
            <x-ui-panel title="Termin-Details" subtitle="{{ $this->interview->interviewType?->name ?? 'Interview' }}">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-[var(--ui-muted)]">Datum</div>
                        <div class="font-medium">
                            {{ $this->interview->starts_at->format('d.m.Y H:i') }}
                            @if($this->interview->ends_at)
                                — {{ $this->interview->ends_at->format('H:i') }}
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Stelle</div>
                        <div class="font-medium">{{ $this->interview->position?->title ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Ort</div>
                        <div class="font-medium">{{ $this->interview->location ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Interviewer</div>
                        <div class="font-medium">
                            @if($this->interview->interviewers->isNotEmpty())
                                {{ $this->interview->interviewers->pluck('name')->join(', ') }}
                            @else
                                —
                            @endif
                        </div>
                    </div>
                </div>
            </x-ui-panel>

            {{-- Buchungen --}}
            <div class="mt-6">
                <x-ui-panel title="Buchungen" subtitle="Gebuchte Kandidaten für diesen Termin">
                    <div class="flex gap-2 mb-4">
                        <x-ui-input-select
                            name="filterStatus"
                            wire:model.live="filterStatus"
                            :options="[
                                ['value' => 'all', 'label' => 'Alle Status'],
                                ['value' => 'registered', 'label' => 'Registriert'],
                                ['value' => 'confirmed', 'label' => 'Bestätigt'],
                                ['value' => 'attended', 'label' => 'Teilgenommen'],
                                ['value' => 'cancelled', 'label' => 'Abgesagt'],
                                ['value' => 'no_show', 'label' => 'Nicht erschienen'],
                            ]"
                            optionValue="value"
                            optionLabel="label"
                        />
                        <x-ui-input-text name="search" placeholder="Suchen…" wire:model.live.debounce.300ms="search" class="flex-1 max-w-xs" />
                    </div>

                    @if($this->interview->max_participants)
                        @php
                            $activeCount = $this->bookings->whereNotIn('status', ['cancelled'])->count();
                            $isFull = $activeCount >= $this->interview->max_participants;
                        @endphp
                        <div class="mb-4 p-3 rounded-lg {{ $isFull ? 'bg-red-50 border border-red-200' : 'bg-blue-50 border border-blue-200' }}">
                            <span class="text-sm font-medium {{ $isFull ? 'text-red-700' : 'text-blue-700' }}">
                                {{ $activeCount }} / {{ $this->interview->max_participants }} Plätze belegt
                                @if($isFull)
                                    — Termin voll
                                @endif
                            </span>
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full table-auto border-collapse text-sm">
                            <thead>
                                <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                    <th class="px-4 py-3">Kandidat</th>
                                    <th class="px-4 py-3">Stelle</th>
                                    <th class="px-4 py-3">Gebucht am</th>
                                    <th class="px-4 py-3">Notizen</th>
                                    <th class="px-4 py-3">Erinnerung</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--ui-border)]/60">
                                @forelse($this->bookings as $booking)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            @if($booking->applicant)
                                                <a href="{{ route('recruiting.applicants.show', $booking->applicant->id) }}" wire:navigate class="text-blue-600 hover:underline">
                                                    {{ $booking->applicant->crmContactLinks->first()?->contact?->full_name ?? 'Unbekannt' }}
                                                </a>
                                            @else
                                                <span class="text-[var(--ui-muted)]">Gelöscht</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @php $positions = $booking->applicant?->postings?->map(fn ($p) => $p->position?->title)->filter()->unique(); @endphp
                                            {{ $positions && $positions->isNotEmpty() ? $positions->implode(', ') : '—' }}
                                        </td>
                                        <td class="px-4 py-3">{{ $booking->booked_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            <textarea
                                                class="w-full text-xs border border-transparent hover:border-[var(--ui-border)] focus:border-blue-400 rounded px-2 py-1 bg-transparent resize-none focus:bg-white transition-colors"
                                                rows="1"
                                                placeholder="Notiz…"
                                                wire:blur="updateNotes({{ $booking->id }}, $event.target.value)"
                                            >{{ $booking->notes }}</textarea>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($booking->reminder_sent_at)
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs text-green-600 flex items-center gap-1">
                                                        @svg('heroicon-o-check-circle', 'w-3.5 h-3.5')
                                                        {{ $booking->reminder_sent_at->format('d.m. H:i') }}
                                                    </span>
                                                    @if($this->interview->reminder_wa_template_id && $booking->status !== 'cancelled')
                                                        <x-ui-button variant="secondary-outline" size="xs" wire:click="sendReminder({{ $booking->id }})" wire:confirm="Erneut senden?">
                                                            @svg('heroicon-o-arrow-path', 'w-3 h-3')
                                                        </x-ui-button>
                                                    @endif
                                                </div>
                                            @elseif($this->interview->reminder_wa_template_id && $booking->status !== 'cancelled')
                                                <x-ui-button variant="secondary-outline" size="xs" wire:click="sendReminder({{ $booking->id }})" wire:confirm="Erinnerung jetzt senden?">
                                                    @svg('heroicon-o-paper-airplane', 'w-3 h-3') Senden
                                                </x-ui-button>
                                            @else
                                                <span class="text-[var(--ui-muted)]">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <select wire:change="updateStatus({{ $booking->id }}, $event.target.value)" class="text-xs border border-[var(--ui-border)] rounded px-2 py-1">
                                                <option value="registered" @selected($booking->status === 'registered')>Registriert</option>
                                                <option value="confirmed" @selected($booking->status === 'confirmed')>Bestätigt</option>
                                                <option value="attended" @selected($booking->status === 'attended')>Teilgenommen</option>
                                                <option value="cancelled" @selected($booking->status === 'cancelled')>Abgesagt</option>
                                                <option value="no_show" @selected($booking->status === 'no_show')>Nicht erschienen</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-3">
                                            <x-ui-button variant="danger-outline" size="xs" wire:click="deleteBooking({{ $booking->id }})">
                                                Löschen
                                            </x-ui-button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                            @svg('heroicon-o-clipboard-document-list', 'w-10 h-10 text-[var(--ui-muted)] mx-auto mb-2')
                                            <div class="text-sm">Keine Buchungen vorhanden</div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui-panel>
            </div>
        </div>
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" width="w-80" :defaultOpen="true">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-4">Statistiken</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Buchungen</span>
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->bookings->count() }}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Bestätigt</span>
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->bookings->where('status', 'confirmed')->count() }}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Teilgenommen</span>
                            <span class="font-semibold text-green-600">{{ $this->bookings->where('status', 'attended')->count() }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-4">
                <div class="text-sm text-[var(--ui-muted)]">Letzte Aktivitäten</div>
                <div class="space-y-3 text-sm">
                    <div class="p-2 rounded border border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                        <div class="font-medium text-[var(--ui-secondary)] truncate">Buchungen geladen</div>
                        <div class="text-[var(--ui-muted)]">{{ now()->format('d.m.Y H:i') }}</div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Buchungs-Modal --}}
    <x-ui-modal wire:model="showBookModal">
        <x-slot name="header">Kandidat buchen</x-slot>
        <div class="space-y-4">
            @if($this->interview->rec_position_id)
                <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700">
                    Es werden nur abgeschlossene Bewerber für die Stelle <strong>{{ $this->interview->position?->title }}</strong> angezeigt.
                </div>
            @endif
            <x-ui-input-select
                name="selectedApplicantId"
                wire:model="selectedApplicantId"
                label="Kandidat *"
                :options="$this->availableApplicants->map(fn($a) => ['value' => $a->id, 'label' => $a->crmContactLinks->first()?->contact?->full_name ?? 'Unbekannt'])->toArray()"
                optionValue="value"
                optionLabel="label"
                :nullable="true"
                nullLabel="— Bitte wählen —"
            />
            <x-ui-input-textarea name="bookingNotes" label="Notizen" wire:model="bookingNotes" />
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary" wire:click="$set('showBookModal', false)">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="book">Buchen</x-ui-button>
        </x-slot>
    </x-ui-modal>
</x-ui-page>
