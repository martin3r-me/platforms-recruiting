<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Interview-Termine" icon="heroicon-o-calendar-days" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Interview-Termine'],
        ]">
            <x-ui-button variant="primary" size="sm" wire:click="openCreateModal">
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Neuer Termin</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="full">
        <div class="px-4 sm:px-6 lg:px-8">
            <x-ui-panel title="Übersicht" subtitle="Alle Bewerbungsgespräch-Termine">
                <div class="flex gap-2 mb-4">
                    <x-ui-input-select
                        name="filterType"
                        :options="$this->interviewTypes"
                        optionValue="id"
                        optionLabel="name"
                        :nullable="true"
                        nullLabel="Alle Typen"
                        wire:model.live="filterType"
                    />
                    <x-ui-input-select
                        name="filterPosition"
                        :options="$this->positions"
                        optionValue="id"
                        optionLabel="title"
                        :nullable="true"
                        nullLabel="Alle Stellen"
                        wire:model.live="filterPosition"
                    />
                    <x-ui-input-select
                        name="filterStatus"
                        :options="[
                            ['value' => 'all', 'label' => 'Alle Status'],
                            ['value' => 'planned', 'label' => 'Geplant'],
                            ['value' => 'confirmed', 'label' => 'Bestätigt'],
                            ['value' => 'cancelled', 'label' => 'Abgesagt'],
                            ['value' => 'completed', 'label' => 'Abgeschlossen'],
                        ]"
                        optionValue="value"
                        optionLabel="label"
                        wire:model.live="filterStatus"
                    />
                    <x-ui-input-text name="search" placeholder="Suchen…" wire:model.live.debounce.300ms="search" class="flex-1 max-w-xs" />
                </div>
                @php
                    $anstehende = $this->upcomingInterviews;
                    $vergangene = $this->pastInterviews;
                @endphp
                <div class="overflow-x-auto" x-data="{ showPast: false }">
                    <table class="w-full table-auto border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                <th class="px-4 py-3">Datum</th>
                                <th class="px-4 py-3">Typ</th>
                                <th class="px-4 py-3">Titel</th>
                                <th class="px-4 py-3">Stelle</th>
                                <th class="px-4 py-3">Ort</th>
                                <th class="px-4 py-3">Teilnehmer</th>
                                <th class="px-4 py-3">Erinnerung</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/60">
                            @forelse($anstehende as $interview)
                                @include('recruiting::livewire.interview-schedule._row', ['interview' => $interview])
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                        @svg('heroicon-o-calendar-days', 'w-10 h-10 text-[var(--ui-muted)] mx-auto mb-2')
                                        {{-- Zwei verschiedene Nachrichten, weil es zwei verschiedene Lagen
                                             sind: gar keine Termine, oder nur vergangene (die stehen dann
                                             unten hinter dem Klapp-Schalter und sind NICHT weg). --}}
                                        <div class="text-sm">
                                            {{ $vergangene->isEmpty() ? 'Keine Termine gefunden' : 'Keine anstehenden Termine' }}
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>

                        @if($vergangene->isNotEmpty())
                            <tbody class="border-t border-[var(--ui-border)]/60">
                                <tr>
                                    <td colspan="9" class="px-4 py-2 bg-[var(--ui-muted-5)]">
                                        <button type="button" @click="showPast = !showPast"
                                            class="flex w-full items-center gap-2 text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)] hover:text-[var(--ui-secondary)]">
                                            <span class="inline-flex transition-transform" :class="showPast ? 'rotate-90' : ''">
                                                @svg('heroicon-o-chevron-right', 'w-4 h-4')
                                            </span>
                                            <span>Vergangene Termine ({{ $vergangene->count() }})</span>
                                            <span class="ml-auto font-normal normal-case tracking-normal"
                                                  x-text="showPast ? 'ausblenden' : 'anzeigen'"></span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            {{-- style statt x-cloak: im Projekt ist keine [x-cloak]-CSS-Regel
                                 definiert (vgl. statistics/interviews-table.blade.php) --}}
                            <tbody x-show="showPast" style="display: none;" class="divide-y divide-[var(--ui-border)]/60">
                                @foreach($vergangene as $interview)
                                    @include('recruiting::livewire.interview-schedule._row', ['interview' => $interview])
                                @endforeach
                            </tbody>
                        @endif
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
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->interviews->count() }}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Geplant</span>
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->interviews->where('status', 'planned')->count() }}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Abgeschlossen</span>
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->interviews->where('status', 'completed')->count() }}</span>
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
                        <div class="font-medium text-[var(--ui-secondary)] truncate">Termin-Übersicht geladen</div>
                        <div class="text-[var(--ui-muted)]">{{ now()->format('d.m.Y H:i') }}</div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Create Modal --}}
    <x-ui-modal wire:model="showCreateModal">
        <x-slot name="header">Neuen Termin anlegen</x-slot>
        <div class="space-y-4">
            <div class="grid grid-cols-3 gap-4">
                <div class="col-span-2">
                    <x-ui-input-text name="title" label="Titel" wire:model="title" />
                </div>
                <x-ui-input-select
                    name="language"
                    label="Sprache"
                    :options="[['value' => 'de', 'label' => 'Deutsch'], ['value' => 'en', 'label' => 'Englisch']]"
                    optionValue="value"
                    optionLabel="label"
                    wire:model="language"
                />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-select
                    name="interview_type_id"
                    label="Gesprächsart"
                    :options="$this->interviewTypes"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="— Keine —"
                    wire:model="interview_type_id"
                />
                <x-ui-input-select
                    name="rec_position_id"
                    label="Stelle"
                    :options="$this->positions"
                    optionValue="id"
                    optionLabel="title"
                    :nullable="true"
                    nullLabel="— Keine —"
                    wire:model="rec_position_id"
                />
            </div>
            <x-ui-input-select
                name="rec_posting_id"
                label="Ausschreibung"
                :options="$this->postingOptions"
                :nullable="true"
                nullLabel="keine Zuordnung"
                wire:model="rec_posting_id"
                hint="Für welche Ausschreibung diese Schulung stattfindet. Ohne Zuordnung wird in der Statistik der Titel angezeigt."
            />
            <div class="space-y-2">
                @php $eventLocations = $this->availableEventLocations; @endphp
                @if($eventLocations->isNotEmpty())
                    <label class="text-sm font-medium text-[var(--ui-secondary)]">Ort</label>
                    <select wire:model.live="selectedEventLocationId"
                            class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                        <option value="">— Vordefinierten Ort wählen oder eigene Adresse eingeben —</option>
                        @foreach($eventLocations as $loc)
                            <option value="{{ $loc->id }}">{{ $loc->label }} — {{ $loc->full_address }}</option>
                        @endforeach
                    </select>
                    <input type="text"
                           wire:model="location"
                           placeholder="Adresse (volle Anschrift, wird im Reminder-Template verwendet)"
                           class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                @else
                    <x-ui-input-text name="location" label="Ort" wire:model="location" hint="Tipp: pflege wiederkehrende Veranstaltungsorte unter Recruiting → Veranstaltungsorte." />
                @endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-text name="starts_at" label="Start *" wire:model="starts_at" type="datetime-local" required />
                <x-ui-input-text name="ends_at" label="Ende" wire:model="ends_at" type="datetime-local" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-text name="min_participants" label="Min. Teilnehmer" wire:model="min_participants" type="number" />
                <x-ui-input-text name="max_participants" label="Max. Teilnehmer" wire:model="max_participants" type="number" />
            </div>
            <x-ui-input-textarea name="description" label="Beschreibung" wire:model="description" />
            <div>
                <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Interviewer</label>
                <div class="max-h-40 overflow-y-auto border border-[var(--ui-border)] rounded-md p-2 space-y-1">
                    @foreach($this->teamUsers as $user)
                        <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-gray-50 p-1 rounded">
                            <input type="checkbox" wire:model="selectedInterviewers" value="{{ $user->id }}" class="rounded border-gray-300">
                            {{ $user->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            @if($this->availableWhatsAppTemplates->isNotEmpty())
                <div class="border-t border-[var(--ui-border)]/60 pt-4">
                    <label class="block text-sm font-bold text-[var(--ui-secondary)] mb-2">WhatsApp-Erinnerung</label>
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui-input-select
                            name="reminder_wa_template_id"
                            label="WA-Template"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="name"
                            :nullable="true"
                            nullLabel="— Keine Erinnerung —"
                            wire:model.live="reminder_wa_template_id"
                        />
                        <x-ui-input-text name="reminder_hours_before" label="Stunden vorher" wire:model="reminder_hours_before" type="number" min="1" placeholder="z.B. 24" />
                    </div>
                    @if($this->selectedTemplateInfo && ($this->selectedTemplateInfo['body_var_count'] > 0 || $this->selectedTemplateInfo['has_url_button']))
                        <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <label class="block text-xs font-bold text-blue-800 mb-2">Template-Variablen zuordnen</label>
                            <div class="space-y-2">
                                @for($i = 1; $i <= $this->selectedTemplateInfo['body_var_count']; $i++)
                                    @php
                                        $paramLabel = $this->selectedTemplateInfo['param_labels'][$i] ?? $i;
                                        $paramDisplay = '{{ ' . $paramLabel . ' }}';
                                    @endphp
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-blue-700 w-28 shrink-0">
                                            {{ $paramDisplay }}
                                        </span>
                                        <select wire:model="reminder_wa_template_variables.body_{{ $i }}" class="flex-1 text-xs border border-blue-300 rounded px-2 py-1">
                                            <option value="">— Nicht zugeordnet —</option>
                                            @foreach(\Platform\Recruiting\Models\RecInterview::TEMPLATE_VARIABLE_SOURCES as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endfor
                                @if($this->selectedTemplateInfo['has_url_button'])
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-blue-700 w-28 shrink-0">URL-Button</span>
                                        <select wire:model="reminder_wa_template_variables.url_button" class="flex-1 text-xs border border-blue-300 rounded px-2 py-1">
                                            <option value="">— Nicht zugeordnet —</option>
                                            @foreach(\Platform\Recruiting\Models\RecInterview::TEMPLATE_VARIABLE_SOURCES as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endif
            <x-ui-input-select
                name="status"
                label="Status"
                :options="[
                    ['value' => 'planned', 'label' => 'Geplant'],
                    ['value' => 'confirmed', 'label' => 'Bestätigt'],
                    ['value' => 'cancelled', 'label' => 'Abgesagt'],
                    ['value' => 'completed', 'label' => 'Abgeschlossen'],
                ]"
                optionValue="value"
                optionLabel="label"
                wire:model="status"
            />
            <x-ui-input-checkbox model="is_active" name="is_active" wire:model="is_active" checked-label="Aktiv" unchecked-label="Inaktiv" />
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary" wire:click="closeModals">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="save">Speichern</x-ui-button>
        </x-slot>
    </x-ui-modal>

    {{-- Edit Modal --}}
    <x-ui-modal wire:model="showEditModal">
        <x-slot name="header">Termin bearbeiten</x-slot>
        <div class="space-y-4">
            <div class="grid grid-cols-3 gap-4">
                <div class="col-span-2">
                    <x-ui-input-text name="title" label="Titel" wire:model="title" />
                </div>
                <x-ui-input-select
                    name="language"
                    label="Sprache"
                    :options="[['value' => 'de', 'label' => 'Deutsch'], ['value' => 'en', 'label' => 'Englisch']]"
                    optionValue="value"
                    optionLabel="label"
                    wire:model="language"
                />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-select
                    name="interview_type_id"
                    label="Gesprächsart"
                    :options="$this->interviewTypes"
                    optionValue="id"
                    optionLabel="name"
                    :nullable="true"
                    nullLabel="— Keine —"
                    wire:model="interview_type_id"
                />
                <x-ui-input-select
                    name="rec_position_id"
                    label="Stelle"
                    :options="$this->positions"
                    optionValue="id"
                    optionLabel="title"
                    :nullable="true"
                    nullLabel="— Keine —"
                    wire:model="rec_position_id"
                />
            </div>
            <x-ui-input-select
                name="rec_posting_id"
                label="Ausschreibung"
                :options="$this->postingOptions"
                :nullable="true"
                nullLabel="keine Zuordnung"
                wire:model="rec_posting_id"
                hint="Für welche Ausschreibung diese Schulung stattfindet. Ohne Zuordnung wird in der Statistik der Titel angezeigt."
            />
            <div class="space-y-2">
                @php $eventLocations = $this->availableEventLocations; @endphp
                @if($eventLocations->isNotEmpty())
                    <label class="text-sm font-medium text-[var(--ui-secondary)]">Ort</label>
                    <select wire:model.live="selectedEventLocationId"
                            class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                        <option value="">— Vordefinierten Ort wählen oder eigene Adresse eingeben —</option>
                        @foreach($eventLocations as $loc)
                            <option value="{{ $loc->id }}">{{ $loc->label }} — {{ $loc->full_address }}</option>
                        @endforeach
                    </select>
                    <input type="text"
                           wire:model="location"
                           placeholder="Adresse (volle Anschrift, wird im Reminder-Template verwendet)"
                           class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]">
                @else
                    <x-ui-input-text name="location" label="Ort" wire:model="location" hint="Tipp: pflege wiederkehrende Veranstaltungsorte unter Recruiting → Veranstaltungsorte." />
                @endif
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-text name="starts_at" label="Start *" wire:model="starts_at" type="datetime-local" required />
                <x-ui-input-text name="ends_at" label="Ende" wire:model="ends_at" type="datetime-local" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <x-ui-input-text name="min_participants" label="Min. Teilnehmer" wire:model="min_participants" type="number" />
                <x-ui-input-text name="max_participants" label="Max. Teilnehmer" wire:model="max_participants" type="number" />
            </div>
            <x-ui-input-textarea name="description" label="Beschreibung" wire:model="description" />
            <div>
                <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Interviewer</label>
                <div class="max-h-40 overflow-y-auto border border-[var(--ui-border)] rounded-md p-2 space-y-1">
                    @foreach($this->teamUsers as $user)
                        <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-gray-50 p-1 rounded">
                            <input type="checkbox" wire:model="selectedInterviewers" value="{{ $user->id }}" class="rounded border-gray-300">
                            {{ $user->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            @if($this->availableWhatsAppTemplates->isNotEmpty())
                <div class="border-t border-[var(--ui-border)]/60 pt-4">
                    <label class="block text-sm font-bold text-[var(--ui-secondary)] mb-2">WhatsApp-Erinnerung</label>
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui-input-select
                            name="reminder_wa_template_id"
                            label="WA-Template"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="name"
                            :nullable="true"
                            nullLabel="— Keine Erinnerung —"
                            wire:model.live="reminder_wa_template_id"
                        />
                        <x-ui-input-text name="reminder_hours_before" label="Stunden vorher" wire:model="reminder_hours_before" type="number" min="1" placeholder="z.B. 24" />
                    </div>
                    @if($this->selectedTemplateInfo && ($this->selectedTemplateInfo['body_var_count'] > 0 || $this->selectedTemplateInfo['has_url_button']))
                        <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <label class="block text-xs font-bold text-blue-800 mb-2">Template-Variablen zuordnen</label>
                            <div class="space-y-2">
                                @for($i = 1; $i <= $this->selectedTemplateInfo['body_var_count']; $i++)
                                    @php
                                        $paramLabel = $this->selectedTemplateInfo['param_labels'][$i] ?? $i;
                                        $paramDisplay = '{{ ' . $paramLabel . ' }}';
                                    @endphp
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-blue-700 w-28 shrink-0">
                                            {{ $paramDisplay }}
                                        </span>
                                        <select wire:model="reminder_wa_template_variables.body_{{ $i }}" class="flex-1 text-xs border border-blue-300 rounded px-2 py-1">
                                            <option value="">— Nicht zugeordnet —</option>
                                            @foreach(\Platform\Recruiting\Models\RecInterview::TEMPLATE_VARIABLE_SOURCES as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endfor
                                @if($this->selectedTemplateInfo['has_url_button'])
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-blue-700 w-28 shrink-0">URL-Button</span>
                                        <select wire:model="reminder_wa_template_variables.url_button" class="flex-1 text-xs border border-blue-300 rounded px-2 py-1">
                                            <option value="">— Nicht zugeordnet —</option>
                                            @foreach(\Platform\Recruiting\Models\RecInterview::TEMPLATE_VARIABLE_SOURCES as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endif
            <x-ui-input-select
                name="status"
                label="Status"
                :options="[
                    ['value' => 'planned', 'label' => 'Geplant'],
                    ['value' => 'confirmed', 'label' => 'Bestätigt'],
                    ['value' => 'cancelled', 'label' => 'Abgesagt'],
                    ['value' => 'completed', 'label' => 'Abgeschlossen'],
                ]"
                optionValue="value"
                optionLabel="label"
                wire:model="status"
            />
            <x-ui-input-checkbox model="is_active" name="is_active" wire:model="is_active" checked-label="Aktiv" unchecked-label="Inaktiv" />
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary" wire:click="closeModals">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="save">Speichern</x-ui-button>
        </x-slot>
    </x-ui-modal>
</x-ui-page>
