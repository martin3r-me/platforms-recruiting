<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Interview-Termine" icon="heroicon-o-calendar-days" />
    </x-slot>

    <x-ui-page-container>
        <div class="px-4 sm:px-6 lg:px-8">
            <x-ui-panel title="Übersicht" subtitle="Alle Bewerbungsgespräch-Termine">
                <div class="flex gap-2 mb-4">
                    <select wire:model.live="filterType" class="text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="">Alle Typen</option>
                        @foreach($this->interviewTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="filterPosition" class="text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="">Alle Stellen</option>
                        @foreach($this->positions as $pos)
                            <option value="{{ $pos->id }}">{{ $pos->title }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="filterStatus" class="text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="all">Alle Status</option>
                        <option value="planned">Geplant</option>
                        <option value="confirmed">Bestätigt</option>
                        <option value="cancelled">Abgesagt</option>
                        <option value="completed">Abgeschlossen</option>
                    </select>
                    <x-ui-input-text name="search" placeholder="Suchen…" wire:model.live.debounce.300ms="search" class="flex-1 max-w-xs" />
                </div>
                <div class="overflow-x-auto">
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
                            @forelse($this->interviews as $interview)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <div class="font-medium">{{ $interview->starts_at->format('d.m.Y') }}</div>
                                        <div class="text-xs text-[var(--ui-muted)]">
                                            {{ $interview->starts_at->format('H:i') }}
                                            @if($interview->ends_at)
                                                — {{ $interview->ends_at->format('H:i') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($interview->interviewType)
                                            <x-ui-badge variant="secondary" size="xs">{{ $interview->interviewType->name }}</x-ui-badge>
                                        @else
                                            <span class="text-[var(--ui-muted)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-medium">{{ $interview->title ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $interview->position->title ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $interview->location ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium">{{ $interview->bookings->whereNotIn('status', ['cancelled'])->count() }}</span>
                                        @if($interview->max_participants)
                                            <span class="text-[var(--ui-muted)]">/ {{ $interview->max_participants }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($interview->reminder_wa_template_id && $interview->reminder_hours_before)
                                            <x-ui-badge variant="info" size="xs">WA {{ $interview->reminder_hours_before }}h</x-ui-badge>
                                        @else
                                            <span class="text-[var(--ui-muted)]">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($interview->status === 'planned')
                                            <x-ui-badge variant="warning" size="xs">Geplant</x-ui-badge>
                                        @elseif($interview->status === 'confirmed')
                                            <x-ui-badge variant="info" size="xs">Bestätigt</x-ui-badge>
                                        @elseif($interview->status === 'cancelled')
                                            <x-ui-badge variant="danger" size="xs">Abgesagt</x-ui-badge>
                                        @elseif($interview->status === 'completed')
                                            <x-ui-badge variant="success" size="xs">Abgeschlossen</x-ui-badge>
                                        @else
                                            <x-ui-badge variant="secondary" size="xs">{{ $interview->status }}</x-ui-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex gap-2">
                                            <a href="{{ route('recruiting.interview-bookings.index', $interview->id) }}" wire:navigate>
                                                <x-ui-button variant="secondary-outline" size="xs">
                                                    Buchungen
                                                </x-ui-button>
                                            </a>
                                            <x-ui-button variant="secondary-outline" size="xs" wire:click="openEditModal({{ $interview->id }})">
                                                Bearbeiten
                                            </x-ui-button>
                                            <x-ui-button variant="danger-outline" size="xs" wire:click="delete({{ $interview->id }})">
                                                Löschen
                                            </x-ui-button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                        @svg('heroicon-o-calendar-days', 'w-10 h-10 text-[var(--ui-muted)] mx-auto mb-2')
                                        <div class="text-sm">Keine Termine gefunden</div>
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
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-4">Aktionen</h3>
                    <div class="space-y-2">
                        <x-ui-button variant="primary" size="sm" class="w-full" wire:click="openCreateModal">
                            <span class="inline-flex items-center gap-2">
                                @svg('heroicon-o-plus', 'w-4 h-4')
                                Neuer Termin
                            </span>
                        </x-ui-button>
                    </div>
                </div>

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
            <x-ui-input-text name="title" label="Titel" wire:model="title" />
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Gesprächsart</label>
                    <select wire:model="interview_type_id" class="w-full text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="">— Keine —</option>
                        @foreach($this->interviewTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Stelle</label>
                    <select wire:model="rec_position_id" class="w-full text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="">— Keine —</option>
                        @foreach($this->positions as $pos)
                            <option value="{{ $pos->id }}">{{ $pos->title }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <x-ui-input-text name="location" label="Ort" wire:model="location" />
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
                        <div>
                            <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">WA-Template</label>
                            <select wire:model.live="reminder_wa_template_id" class="w-full text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                                <option value="">— Keine Erinnerung —</option>
                                @foreach($this->availableWhatsAppTemplates as $tpl)
                                    <option value="{{ $tpl->id }}">{{ $tpl->name }} ({{ $tpl->language }})</option>
                                @endforeach
                            </select>
                        </div>
                        <x-ui-input-text name="reminder_hours_before" label="Stunden vorher" wire:model="reminder_hours_before" type="number" min="1" placeholder="z.B. 24" />
                    </div>
                    @if($this->selectedTemplateInfo && ($this->selectedTemplateInfo['body_var_count'] > 0 || $this->selectedTemplateInfo['has_url_button']))
                        <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <label class="block text-xs font-bold text-blue-800 mb-2">Template-Variablen zuordnen</label>
                            <div class="space-y-2">
                                @for($i = 1; $i <= $this->selectedTemplateInfo['body_var_count']; $i++)
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-blue-700 w-28 shrink-0">
                                            @{{ {{ $this->selectedTemplateInfo['param_labels'][$i] ?? $i }} }}
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
            <div>
                <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Status</label>
                <select wire:model="status" class="w-full text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                    <option value="planned">Geplant</option>
                    <option value="confirmed">Bestätigt</option>
                    <option value="cancelled">Abgesagt</option>
                    <option value="completed">Abgeschlossen</option>
                </select>
            </div>
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
            <x-ui-input-text name="title" label="Titel" wire:model="title" />
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Gesprächsart</label>
                    <select wire:model="interview_type_id" class="w-full text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="">— Keine —</option>
                        @foreach($this->interviewTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Stelle</label>
                    <select wire:model="rec_position_id" class="w-full text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                        <option value="">— Keine —</option>
                        @foreach($this->positions as $pos)
                            <option value="{{ $pos->id }}">{{ $pos->title }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <x-ui-input-text name="location" label="Ort" wire:model="location" />
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
                        <div>
                            <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">WA-Template</label>
                            <select wire:model.live="reminder_wa_template_id" class="w-full text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                                <option value="">— Keine Erinnerung —</option>
                                @foreach($this->availableWhatsAppTemplates as $tpl)
                                    <option value="{{ $tpl->id }}">{{ $tpl->name }} ({{ $tpl->language }})</option>
                                @endforeach
                            </select>
                        </div>
                        <x-ui-input-text name="reminder_hours_before" label="Stunden vorher" wire:model="reminder_hours_before" type="number" min="1" placeholder="z.B. 24" />
                    </div>
                    @if($this->selectedTemplateInfo && ($this->selectedTemplateInfo['body_var_count'] > 0 || $this->selectedTemplateInfo['has_url_button']))
                        <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <label class="block text-xs font-bold text-blue-800 mb-2">Template-Variablen zuordnen</label>
                            <div class="space-y-2">
                                @for($i = 1; $i <= $this->selectedTemplateInfo['body_var_count']; $i++)
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-blue-700 w-28 shrink-0">
                                            @{{ {{ $this->selectedTemplateInfo['param_labels'][$i] ?? $i }} }}
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
            <div>
                <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Status</label>
                <select wire:model="status" class="w-full text-sm border border-[var(--ui-border)] rounded-md px-3 py-2">
                    <option value="planned">Geplant</option>
                    <option value="confirmed">Bestätigt</option>
                    <option value="cancelled">Abgesagt</option>
                    <option value="completed">Abgeschlossen</option>
                </select>
            </div>
            <x-ui-input-checkbox model="is_active" name="is_active" wire:model="is_active" checked-label="Aktiv" unchecked-label="Inaktiv" />
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary" wire:click="closeModals">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="save">Speichern</x-ui-button>
        </x-slot>
    </x-ui-modal>
</x-ui-page>
