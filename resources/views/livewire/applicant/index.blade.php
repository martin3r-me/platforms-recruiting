<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Bewerber" icon="heroicon-o-user-group" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Bewerber'],
        ]">
            <x-slot name="left">
                <x-ui-button variant="ghost" size="sm" wire:click="$dispatch('open-applicant-settings')">
                    @svg('heroicon-o-cog-6-tooth', 'w-4 h-4')
                    <span>Einstellungen</span>
                </x-ui-button>
            </x-slot>
            <x-ui-button variant="secondary-outline" size="sm" wire:click="openImportModal">
                @svg('heroicon-o-arrow-up-tray', 'w-4 h-4')
                <span>CSV-Import</span>
            </x-ui-button>
            <x-ui-button variant="primary" size="sm" wire:click="openCreateModal">
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Neuer Bewerber</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        <x-ui-panel title="Übersicht" subtitle="Bewerber verwalten">
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">E-Mail</th>
                            <th class="px-4 py-3">Nachrichten</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Verantwortlicher</th>
                            <th class="px-4 py-3">Fortschritt</th>
                            <th class="px-4 py-3">AutoPilot</th>
                            <th class="px-4 py-3">Beworben am</th>
                            <th class="px-4 py-3 text-right">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/60">
                        @forelse($this->applicants as $applicant)
                            @php
                                $primaryContact = $applicant->crmContactLinks->first()?->contact;
                                $primaryEmail = $primaryContact?->emailAddresses->first()?->email_address;
                                $primaryPhone = $primaryContact?->phoneNumbers->first(fn($p) => $p->is_active)?->international
                                    ?: $primaryContact?->phoneNumbers->first(fn($p) => $p->is_active)?->raw_input;
                                $positions = $applicant->postings->map(fn($p) => $p->position)->filter()->unique('id');
                                $apColor = $this->getAutoPilotColor($applicant);
                            @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)] transition-colors">
                                {{-- Name + Stellen-Badges --}}
                                <td class="px-4 py-3">
                                    @if($primaryContact)
                                        <div class="space-y-1">
                                            <div class="font-semibold text-[var(--ui-secondary)] flex items-center gap-2">
                                                {{ $primaryContact->full_name }}
                                                @if($applicant->is_active)
                                                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                                @endif
                                                @if($applicant->import_source)
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200" title="Altbestand-Import — fließt nicht in Recruiting-KPIs ein">
                                                        Import
                                                    </span>
                                                @endif
                                            </div>
                                            @if($positions->isNotEmpty())
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach($positions as $pos)
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-50 text-blue-700 border border-blue-200">
                                                            {{ $pos->title }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="flex flex-wrap gap-1">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200">
                                                        Initiativ
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-[var(--ui-muted)] italic">Kein Kontakt verknüpft</span>
                                    @endif
                                </td>
                                {{-- E-Mail & Telefon --}}
                                <td class="px-4 py-3">
                                    @if($primaryEmail)
                                        <div class="text-xs text-[var(--ui-muted)] flex items-center gap-1">
                                            @svg('heroicon-o-envelope', 'w-3 h-3')
                                            {{ $primaryEmail }}
                                        </div>
                                    @endif
                                    @if($primaryPhone)
                                        <div class="text-xs text-[var(--ui-muted)] flex items-center gap-1 {{ $primaryEmail ? 'mt-0.5' : '' }}">
                                            @svg('heroicon-o-phone', 'w-3 h-3')
                                            {{ $primaryPhone }}
                                        </div>
                                    @endif
                                    @if(!$primaryEmail && !$primaryPhone)
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Nachrichten --}}
                                <td class="px-4 py-3">
                                    @php $waStatus = $this->getWhatsAppStatus($applicant); @endphp
                                    @if($waStatus['color'] !== 'none')
                                        <div class="flex items-center gap-1">
                                            <span title="{{ $waStatus['window_open'] ? '💬 Fenster offen' . ($waStatus['last_message'] ? ' — ' . $waStatus['last_message'] : '') : ($waStatus['color'] === 'yellow' ? 'WhatsApp verfügbar' . ($waStatus['last_message'] ? ' — ' . $waStatus['last_message'] : '') : 'WhatsApp unbekannt') }}"
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
                                        </div>
                                        @if(!empty($waStatus['recent_messages']))
                                            <div class="mt-1 space-y-0.5">
                                                @foreach($waStatus['recent_messages'] as $msg)
                                                    <div class="flex items-center gap-1 text-[10px] leading-tight {{ $msg['direction'] === 'inbound' ? 'text-green-600' : 'text-[var(--ui-muted)]' }}">
                                                        @if($msg['direction'] === 'inbound')
                                                            @svg('heroicon-o-arrow-down-left', 'w-2.5 h-2.5 flex-shrink-0')
                                                        @else
                                                            @svg('heroicon-o-arrow-up-right', 'w-2.5 h-2.5 flex-shrink-0')
                                                        @endif
                                                        <span class="truncate">{{ $msg['body'] ?: '—' }}</span>
                                                        <span class="flex-shrink-0 text-[var(--ui-muted)]/60">{{ $msg['at'] }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Status --}}
                                <td class="px-4 py-3">
                                    @if($applicant->applicantStatus)
                                        <x-ui-badge variant="primary" size="xs">{{ $applicant->applicantStatus->name }}</x-ui-badge>
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Verantwortlicher --}}
                                <td class="px-4 py-3">
                                    @if($applicant->ownedByUser)
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 bg-[var(--ui-primary)] text-[var(--ui-on-primary)] rounded-full flex items-center justify-center text-xs font-medium">
                                                {{ strtoupper(substr($applicant->ownedByUser->name, 0, 1)) }}
                                            </div>
                                            <span class="text-sm">{{ $applicant->ownedByUser->fullname ?? $applicant->ownedByUser->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Fortschritt --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-24 bg-gray-200 rounded-full h-2">
                                            <div class="bg-blue-600 h-2 rounded-full" style="width: {{ $applicant->progress }}%"></div>
                                        </div>
                                        <span class="text-xs text-[var(--ui-muted)]">{{ $applicant->progress }}%</span>
                                    </div>
                                </td>
                                {{-- AutoPilot Icon + Ampel --}}
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        {{-- AutoPilot Icon --}}
                                        @if($applicant->auto_pilot)
                                            @if($applicant->auto_pilot_completed_at)
                                                <div class="relative" title="AutoPilot abgeschlossen">
                                                    @svg('heroicon-s-cpu-chip', 'w-5 h-5 text-green-500')
                                                </div>
                                            @else
                                                <div class="relative" title="AutoPilot aktiv">
                                                    @svg('heroicon-s-cpu-chip', 'w-5 h-5 text-[var(--ui-primary)] animate-pulse')
                                                </div>
                                            @endif
                                        @else
                                            <div title="AutoPilot inaktiv">
                                                @svg('heroicon-o-cpu-chip', 'w-5 h-5 text-[var(--ui-muted)]')
                                            </div>
                                        @endif
                                        {{-- Ampel --}}
                                        <div class="flex items-center gap-1" title="{{ $applicant->autoPilotState?->name ?? 'Kein State' }}">
                                            <span class="w-2.5 h-2.5 rounded-full {{ $apColor === 'red' ? 'bg-red-500' : 'bg-gray-200' }}"></span>
                                            <span class="w-2.5 h-2.5 rounded-full {{ $apColor === 'yellow' ? 'bg-amber-400' : 'bg-gray-200' }}"></span>
                                            <span class="w-2.5 h-2.5 rounded-full {{ $apColor === 'green' ? 'bg-emerald-500' : 'bg-gray-200' }}"></span>
                                        </div>
                                    </div>
                                </td>
                                {{-- Beworben am --}}
                                <td class="px-4 py-3">
                                    @if($applicant->applied_at)
                                        <div class="flex items-center gap-1 text-sm">
                                            @svg('heroicon-o-calendar', 'w-4 h-4 text-[var(--ui-muted)]')
                                            <span>{{ $applicant->applied_at->format('d.m.Y') }}</span>
                                        </div>
                                    @else
                                        <span class="text-[var(--ui-muted)]">–</span>
                                    @endif
                                </td>
                                {{-- Aktionen --}}
                                <td class="px-4 py-3 text-right">
                                    <x-ui-button size="sm" variant="primary" href="{{ route('recruiting.applicants.show', ['applicant' => $applicant->id]) }}" wire:navigate>
                                        @svg('heroicon-o-eye', 'w-3 h-3') Anzeigen
                                    </x-ui-button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        @svg('heroicon-o-user-plus', 'w-16 h-16 text-[var(--ui-muted)] mb-4')
                                        <div class="text-lg font-medium text-[var(--ui-secondary)] mb-1">Keine Bewerber gefunden</div>
                                        <div class="text-sm text-[var(--ui-muted)]">Erstelle deinen ersten Bewerber</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui-panel>
    </x-ui-page-container>

    {{-- CSV-Import-Modal (Altbestand) --}}
    <x-ui-modal wire:model="showImportModal" size="md">
        <x-slot name="header">CSV-Import (Altbestand)</x-slot>
        <div class="space-y-4">
            <p class="text-sm text-[var(--ui-muted)] leading-snug">
                Importiert Bewerber aus dem CSV-Export des bisherigen Dispo-Tools.
                Erwartete Spalten (Header): <code class="px-1 bg-gray-100 rounded">Vorname</code>,
                <code class="px-1 bg-gray-100 rounded">Nachname</code>,
                <code class="px-1 bg-gray-100 rounded">Geburtsdatum</code>,
                <code class="px-1 bg-gray-100 rounded">Geburtsort</code>,
                <code class="px-1 bg-gray-100 rounded">Straße</code>/<code class="px-1 bg-gray-100 rounded">Straße, Nr.</code>,
                <code class="px-1 bg-gray-100 rounded">HNr</code>,
                <code class="px-1 bg-gray-100 rounded">Postleitzahl</code>,
                <code class="px-1 bg-gray-100 rounded">Wohnort</code>.
            </p>

            <div>
                <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">CSV-Datei *</label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv,text/csv,text/plain"
                    class="block w-full text-sm text-[var(--ui-secondary)] file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-[var(--ui-muted-5)] file:text-[var(--ui-secondary)] hover:file:bg-[var(--ui-muted-10)]"
                />
                @error('importFile')
                    <span class="text-xs text-red-500">{{ $message }}</span>
                @enderror
                <div wire:loading wire:target="importFile" class="text-xs text-[var(--ui-muted)] mt-1">Datei wird hochgeladen…</div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="importDryRun" class="rounded border-[var(--ui-border)]" />
                <span>Dry-Run — nur prüfen, nicht schreiben</span>
            </label>

            @if($importResult)
                <div class="p-3 rounded-lg border {{ ($importResult['fatal'] ?? null) || !empty($importResult['errors'] ?? []) ? 'border-red-300 bg-red-50' : 'border-emerald-300 bg-emerald-50' }} text-sm">
                    @if($importResult['fatal'] ?? null)
                        <p class="font-semibold text-red-700 mb-1">Fehler:</p>
                        <p class="text-red-700">{{ $importResult['fatal'] }}</p>
                    @else
                        <p class="font-semibold mb-2">Ergebnis{{ $importDryRun ? ' (Dry-Run)' : '' }}:</p>
                        <ul class="space-y-0.5 text-[13px]">
                            <li>Geparst: <strong>{{ $importResult['parsed'] }}</strong></li>
                            <li>Importiert: <strong>{{ $importResult['imported'] }}</strong></li>
                            <li>Übersprungen (existiert schon): {{ $importResult['skipped_existing'] }}</li>
                            <li>Übersprungen (Dup im Lauf): {{ $importResult['skipped_dup'] }}</li>
                            <li>Übersprungen (unvollständig / Header-Zeile): {{ $importResult['skipped_incompl'] }}</li>
                        </ul>
                        @if(!empty($importResult['errors'] ?? []))
                            <p class="font-semibold text-red-700 mt-3 mb-1">{{ count($importResult['errors']) }} Fehler:</p>
                            <ul class="text-[12px] text-red-700 space-y-0.5 max-h-40 overflow-y-auto">
                                @foreach($importResult['errors'] as $err)
                                    <li>Zeile {{ $err['row'] }} ({{ $err['name'] }}): {{ $err['message'] }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if(!empty($importResult['details'] ?? []))
                            <details class="mt-3">
                                <summary class="cursor-pointer text-[12px] text-[var(--ui-muted)] hover:text-[var(--ui-secondary)]">Details ({{ count($importResult['details']) }} Zeilen) anzeigen</summary>
                                <ul class="mt-2 text-[12px] space-y-1 max-h-60 overflow-y-auto">
                                    @foreach($importResult['details'] as $d)
                                        @php
                                            $color = match($d['action']) {
                                                'imported' => 'text-emerald-700',
                                                'skipped_existing' => 'text-amber-700',
                                                'skipped_dup' => 'text-orange-700',
                                                default => 'text-[var(--ui-muted)]',
                                            };
                                            $label = match($d['action']) {
                                                'imported' => 'Importiert',
                                                'skipped_existing' => 'Existiert',
                                                'skipped_dup' => 'Dup',
                                                default => $d['action'],
                                            };
                                        @endphp
                                        <li class="{{ $color }}">
                                            <span class="font-mono text-[10px] text-[var(--ui-muted)]">Z.{{ $d['row'] }}</span>
                                            <strong>[{{ $label }}]</strong>
                                            {{ $d['name'] }}
                                            @if(!empty($d['note']))
                                                <span class="text-[var(--ui-muted)]">— {{ $d['note'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    @endif
                </div>

                {{-- Optional: importierte direkt in Schulung buchen --}}
                @php
                    $importedIds = $importResult['imported_applicant_ids'] ?? [];
                    $canBook = !$importDryRun
                        && empty($importResult['fatal'] ?? null)
                        && count($importedIds) > 0;
                @endphp
                @if($canBook)
                    <div class="p-3 rounded-lg border border-blue-200 bg-blue-50 text-sm space-y-3">
                        <p class="font-semibold text-blue-700">
                            Optional: {{ count($importedIds) }} importierte Bewerber direkt in eine Schulung buchen?
                        </p>

                        @if($importBookingMessage)
                            <p class="text-emerald-700 text-[13px]">{{ $importBookingMessage }}</p>
                        @endif
                        @if($importBookingError)
                            <p class="text-red-700 text-[13px]">{{ $importBookingError }}</p>
                        @endif

                        @if(!$importBookingMessage)
                            @php
                                $interviews = $this->availableImportInterviews;
                            @endphp
                            @if($interviews->isEmpty())
                                <p class="text-[var(--ui-muted)] text-[13px]">Keine zukünftigen Schulungs-Termine im Team verfügbar.</p>
                            @else
                                <div class="flex items-end gap-2">
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Schulung</label>
                                        <select
                                            wire:model.live="importBookingInterviewId"
                                            class="w-full rounded-md border border-[var(--ui-border)] px-3 py-2 text-sm bg-white"
                                        >
                                            <option value="">— Schulung wählen —</option>
                                            @foreach($interviews as $iv)
                                                <option value="{{ $iv->id }}">
                                                    {{ $iv->starts_at?->format('d.m.Y H:i') }} — {{ $iv->title }}
                                                    @if($iv->position) ({{ $iv->position->title }}) @endif
                                                    @if($iv->max_participants)
                                                        · max {{ $iv->max_participants }}
                                                    @endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <x-ui-button
                                        type="button"
                                        variant="primary"
                                        size="sm"
                                        wire:click="bookImportedIntoInterview"
                                        wire:loading.attr="disabled"
                                        wire:target="bookImportedIntoInterview"
                                        @disabled(!$importBookingInterviewId)
                                    >
                                        <span wire:loading.remove wire:target="bookImportedIntoInterview">In Schulung buchen</span>
                                        <span wire:loading wire:target="bookImportedIntoInterview">Bucht…</span>
                                    </x-ui-button>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            @endif
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-2">
                <x-ui-button type="button" variant="secondary-outline" wire:click="closeImportModal">Schließen</x-ui-button>
                <x-ui-button type="button" variant="primary" wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport,importFile">
                    <span wire:loading.remove wire:target="runImport">{{ $importDryRun ? 'Dry-Run starten' : 'Importieren' }}</span>
                    <span wire:loading wire:target="runImport">Läuft…</span>
                </x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>

    {{-- Create Applicant Modal --}}
    <x-ui-modal wire:model="modalShow" size="md">
        <x-slot name="header">Neuer Bewerber</x-slot>
        <div class="space-y-4">
            <x-ui-input-select name="contact_id" label="CRM-Kontakt (optional)" :options="$this->availableContacts" optionValue="id" optionLabel="display_name" :nullable="true" nullLabel="Ohne Kontakt" wire:model.live="contact_id" />
            <x-ui-input-select name="posting_id" label="Ausschreibung (optional)" :options="$this->availablePostings" optionValue="id" optionLabel="title" :nullable="true" nullLabel="Initiativbewerbung" wire:model.live="posting_id" />
            <x-ui-input-select name="rec_applicant_status_id" label="Bewerbungsstatus (optional)" :options="$this->availableStatuses" optionValue="id" optionLabel="name" :nullable="true" nullLabel="Kein Status" wire:model.live="rec_applicant_status_id" />
            <x-ui-input-date name="applied_at" label="Bewerbungsdatum" wire:model.live="applied_at" :nullable="true" />
            <x-ui-input-textarea name="notes" label="Notizen" wire:model.live="notes" placeholder="Zusätzliche Notizen (optional)" rows="3" />
        </div>
        <x-slot name="footer">
            <div class="flex justify-end gap-2">
                <x-ui-button type="button" variant="secondary-outline" wire:click="closeCreateModal">Abbrechen</x-ui-button>
                <x-ui-button type="button" variant="primary" wire:click="createApplicant">Anlegen</x-ui-button>
            </div>
        </x-slot>
    </x-ui-modal>

    <livewire:recruiting.applicant.applicant-settings-modal />

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" width="w-80" :defaultOpen="true" side="left">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">Suchen</h3>
                    <x-ui-input-text name="search" placeholder="Name, E-Mail suchen…" wire:model.live.debounce.300ms="search" />
                </div>
                <x-ui-input-select
                    name="positionFilter"
                    label="Stelle"
                    :options="$this->availablePositions"
                    optionValue="id"
                    optionLabel="title"
                    :nullable="true"
                    nullLabel="Alle Stellen"
                    wire:model.live="positionFilter"
                />
                <x-ui-input-select
                    name="activityFilter"
                    label="Tätigkeit"
                    :options="$this->availableActivities->map(fn($a) => ['value' => $a, 'label' => $a])->values()"
                    optionValue="value"
                    optionLabel="label"
                    :nullable="true"
                    nullLabel="Alle Tätigkeiten"
                    wire:model.live="activityFilter"
                />
                <x-ui-input-select
                    name="sourcePlatformFilter"
                    label="Quelle"
                    :options="$this->availableSourcePlatforms"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="Alle Quellen"
                    wire:model.live="sourcePlatformFilter"
                />
                <div class="space-y-2">
                    <label class="text-sm font-medium text-[var(--ui-secondary)]">Beworben am</label>
                    <x-ui-input-date
                        name="appliedFromFilter"
                        placeholder="von"
                        wire:model.live="appliedFromFilter"
                        :nullable="true"
                    />
                    <x-ui-input-date
                        name="appliedToFilter"
                        placeholder="bis"
                        wire:model.live="appliedToFilter"
                        :nullable="true"
                    />
                </div>
                <x-ui-input-select
                    name="statusFilter"
                    label="Status"
                    :options="$this->availableStatuses"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="Alle Status"
                    wire:model.live="statusFilter"
                />
                <x-ui-input-select
                    name="autoPilotStateFilter"
                    label="AutoPilot-State"
                    :options="$this->availableAutoPilotStates"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="Alle States"
                    wire:model.live="autoPilotStateFilter"
                />
                <x-ui-input-select
                    name="activeFilter"
                    label="Aktiv/Inaktiv"
                    :options="[['value' => '1', 'label' => 'Aktiv'], ['value' => '0', 'label' => 'Inaktiv']]"
                    optionValue="value"
                    optionLabel="label"
                    :nullable="true"
                    nullLabel="Alle"
                    wire:model.live="activeFilter"
                />
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-3">Aktionen</h3>
                    <x-ui-button variant="secondary" size="sm" wire:click="openCreateModal" class="w-full justify-start">
                        @svg('heroicon-o-plus', 'w-4 h-4') <span class="ml-2">Neuer Bewerber</span>
                    </x-ui-button>
                </div>
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
