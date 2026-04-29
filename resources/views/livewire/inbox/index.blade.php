<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Eingangs-Inbox" icon="heroicon-o-inbox" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Eingangs-Inbox'],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        @if(session()->has('message'))
            <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                {{ session('message') }}
            </div>
        @endif

        <x-ui-panel title="Unbekannte Quelle" subtitle="Eingehende Bewerbungen, deren Sender keinem deiner Eingangs-Quellen-Patterns entspricht. Sie werden nicht im normalen Flow verarbeitet, bis HR sie zuordnet oder verwirft.">

            @php $applicants = $this->unroutedApplicants; @endphp

            <div class="mb-4 flex items-center justify-between">
                <div class="text-sm text-[var(--ui-muted)]">
                    @if($this->totalCount === 0)
                        Keine unzuordneten Eingänge.
                    @else
                        <strong class="text-[var(--ui-secondary)]">{{ $this->totalCount }}</strong>
                        {{ $this->totalCount === 1 ? 'Eingang wartet' : 'Eingänge warten' }} auf Zuordnung.
                    @endif
                </div>
                <div class="w-64">
                    <input type="text"
                           wire:model.live.debounce.300ms="search"
                           placeholder="Suche nach Name…"
                           class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                </div>
            </div>

            @if($applicants->isEmpty())
                <div class="text-center py-12 text-[var(--ui-muted)] text-sm border border-dashed border-[var(--ui-border)]/40 rounded-lg">
                    @if(empty($this->search))
                        Keine unzuordneten Eingänge. 🎉
                    @else
                        Keine Treffer für „{{ $this->search }}".
                    @endif
                </div>
            @else
                <div class="space-y-3">
                    @foreach($applicants as $applicant)
                        @php
                            $contact = $applicant->crmContactLinks->first()?->contact;
                            $name = trim(($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? ''));
                            $email = $contact?->emailAddresses->first()?->email_address;
                            $phone = $contact?->phoneNumbers->first()?->raw_input ?? $contact?->phoneNumbers->first()?->e164;
                            $createdAt = $applicant->created_at?->format('d.m.Y H:i');
                            $notesPreview = $applicant->notes ? mb_substr($applicant->notes, 0, 220) : '';
                        @endphp

                        <div class="border border-[var(--ui-border)]/60 rounded-lg p-4 bg-[var(--ui-surface)] hover:bg-[var(--ui-muted-5)] transition-colors">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 text-xs text-[var(--ui-muted)] mb-1">
                                        @svg('heroicon-o-envelope', 'w-3.5 h-3.5')
                                        <span>{{ $createdAt }}</span>
                                        <span class="text-[var(--ui-muted)]/60">·</span>
                                        <span class="font-mono">#{{ $applicant->id }}</span>
                                    </div>
                                    <div class="font-medium text-[var(--ui-secondary)]">
                                        {{ $name !== '' ? $name : '— kein Name extrahiert —' }}
                                    </div>
                                    <div class="text-sm text-[var(--ui-muted)] mt-0.5 space-x-3">
                                        @if($email)
                                            <span>{{ $email }}</span>
                                        @endif
                                        @if($phone)
                                            <span>{{ $phone }}</span>
                                        @endif
                                    </div>
                                    @if($notesPreview)
                                        <div class="text-xs text-[var(--ui-muted)] mt-2 line-clamp-2">
                                            {{ $notesPreview }}{{ mb_strlen($applicant->notes) > 220 ? '…' : '' }}
                                        </div>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <select wire:change="assignSource({{ $applicant->id }}, $event.target.value)"
                                            class="text-xs px-2 py-1.5 border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20">
                                        <option value="">→ Quelle zuweisen…</option>
                                        @foreach($this->availableSourcePlatforms as $source)
                                            <option value="{{ $source->id }}">{{ $source->name }}</option>
                                        @endforeach
                                    </select>
                                    <button wire:click="discardApplicant({{ $applicant->id }})"
                                            wire:confirm="Diesen Eingang wirklich verwerfen?"
                                            class="text-xs px-2 py-1.5 border border-red-200 text-red-600 rounded-md hover:bg-red-50">
                                        Verwerfen
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-6 text-xs text-[var(--ui-muted)] border-t border-[var(--ui-border)]/40 pt-4">
                <strong>Hinweis:</strong> Wenn du eine Quelle zuweist, wandert der Bewerber in den normalen Flow und wird automatisch enriched. Beim Verwerfen wird der Eingang als inaktiv markiert (nicht hart gelöscht — bei Bedarf rückholbar).
                Pflege Eingangs-Quellen unter <em>Bewerber → Einstellungen → Eingangs-Quellen</em>.
            </div>
        </x-ui-panel>
    </x-ui-page-container>
</x-ui-page>
