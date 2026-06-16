@php
    $positions = $this->positions;
    $byPosition = $this->applicantsByPosition;
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Direkteinstellungen" icon="heroicon-o-bolt" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Direkteinstellungen'],
        ]">
            <x-ui-button variant="primary" size="sm" href="{{ route('recruiting.direct-hire.create') }}" wire:navigate>
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Neue Direkteinstellung</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        @if(session()->has('message'))
            <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                {{ session('message') }}
            </div>
        @endif

        <div class="mb-4 flex items-center justify-between">
            <div class="text-sm text-[var(--ui-muted)]">
                Aktive Direkteinstellungs-Stellen mit ihren Bewerbern.
            </div>
            <label class="flex items-center gap-2 text-sm text-[var(--ui-secondary)] cursor-pointer">
                <input type="checkbox" wire:model.live="onlyMine"
                       class="rounded border-[var(--ui-border)] text-[var(--ui-primary)] focus:ring-[var(--ui-primary)]/20">
                <span>Nur meine</span>
            </label>
        </div>

        @if($positions->isEmpty())
            <div class="text-center py-16 border border-dashed border-[var(--ui-border)]/50 rounded-lg">
                <div class="text-[var(--ui-muted)] text-sm mb-4">
                    @if($this->onlyMine)
                        Dir ist aktuell keine Direkteinstellungs-Stelle zugewiesen.
                    @else
                        Es gibt noch keine Direkteinstellungs-Stellen.
                    @endif
                </div>
                <x-ui-button variant="primary" size="sm" href="{{ route('recruiting.direct-hire.create') }}" wire:navigate>
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    <span>Direkteinstellung anlegen</span>
                </x-ui-button>
            </div>
        @else
            <div class="space-y-6">
                @foreach($positions as $position)
                    @php
                        $owner = $position->ownedByUser?->name;

                        // Intake: Code (externalRefs / sourcePlatform) + Mail (commsChannels).
                        $codes = $position->postings
                            ->flatMap(fn ($p) => $p->externalRefs)
                            ->pluck('external_ref')
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();
                        $mails = $position->postings
                            ->flatMap(fn ($p) => $p->commsChannels)
                            ->pluck('sender_identifier')
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();

                        $applicants = $byPosition[$position->id] ?? collect();
                    @endphp

                    <x-ui-panel :title="$position->title">
                        <x-slot name="subtitle">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[var(--ui-muted)]">
                                @if($owner)
                                    <span class="inline-flex items-center gap-1">
                                        @svg('heroicon-o-user', 'w-3.5 h-3.5')
                                        {{ $owner }}
                                    </span>
                                @endif
                                @foreach($codes as $code)
                                    <span class="inline-flex items-center gap-1 font-mono">
                                        @svg('heroicon-o-hashtag', 'w-3.5 h-3.5')
                                        {{ $code }}
                                    </span>
                                @endforeach
                                @foreach($mails as $mail)
                                    <span class="inline-flex items-center gap-1">
                                        @svg('heroicon-o-envelope', 'w-3.5 h-3.5')
                                        {{ $mail }}
                                    </span>
                                @endforeach
                            </div>
                        </x-slot>

                        @if($applicants->isEmpty())
                            <div class="text-sm text-[var(--ui-muted)] py-6 text-center border border-dashed border-[var(--ui-border)]/40 rounded-lg">
                                Noch keine Bewerber für diese Stelle.
                            </div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-xs text-[var(--ui-muted)] border-b border-[var(--ui-border)]/40">
                                            <th class="py-2 pr-3 font-medium">Bewerber</th>
                                            <th class="py-2 pr-3 font-medium">Kontakt</th>
                                            <th class="py-2 pr-3 font-medium">Eingang</th>
                                            <th class="py-2 pr-3 font-medium">Phase</th>
                                            <th class="py-2 pr-3 font-medium text-right">Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($applicants as $applicant)
                                            @php
                                                $contact = $applicant->crmContactLinks->first()?->contact;
                                                $name = trim(($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? ''));
                                                $email = $contact?->emailAddresses->first()?->email_address;
                                                $phone = $contact?->phoneNumbers->first()?->raw_input ?? $contact?->phoneNumbers->first()?->e164;
                                                $createdAt = $applicant->created_at?->format('d.m.Y');
                                                $phaseName = $applicant->phase?->name;
                                                $phaseOrder = $applicant->phase?->order;
                                                if ($phaseOrder === 2) {
                                                    $portalLink = $applicant->getPublicUrl();
                                                }
                                            @endphp
                                            <tr class="border-b border-[var(--ui-border)]/30 hover:bg-[var(--ui-muted-5)]">
                                                <td class="py-2.5 pr-3 align-top">
                                                    <div class="font-medium text-[var(--ui-secondary)]">
                                                        {{ $name !== '' ? $name : '— kein Name —' }}
                                                    </div>
                                                    <div class="text-xs text-[var(--ui-muted)] font-mono">#{{ $applicant->id }}</div>
                                                </td>
                                                <td class="py-2.5 pr-3 align-top text-[var(--ui-muted)]">
                                                    @if($email)<div>{{ $email }}</div>@endif
                                                    @if($phone)<div>{{ $phone }}</div>@endif
                                                </td>
                                                <td class="py-2.5 pr-3 align-top text-[var(--ui-muted)]">{{ $createdAt }}</td>
                                                <td class="py-2.5 pr-3 align-top">
                                                    @if($phaseName)
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">{{ $phaseName }}</span>
                                                    @else
                                                        <span class="text-xs text-[var(--ui-muted)]">—</span>
                                                    @endif
                                                    @if($phaseOrder === 2)
                                                        <div class="text-xs text-[var(--ui-muted)] mt-1">{{ (int) $applicant->progress }}% erfasst</div>
                                                    @endif
                                                </td>
                                                <td class="py-2.5 pr-3 align-top">
                                                    <div class="flex items-center justify-end gap-2">
                                                        @if($phaseOrder === 1 || $phaseOrder === null)
                                                            <button type="button"
                                                                    wire:click="startDataCollection({{ $applicant->id }})"
                                                                    wire:confirm="Datenerfassung starten?"
                                                                    class="text-xs px-2 py-1.5 rounded-md bg-[var(--ui-primary)] text-white hover:opacity-90">
                                                                Datenerfassung starten
                                                            </button>
                                                            <button type="button"
                                                                    wire:click="parkApplicant({{ $applicant->id }})"
                                                                    wire:confirm="Diesen Bewerber parken?"
                                                                    class="text-xs px-2 py-1.5 border border-[var(--ui-border)] text-[var(--ui-secondary)] rounded-md hover:bg-[var(--ui-muted-5)]">
                                                                Parken
                                                            </button>
                                                        @elseif($phaseOrder === 2)
                                                            <div
                                                                x-data="{ copied: false }"
                                                                class="flex items-center gap-2 px-2 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-md cursor-pointer transition-colors"
                                                                x-on:click="navigator.clipboard.writeText('{{ $portalLink }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                                title="Datenerfassungs-Link kopieren"
                                                            >
                                                                @svg('heroicon-o-link', 'w-3.5 h-3.5 text-gray-500')
                                                                <span class="text-xs font-medium text-gray-600" x-show="!copied">Link kopieren</span>
                                                                <span class="text-xs font-medium text-emerald-600" x-show="copied" x-cloak>Kopiert!</span>
                                                                <template x-if="!copied">
                                                                    @svg('heroicon-o-clipboard-document', 'w-3.5 h-3.5 text-gray-400')
                                                                </template>
                                                                <template x-if="copied">
                                                                    @svg('heroicon-o-check', 'w-3.5 h-3.5 text-emerald-500')
                                                                </template>
                                                            </div>
                                                        @endif
                                                        <a href="{{ route('recruiting.applicants.show', $applicant->id) }}"
                                                           wire:navigate
                                                           class="text-xs px-2 py-1.5 border border-[var(--ui-border)] text-[var(--ui-secondary)] rounded-md hover:bg-[var(--ui-muted-5)]">
                                                            Öffnen
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-ui-panel>
                @endforeach
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
