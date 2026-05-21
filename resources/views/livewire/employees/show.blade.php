@php
    $employee = $this->employee;
@endphp
<div class="p-6">
    @if(!$employee)
        <div class="p-8 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
            Mitarbeiter nicht gefunden.
        </div>
    @else
        @php
            $name = trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) ?: 'Mitarbeiter #' . $employee->id;
        @endphp

        <x-ui-page-navbar :title="$name" icon="heroicon-o-identification">
            <x-slot:actions>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                    @svg('heroicon-o-identification', 'w-3.5 h-3.5')
                    Mitarbeiter
                </span>
            </x-slot:actions>
        </x-ui-page-navbar>

        <x-ui-breadcrumbs :items="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard')],
            ['label' => 'Mitarbeiter', 'href' => route('recruiting.employees.index')],
            ['label' => $name],
        ]" />

        {{-- Quick-Info-Bar --}}
        <div class="mt-4 bg-emerald-50/40 border border-emerald-200 rounded-lg p-3 flex flex-wrap items-center gap-4 text-xs text-[var(--ui-secondary)]">
            @if($employee->position)
                <div class="inline-flex items-center gap-1.5">
                    @svg('heroicon-o-briefcase', 'w-4 h-4 text-[var(--ui-muted)]')
                    <span>{{ $employee->position->title }}</span>
                </div>
            @endif
            @if($employee->employed_since)
                <div class="inline-flex items-center gap-1.5">
                    @svg('heroicon-o-calendar', 'w-4 h-4 text-[var(--ui-muted)]')
                    <span>seit {{ $employee->employed_since->format('d.m.Y') }}</span>
                </div>
            @endif
            @if($employee->applicant)
                <div class="inline-flex items-center gap-1.5">
                    @svg('heroicon-o-arrow-uturn-left', 'w-4 h-4 text-[var(--ui-muted)]')
                    <a href="{{ route('recruiting.applicants.show', $employee->applicant->id) }}" wire:navigate
                       class="text-[var(--ui-primary)] hover:underline">Original-Bewerber</a>
                </div>
            @endif
            @if($employee->portal_token)
                <div class="inline-flex items-center gap-1.5">
                    @svg('heroicon-o-link', 'w-4 h-4 text-[var(--ui-muted)]')
                    <a href="{{ route('recruiting.public.employee-portal', ['token' => $employee->portal_token]) }}"
                       target="_blank" class="text-[var(--ui-primary)] hover:underline">MA-Portal-Link</a>
                </div>
            @endif
        </div>

        {{-- Flash --}}
        @if($flash)
            <div class="mt-3 p-2 bg-green-50 border border-green-200 rounded text-xs text-green-800 inline-flex items-center gap-2">
                @svg('heroicon-o-check-circle', 'w-4 h-4')
                {{ $flash }}
            </div>
        @endif

        {{-- Felder --}}
        <div class="mt-4 space-y-5">
            @foreach($this->fieldGroups() as $section => $fields)
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)] mb-2">{{ $section }}</h3>
                    <div class="bg-white border border-[var(--ui-border)] rounded-lg divide-y divide-[var(--ui-border)]">
                        @foreach($fields as $key => $meta)
                            @php
                                $type = $meta['type'];
                                $label = $meta['label'];
                                $currentValue = $employee->getAttribute($key);
                                $isMissing = ($currentValue === null || $currentValue === '' || $currentValue === []);
                                $inputBorder = $isMissing ? 'border-red-300' : 'border-[var(--ui-border)]';
                            @endphp
                            <div class="p-3">
                                <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1.5">{{ $label }}</label>

                                @if($type === 'lookup')
                                    <select wire:model.defer="fieldValues.{{ $key }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white">
                                        <option value="">— bitte wählen —</option>
                                        @foreach($this->lookupOptionsFor($meta['lookup']) as $optValue => $optLabel)
                                            <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                        @endforeach
                                    </select>

                                @elseif($type === 'bool')
                                    <select wire:model.defer="fieldValues.{{ $key }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white">
                                        <option value="">— bitte wählen —</option>
                                        <option value="1">Ja</option>
                                        <option value="0">Nein</option>
                                    </select>

                                @elseif($type === 'date')
                                    <input type="date" wire:model.defer="fieldValues.{{ $key }}"
                                           class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm" />

                                @elseif($type === 'datetime')
                                    <input type="datetime-local" wire:model.defer="fieldValues.{{ $key }}"
                                           class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm" />

                                @elseif($type === 'position')
                                    <select wire:model.defer="fieldValues.{{ $key }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white">
                                        <option value="">— keine Stelle —</option>
                                        @foreach($this->positions as $pos)
                                            <option value="{{ $pos->id }}">{{ $pos->title }}</option>
                                        @endforeach
                                    </select>

                                @elseif($type === 'file')
                                    @php $uploadProp = $this->uploadPropertyFor($key); @endphp
                                    <div class="flex items-center gap-3 p-2 rounded-md border {{ $isMissing ? 'border-red-300' : 'border-[var(--ui-border)]' }}">
                                        <div class="flex-1 text-sm">
                                            @if($isMissing)
                                                <span class="text-[var(--ui-muted)] text-xs">Keine Datei hochgeladen</span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 text-[var(--ui-secondary)]">
                                                    @svg('heroicon-o-document-check', 'w-4 h-4 text-emerald-600')
                                                    {{ $this->fileNameFor($currentValue) ?? 'Datei #' . $currentValue }}
                                                </span>
                                            @endif
                                        </div>
                                        @if($uploadProp)
                                            <label class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[var(--ui-secondary)] text-white text-xs font-medium rounded-md hover:opacity-90 transition cursor-pointer">
                                                @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                                                @if($isMissing) Hochladen @else Ersetzen @endif
                                                <input type="file" wire:model="{{ $uploadProp }}"
                                                       accept="image/*,.pdf" class="hidden" />
                                            </label>
                                            <div wire:loading wire:target="{{ $uploadProp }}" class="text-xs text-[var(--ui-muted)]">
                                                Lade hoch...
                                            </div>
                                        @endif
                                    </div>

                                @else
                                    <input type="text" wire:model.defer="fieldValues.{{ $key }}"
                                           placeholder="{{ $label }}"
                                           class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- HR-only Daten (separate Entity) — placeholder, Felder kommen iterativ --}}
        <div class="mt-5">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-700 mb-2">HR-Vertraulich</h3>
            <div class="bg-amber-50/40 border border-amber-200 rounded-lg p-4 text-sm text-[var(--ui-muted)]">
                Diese Sektion ist fuer HR-only-Felder reserviert (z.B. Probezeit, Notizen, interner Status).
                Die Tabelle <code class="text-xs">rec_employee_hr_data</code> ist bereits angelegt — Felder werden iterativ ergaenzt.
            </div>
        </div>

        {{-- Sticky Save --}}
        <div class="sticky bottom-0 bg-[var(--ui-surface)] pt-4 mt-6 flex items-center justify-between gap-3 border-t border-[var(--ui-border)]">
            <a href="{{ route('recruiting.employees.index') }}" wire:navigate
               class="text-xs text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] underline">
                ← Zurück zur Liste
            </a>
            <button wire:click="saveAll" wire:loading.attr="disabled" wire:target="saveAll"
                    class="inline-flex items-center gap-2 px-5 py-2 bg-[var(--ui-primary)] text-white text-sm font-semibold rounded-md hover:bg-[var(--ui-primary)]/90 transition-colors disabled:opacity-50">
                @svg('heroicon-o-check', 'w-4 h-4')
                <span wire:loading.remove wire:target="saveAll">Alle Änderungen speichern</span>
                <span wire:loading wire:target="saveAll">Speichere...</span>
            </button>
        </div>
    @endif
</div>
