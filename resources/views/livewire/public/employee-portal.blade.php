<div class="min-h-screen bg-[var(--ui-surface)] py-8 px-4">
    <div class="max-w-2xl mx-auto">

        {{-- HEADER --}}
        <div class="text-center mb-8">
            <h1 class="text-2xl font-semibold text-[var(--ui-secondary)]">Mitarbeiter-Portal</h1>
            @if($state === 'verified' && $displayName)
                <p class="text-sm text-[var(--ui-muted)] mt-1">Willkommen, {{ $displayName }}</p>
            @endif
        </div>

        {{-- LOADING --}}
        @if($state === 'loading')
            <div class="text-center py-12">
                <p class="text-sm text-[var(--ui-muted)]">Lade Portal...</p>
            </div>

        {{-- TOKEN INVALID --}}
        @elseif($state === 'tokenInvalid')
            <div class="p-6 bg-red-50 border border-red-200 rounded-lg text-center">
                @svg('heroicon-o-exclamation-triangle', 'w-10 h-10 text-red-600 mx-auto mb-3')
                <h2 class="text-lg font-medium text-red-900 mb-1">Link ungueltig</h2>
                <p class="text-sm text-red-700">Dieser Link ist nicht gueltig oder das Konto ist nicht aktiv. Bitte wenden Sie sich an Ihre Ansprechperson.</p>
            </div>

        {{-- RATE LIMITED --}}
        @elseif($state === 'rateLimited')
            <div class="p-6 bg-amber-50 border border-amber-200 rounded-lg text-center">
                @svg('heroicon-o-shield-exclamation', 'w-10 h-10 text-amber-600 mx-auto mb-3')
                <h2 class="text-lg font-medium text-amber-900 mb-1">Zu viele Versuche</h2>
                <p class="text-sm text-amber-700">Aus Sicherheitsgruenden wurde der Zugang vorruebergehend gesperrt. Bitte versuchen Sie es in 15 Minuten erneut.</p>
            </div>

        {{-- UNVERIFIED — LOGIN FORM --}}
        @elseif($state === 'unverified')
            <div class="bg-white border border-[var(--ui-border)] rounded-lg shadow-sm p-6">
                <div class="mb-4">
                    <h2 class="text-lg font-medium text-[var(--ui-secondary)]">Anmeldung</h2>
                    <p class="text-sm text-[var(--ui-muted)] mt-1">Bitte verifizieren Sie sich mit Ihrem Geburtsdatum und den letzten 4 Ziffern Ihrer Ausweisnummer.</p>
                </div>

                <form wire:submit.prevent="verify" class="space-y-4">
                    <div>
                        <label for="birthDate" class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Geburtsdatum</label>
                        <input
                            type="date"
                            id="birthDate"
                            wire:model.defer="birthDateInput"
                            class="w-full border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]"
                            required
                        />
                    </div>

                    <div>
                        <label for="idCardLast4" class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">Letzte 4 Ziffern Ihrer Ausweisnummer</label>
                        <input
                            type="text"
                            id="idCardLast4"
                            maxlength="4"
                            inputmode="numeric"
                            wire:model.defer="idCardLast4Input"
                            class="w-full border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]"
                            placeholder="z.B. 4567"
                            required
                        />
                    </div>

                    @if($loginError)
                        <div class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
                            {{ $loginError }}
                        </div>
                    @endif

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-[var(--ui-primary)] text-white text-sm font-medium rounded-md hover:bg-[var(--ui-primary)]/90 transition-colors disabled:opacity-50"
                    >
                        @svg('heroicon-o-lock-open', 'w-4 h-4')
                        Anmelden
                    </button>
                </form>
            </div>

        {{-- VERIFIED — DASHBOARD --}}
        @elseif($state === 'verified')
            @php
                $contractsList = $this->contracts;
                $missing = $this->missingFields;
            @endphp

            {{-- VERTRAEGE SECTION --}}
            <div class="mb-6">
                <h2 class="text-base font-semibold text-[var(--ui-secondary)] mb-3">Ihre Vertraege</h2>
                @if(empty($contractsList))
                    <div class="p-6 bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg text-center">
                        <p class="text-sm text-[var(--ui-muted)]">Keine Vertraege hinterlegt.</p>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($contractsList as $c)
                            <div class="p-4 bg-white border border-[var(--ui-border)] rounded-lg shadow-sm">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-medium text-[var(--ui-secondary)]">
                                            {{ $c['display_name'] }}
                                        </h3>
                                        <div class="mt-2 text-xs">
                                            @if($c['status'] === 'completed' || $c['signed_at'])
                                                <span class="inline-flex items-center gap-1 text-green-700">
                                                    @svg('heroicon-o-check-circle', 'w-4 h-4')
                                                    Unterschrieben
                                                    @if($c['signed_at'])
                                                        am {{ \Carbon\Carbon::parse($c['signed_at'])->format('d.m.Y') }}
                                                    @endif
                                                </span>
                                            @elseif($c['status'] === 'sent')
                                                <span class="inline-flex items-center gap-1 text-blue-700">
                                                    @svg('heroicon-o-pencil', 'w-4 h-4')
                                                    Wartet auf Ihre Unterschrift
                                                </span>
                                            @elseif($c['status'] === 'in_progress')
                                                <span class="inline-flex items-center gap-1 text-amber-700">
                                                    @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                                    Begonnen, aber noch nicht abgeschlossen
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 text-[var(--ui-muted)]">
                                                    @svg('heroicon-o-clock', 'w-4 h-4')
                                                    {{ $c['status'] }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0">
                                        @if(!$c['signed_at'] && in_array($c['status'], ['sent', 'in_progress']))
                                            <a href="{{ $c['sign_url'] }}"
                                               class="inline-flex items-center gap-2 px-4 py-2 bg-[var(--ui-primary)] text-white text-sm font-medium rounded-md hover:bg-[var(--ui-primary)]/90 transition-colors">
                                                @svg('heroicon-o-pencil', 'w-4 h-4')
                                                Jetzt unterschreiben
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- "FEHLT NOCH"-HIGHLIGHT-BANNER --}}
            @if(!empty($missing))
                <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 flex items-start gap-2">
                    @svg('heroicon-o-exclamation-triangle', 'w-5 h-5 mt-0.5 flex-shrink-0')
                    <div>
                        <strong>Diese Daten fehlen noch:</strong>
                        {{ implode(', ', array_values($missing)) }}.
                    </div>
                </div>
            @endif

            @if($editFlash)
                <div class="mb-3 p-2 bg-green-50 border border-green-200 rounded text-xs text-green-800 flex items-center gap-2">
                    @svg('heroicon-o-check-circle', 'w-4 h-4')
                    {{ $editFlash }}
                </div>
            @endif

            {{-- READ-ONLY DISPLAY (z.B. Geworben-von) --}}
            @if(!empty($this->readOnlyDisplay))
                <div class="mb-6">
                    <h2 class="text-base font-semibold text-[var(--ui-secondary)] mb-3">Eintraege bei Bewerbung</h2>
                    <div class="bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg divide-y divide-[var(--ui-border)]">
                        @foreach($this->readOnlyDisplay as $field => $entry)
                            <div class="p-3 flex items-center justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-xs text-[var(--ui-muted)]">{{ $entry['label'] }}</div>
                                    <div class="text-sm text-[var(--ui-secondary)] truncate">{{ $entry['value'] }}</div>
                                </div>
                                <span class="flex-shrink-0 px-2 py-0.5 text-[10px] uppercase tracking-wide text-[var(--ui-muted)] bg-white border border-[var(--ui-border)] rounded">
                                    nicht aenderbar
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- STAMMDATEN — Direct-Edit fuer alle editierbaren Felder --}}
            <div class="mb-6">
                <h2 class="text-base font-semibold text-[var(--ui-secondary)] mb-3">Meine Daten</h2>
                <p class="text-xs text-[var(--ui-muted)] mb-4">Diese Daten kannst du jederzeit ändern. Anpassungen werden mit dem Button "Speichern" unten übernommen. Dateien werden direkt beim Hochladen gespeichert.</p>

                @php
                    $fileUploadProps = [
                        'identity_card_front_file_id'   => 'uploadIdentityFront',
                        'identity_card_back_file_id'    => 'uploadIdentityBack',
                        'selfie_file_id'                => 'uploadSelfie',
                        'health_insurance_card_file_id' => 'uploadHealthInsuranceCard',
                    ];
                @endphp

                @foreach($this->editableGroups as $section => $entries)
                    <div class="mb-5">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)] mb-2">{{ $section }}</h3>
                        <div class="bg-white border border-[var(--ui-border)] rounded-lg divide-y divide-[var(--ui-border)]">
                            @foreach($entries as $entry)
                                @php
                                    $key = $entry['key'];
                                    $type = $entry['type'];
                                    $label = $entry['label'];
                                    $isMissing = $entry['is_missing'];
                                @endphp
                                @php
                                    // Roter Border bei leerem Feld als dezenter "fehlt noch"-Indikator
                                    $inputBorder = $isMissing ? 'border-red-300' : 'border-[var(--ui-border)]';
                                @endphp
                                <div class="p-3">
                                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1.5">
                                        {{ $label }}
                                    </label>

                                    @if($type === 'lookup')
                                        <select
                                            wire:model.defer="fieldValues.{{ $key }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white"
                                        >
                                            <option value="">— bitte wählen —</option>
                                            @foreach($this->lookupOptionsFor($entry['lookup']) as $optValue => $optLabel)
                                                <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                            @endforeach
                                        </select>

                                    @elseif($type === 'bool')
                                        <select
                                            wire:model.defer="fieldValues.{{ $key }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm bg-white"
                                        >
                                            <option value="">— bitte wählen —</option>
                                            <option value="1">Ja</option>
                                            <option value="0">Nein</option>
                                        </select>

                                    @elseif($type === 'date')
                                        <input
                                            type="date"
                                            wire:model.defer="fieldValues.{{ $key }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm"
                                        />

                                    @elseif($type === 'file')
                                        @php $uploadProp = $fileUploadProps[$key] ?? null; @endphp
                                        <div class="flex items-center gap-3 p-2 rounded-md border {{ $isMissing ? 'border-red-300' : 'border-[var(--ui-border)]' }}">
                                            <div class="flex-1 text-sm">
                                                @if($isMissing)
                                                    <span class="text-[var(--ui-muted)] text-xs">Keine Datei hochgeladen</span>
                                                @else
                                                    <span class="inline-flex items-center gap-1.5 text-[var(--ui-secondary)]">
                                                        @svg('heroicon-o-document-check', 'w-4 h-4 text-emerald-600')
                                                        {{ $entry['display'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            @if($uploadProp)
                                                <label class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[var(--ui-secondary)] text-white text-xs font-medium rounded-md hover:opacity-90 transition cursor-pointer">
                                                    @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                                                    @if($isMissing) Hochladen @else Ersetzen @endif
                                                    <input
                                                        type="file"
                                                        wire:model="{{ $uploadProp }}"
                                                        accept="image/*,.pdf"
                                                        class="hidden"
                                                    />
                                                </label>
                                                <div wire:loading wire:target="{{ $uploadProp }}" class="text-xs text-[var(--ui-muted)]">
                                                    Lade hoch...
                                                </div>
                                            @endif
                                        </div>

                                    @else
                                        <input
                                            type="text"
                                            wire:model.defer="fieldValues.{{ $key }}"
                                            placeholder="{{ $label }}"
                                            class="w-full border {{ $inputBorder }} rounded-md px-3 py-1.5 text-sm"
                                        />
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- GLOBALER SPEICHER-BUTTON --}}
            <div class="sticky bottom-0 bg-[var(--ui-surface)] pt-4 pb-2 mt-6 flex items-center justify-between gap-3 border-t border-[var(--ui-border)]">
                <button
                    wire:click="logout"
                    class="text-xs text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] underline"
                >
                    Abmelden
                </button>
                <button
                    wire:click="saveAll"
                    wire:loading.attr="disabled"
                    wire:target="saveAll"
                    class="inline-flex items-center gap-2 px-5 py-2 bg-[var(--ui-primary)] text-white text-sm font-semibold rounded-md hover:bg-[var(--ui-primary)]/90 transition-colors disabled:opacity-50"
                >
                    @svg('heroicon-o-check', 'w-4 h-4')
                    <span wire:loading.remove wire:target="saveAll">Änderungen speichern</span>
                    <span wire:loading wire:target="saveAll">Speichere...</span>
                </button>
            </div>
        @endif
    </div>
</div>
