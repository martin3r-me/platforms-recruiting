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

    <x-ui-page-container width="full">
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

            @if($flashError)
                <div class="mt-3 p-2 bg-red-50 border border-red-200 rounded text-xs text-red-800 inline-flex items-center gap-2">
                    @svg('heroicon-o-exclamation-circle', 'w-4 h-4')
                    {{ $flashError }}
                </div>
            @endif

            {{-- Felder — responsive grid (1 col mobile, 2 col md, 3 col xl) --}}
            <div class="mt-4 space-y-5">
                @foreach($this->fieldGroups() as $section => $fields)
                    @php $isHrOnly = str_contains($section, 'HR-only'); @endphp
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide {{ $isHrOnly ? 'text-amber-700' : 'text-[var(--ui-muted)]' }} mb-2">{{ $section }}</h3>
                        <div class="{{ $isHrOnly ? 'bg-amber-50/30 border-amber-200' : 'bg-white border-[var(--ui-border)]' }} border rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-3">
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
                                                    <a href="{{ route('recruiting.employees.files', ['employee' => $employeeId, 'slot' => $key]) }}"
                                                       target="_blank" rel="noopener"
                                                       title="Dokument in neuem Tab öffnen"
                                                       class="inline-flex items-center gap-1.5 text-[var(--ui-secondary)] truncate hover:underline">
                                                        @svg('heroicon-o-document-check', 'w-4 h-4 text-emerald-600 flex-shrink-0')
                                                        <span class="truncate">{{ $this->fileNameFor($currentValue) ?? 'Datei #' . $currentValue }}</span>
                                                        @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5 text-[var(--ui-muted)] flex-shrink-0')
                                                    </a>
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
                                // Date-Casts wuerden als "2026-08-19 00:00:00" gerendert.
                                $displayValue = $hrValue instanceof \DateTimeInterface ? $hrValue->format('d.m.Y') : $hrValue;
                            @endphp
                            <div>
                                <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">{{ $label }}</label>

                                @if($isReadonly)
                                    {{-- Leerwert-Anzeige: 'empty' aus den Feld-Metadaten; ohne 'empty'
                                         greift aus Rueckwaertskompatibilitaet der erste Options-Wert
                                         (nur fuer export_status sinnvoll, weil dort genau eine Option
                                         existiert). Neue readonly-Felder mit mehreren Optionen MUESSEN
                                         'empty' setzen, sonst wird ein Wert angezeigt, den niemand
                                         gesetzt hat. --}}
                                    <div class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white text-[var(--ui-secondary)]">
                                        {{ $displayValue ?: ($meta['empty'] ?? $meta['options'][0] ?? 'GO') }}
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

                {{-- Zuschlag (read-only) — gepflegt in der Schulungsnachbereitung, gelesen über den verknüpften Bewerber --}}
                @php $maZuschlag = $employee->applicant?->zuschlag; @endphp
                <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-700 mb-2 mt-4">Lohn</h3>
                <div class="bg-amber-50/30 border border-amber-200 rounded-lg p-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-[var(--ui-muted)]">Zuschlag</span>
                        <span class="font-medium text-[var(--ui-secondary)]">
                            {{ $maZuschlag !== null ? number_format((float) $maZuschlag, 2, ',', '.') . ' €/Std' : '—' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Signierte Vertraege (Download) --}}
            @php $contracts = $this->signedContracts; @endphp
            @if(!empty($contracts))
                <div class="mt-6 p-4 bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg">
                    <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">Unterschriebene Vertraege</h3>
                    <div class="space-y-2">
                        @foreach($contracts as $c)
                            <div class="flex items-center justify-between gap-3 p-2 bg-white border border-[var(--ui-border)]/60 rounded-md">
                                <div class="flex items-center gap-2 text-sm flex-wrap">
                                    @svg('heroicon-o-document-check', 'w-4 h-4 text-emerald-600')
                                    <span class="font-medium">{{ $c['display_name'] }}</span>
                                    <span class="text-xs text-[var(--ui-muted)]">am {{ \Carbon\Carbon::parse($c['signed_at'])->format('d.m.Y') }}</span>
                                    @if($c['superseded_by'])
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 border border-gray-200">
                                            ersetzt durch #{{ $c['superseded_by'] }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($c['can_reissue'])
                                        <button type="button" wire:click="openReissueModal({{ $c['id'] }})"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-[var(--ui-border)] text-[var(--ui-secondary)] bg-white text-xs font-medium rounded-md hover:bg-[var(--ui-muted-5)] transition-colors">
                                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                            Neu ausstellen
                                        </button>
                                    @endif
                                    <a href="{{ $c['pdf_url'] }}" target="_blank"
                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-emerald-300 text-emerald-800 bg-emerald-50 text-xs font-medium rounded-md hover:bg-emerald-100 transition-colors">
                                        @svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5')
                                        PDF
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Offene Vertraege (ausgestellt, noch nicht unterschrieben) --}}
            @php $openContracts = $this->openContracts; @endphp
            @if(!empty($openContracts))
                <div class="mt-4 p-4 bg-blue-50/40 border border-blue-200 rounded-lg">
                    <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">Offene Vertraege</h3>
                    <div class="space-y-2">
                        @foreach($openContracts as $oc)
                            <div class="flex items-center justify-between gap-3 p-2 bg-white border border-[var(--ui-border)]/60 rounded-md">
                                <div class="flex items-center gap-2 text-sm flex-wrap">
                                    @svg('heroicon-o-clock', 'w-4 h-4 text-blue-600')
                                    <span class="font-medium">{{ $oc['display_name'] }}</span>
                                    @if($oc['code'])
                                        <span class="text-xs text-[var(--ui-muted)]">({{ $oc['code'] }})</span>
                                    @endif
                                    <span class="text-xs text-[var(--ui-muted)]">
                                        {{ $oc['sent_at'] ? 'versendet am ' . \Carbon\Carbon::parse($oc['sent_at'])->format('d.m.Y') : 'noch nicht versendet' }}
                                    </span>
                                </div>
                                @if($oc['sign_url'])
                                    <div x-data="{ copied: false }" class="flex items-center gap-2">
                                        <input type="text" readonly value="{{ $oc['sign_url'] }}"
                                               class="w-64 text-xs px-2 py-1 border border-[var(--ui-border)] rounded bg-white" />
                                        <button type="button"
                                                @click="navigator.clipboard.writeText('{{ $oc['sign_url'] }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-blue-300 text-blue-800 bg-blue-50 text-xs font-medium rounded-md hover:bg-blue-100 transition-colors">
                                            <span x-show="!copied">Link kopieren</span>
                                            <span x-show="copied" x-cloak>Kopiert</span>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-[var(--ui-muted)] mt-2">
                        Der Mitarbeiter kann auch ueber das Portal unterschreiben — der Link hier ist fuer den Einzelversand.
                    </p>
                </div>
            @endif

            {{-- Vertrag neu ausstellen --}}
            <x-ui-modal size="sm" model="reissueModalShow">
                <x-slot name="header">Vertrag neu ausstellen</x-slot>
                <div class="p-4 space-y-4">
                    <p class="text-xs text-[var(--ui-muted)]">
                        Der unterschriebene Vertrag bleibt als Beleg erhalten und wird als ersetzt markiert.
                        Der neue Vertrag entsteht aus derselben Vorlage mit dem neuen Zuschlag — ohne
                        Infektionsschutz-Erklaerung, die ist ja schon unterschrieben.
                    </p>

                    <div>
                        <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Neuer Zuschlag (€/Std)</label>
                        <input type="text" wire:model="reissueZuschlag" placeholder="1,60"
                               class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm" />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Vertragsbeginn</label>
                        <input type="date" wire:model="reissueBeginn"
                               class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm" />
                        <p class="text-xs text-[var(--ui-muted)] mt-1">Leer = Beginn des ersetzten Vertrags uebernehmen.</p>
                    </div>

                    <div>
                        <span class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Grund</span>
                        <label class="flex items-start gap-2 p-2 border border-[var(--ui-border)] rounded-md cursor-pointer">
                            <input type="radio" wire:model="reissueReason" value="correction" class="mt-0.5" />
                            <span class="text-sm">
                                <span class="font-medium">Korrektur</span>
                                <span class="block text-xs text-[var(--ui-muted)]">
                                    Vertrag war falsch, Mitarbeiter noch nicht im Einsatz — keine Meldung ans Lohnbuero.
                                </span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2 p-2 border border-[var(--ui-border)] rounded-md cursor-pointer mt-2">
                            <input type="radio" wire:model="reissueReason" value="raise" class="mt-0.5" />
                            <span class="text-sm">
                                <span class="font-medium">Erhoehung</span>
                                <span class="block text-xs text-[var(--ui-muted)]">
                                    Aenderung im laufenden Verhaeltnis — erscheint in den Lohnaenderungen.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Notiz (optional)</label>
                        <textarea wire:model="reissueNote" rows="2"
                                  class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm"
                                  placeholder="z.B. Zuschlag bei Versand falsch angesetzt"></textarea>
                    </div>
                </div>
                <x-slot name="footer">
                    <div class="flex items-center justify-end gap-2">
                        <x-ui-button variant="secondary" wire:click="closeReissueModal">Abbrechen</x-ui-button>
                        <x-ui-button variant="primary" wire:click="reissueContract"
                                     wire:loading.attr="disabled" wire:target="reissueContract">
                            Neu ausstellen
                        </x-ui-button>
                    </div>
                </x-slot>
            </x-ui-modal>

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
