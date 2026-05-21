@php
    $employee = $this->employee;
    $name = $employee
        ? (trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) ?: 'Mitarbeiter #' . $employee->id)
        : 'Mitarbeiter';
@endphp
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$name" icon="heroicon-o-identification" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Mitarbeiter', 'href' => route('recruiting.employees.index')],
            ['label' => $name],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        @if(!$employee)
            <div class="p-8 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
                Mitarbeiter nicht gefunden.
            </div>
        @else
            {{-- Quick-Info-Bar --}}
            <div class="bg-emerald-50/40 border border-emerald-200 rounded-lg p-3 flex flex-wrap items-center gap-4 text-xs text-[var(--ui-secondary)]">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                    @svg('heroicon-o-identification', 'w-3 h-3')
                    Mitarbeiter
                </span>
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

            @if($flash)
                <div class="mt-3 p-2 bg-green-50 border border-green-200 rounded text-xs text-green-800 inline-flex items-center gap-2">
                    @svg('heroicon-o-check-circle', 'w-4 h-4')
                    {{ $flash }}
                </div>
            @endif

            {{-- Felder — responsive grid (1 col mobile, 2 col md, 3 col xl) --}}
            <div class="mt-4 space-y-5">
                @foreach($this->fieldGroups() as $section => $fields)
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)] mb-2">{{ $section }}</h3>
                        <div class="bg-white border border-[var(--ui-border)] rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-3">
                            @foreach($fields as $key => $meta)
                                @php
                                    $type = $meta['type'];
                                    $label = $meta['label'];
                                    $currentValue = $employee->getAttribute($key);
                                    $isMissing = ($currentValue === null || $currentValue === '' || $currentValue === []);
                                    $inputBorder = $isMissing ? 'border-red-300' : 'border-[var(--ui-border)]';
                                    // File-Felder + lange Selects bekommen 2 Spalten auf xl-Screens
                                    $spanClass = in_array($type, ['file', 'position', 'multi_lookup'], true) ? 'xl:col-span-2' : '';
                                @endphp
                                <div class="{{ $spanClass }}">
                                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">{{ $label }}</label>

                                    @if($type === 'lookup')
                                        <select wire:model.defer="fieldValues.{{ $key }}"
                                                class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white">
                                            <option value="">— bitte wählen —</option>
                                            @foreach($this->lookupOptionsFor($meta['lookup']) as $optValue => $optLabel)
                                                <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                            @endforeach
                                        </select>

                                    @elseif($type === 'multi_lookup')
                                        <div class="border {{ $inputBorder }} rounded-md px-3 py-2 text-sm bg-white flex flex-wrap gap-x-4 gap-y-1.5">
                                            @foreach($this->lookupOptionsFor($meta['lookup']) as $optValue => $optLabel)
                                                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                                    <input type="checkbox"
                                                           wire:model.defer="fieldValues.{{ $key }}"
                                                           value="{{ $optValue }}"
                                                           class="rounded border-[var(--ui-border)]" />
                                                    <span>{{ $optLabel }}</span>
                                                </label>
                                            @endforeach
                                        </div>

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

                                    @elseif($type === 'inline_select')
                                        <select wire:model.defer="fieldValues.{{ $key }}"
                                                class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white">
                                            <option value="">— bitte wählen —</option>
                                            @foreach(($meta['options'] ?? []) as $opt)
                                                <option value="{{ $opt }}">{{ $opt }}</option>
                                            @endforeach
                                        </select>

                                    @elseif($type === 'file')
                                        @php $uploadProp = $this->uploadPropertyFor($key); @endphp
                                        <div class="flex items-center gap-3 p-2 rounded-md border {{ $isMissing ? 'border-red-300' : 'border-[var(--ui-border)]' }}">
                                            <div class="flex-1 min-w-0 text-sm">
                                                @if($isMissing)
                                                    <span class="text-[var(--ui-muted)] text-xs">Keine Datei hochgeladen</span>
                                                @else
                                                    <span class="inline-flex items-center gap-1.5 text-[var(--ui-secondary)] truncate">
                                                        @svg('heroicon-o-document-check', 'w-4 h-4 text-emerald-600 flex-shrink-0')
                                                        <span class="truncate">{{ $this->fileNameFor($currentValue) ?? 'Datei #' . $currentValue }}</span>
                                                    </span>
                                                @endif
                                            </div>
                                            @if($uploadProp)
                                                <label class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[var(--ui-secondary)] text-white text-xs font-medium rounded-md hover:opacity-90 transition cursor-pointer flex-shrink-0">
                                                    @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                                                    @if($isMissing) Hochladen @else Ersetzen @endif
                                                    <input type="file" wire:model="{{ $uploadProp }}"
                                                           accept="image/*,.pdf" class="hidden" />
                                                </label>
                                                <div wire:loading wire:target="{{ $uploadProp }}" class="text-xs text-[var(--ui-muted)] flex-shrink-0">
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

            {{-- HR-Vertraulich (separate Entity rec_employee_hr_data) --}}
            <div class="mt-5">
                @foreach($this->hrFieldGroups() as $section => $fields)
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-700 mb-2">{{ $section }}</h3>
                    <div class="bg-amber-50/30 border border-amber-200 rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-3">
                        @foreach($fields as $key => $meta)
                            @php
                                $type = $meta['type'];
                                $label = $meta['label'];
                                $isReadonly = ($meta['readonly'] ?? false) === true;
                                $hrValue = $employee->ensureHrData()->getAttribute($key);
                                $isMissing = !$isReadonly && ($hrValue === null || $hrValue === '' || $hrValue === []);
                                $inputBorder = $isMissing ? 'border-red-300' : 'border-[var(--ui-border)]';
                            @endphp
                            <div>
                                <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">{{ $label }}</label>

                                @if($isReadonly)
                                    <div class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white text-[var(--ui-secondary)]">
                                        {{ $hrValue ?: ($meta['options'][0] ?? 'GO') }}
                                    </div>

                                @elseif($type === 'lookup')
                                    <select wire:model.defer="hrFieldValues.{{ $key }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white">
                                        <option value="">— bitte wählen —</option>
                                        @foreach($this->lookupOptionsFor($meta['lookup']) as $optValue => $optLabel)
                                            <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                        @endforeach
                                    </select>

                                @elseif($type === 'date')
                                    <input type="date" wire:model.defer="hrFieldValues.{{ $key }}"
                                           class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm" />

                                @elseif($type === 'inline_select')
                                    <select wire:model.defer="hrFieldValues.{{ $key }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white">
                                        <option value="">— bitte wählen —</option>
                                        @foreach(($meta['options'] ?? []) as $opt)
                                            <option value="{{ $opt }}">{{ $opt }}</option>
                                        @endforeach
                                    </select>

                                @elseif($type === 'multi_lookup')
                                    <div class="border {{ $inputBorder }} rounded-md px-3 py-2 text-sm bg-white flex flex-wrap gap-x-4 gap-y-1.5">
                                        @foreach($this->lookupOptionsFor($meta['lookup']) as $optValue => $optLabel)
                                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                                <input type="checkbox"
                                                       wire:model.defer="hrFieldValues.{{ $key }}"
                                                       value="{{ $optValue }}"
                                                       class="rounded border-[var(--ui-border)]" />
                                                <span>{{ $optLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>

                                @else
                                    <input type="text" wire:model.defer="hrFieldValues.{{ $key }}"
                                           placeholder="{{ $label }}"
                                           class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
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
    </x-ui-page-container>
</x-ui-page>
