<div class="applicant-wrap min-h-screen relative overflow-hidden">

    {{-- Background --}}
    @php
        $bgFiles = glob(public_path('images/bg-images/*.{jpeg,jpg,png,webp}'), GLOB_BRACE);
        $bgImage = !empty($bgFiles) ? basename($bgFiles[array_rand($bgFiles)]) : null;
    @endphp
    <div class="fixed inset-0 -z-10" aria-hidden="true">
        <div class="applicant-bg"></div>
        @if($bgImage)
            <img src="{{ asset('images/bg-images/' . $bgImage) }}"
                 class="absolute inset-0 w-full h-full object-cover"
                 alt="" loading="eager">
        @endif
        <div class="absolute inset-0 bg-gradient-to-br from-black/50 via-black/30 to-black/50"></div>
        <div class="absolute inset-0 backdrop-blur-[6px]"></div>
    </div>

    {{-- Loading --}}
    @if($state === 'loading')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-16 h-16 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="animate-spin w-8 h-8 text-blue-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                </div>
                <p class="text-gray-500 text-lg">Wird geladen...</p>
            </div>
        </div>

    {{-- Not Found --}}
    @elseif($state === 'notFound')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-red-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Link ungueltig</h1>
                <p class="text-gray-500 text-lg">Dieser Link ist ungueltig oder existiert nicht mehr. Bitte kontaktieren Sie die Personalabteilung.</p>
            </div>
        </div>

    {{-- Not Active --}}
    @elseif($state === 'notActive')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-amber-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Bewerbung nicht aktiv</h1>
                <p class="text-gray-500 text-lg">Ihre Bewerbung ist derzeit nicht aktiv. Bitte kontaktieren Sie die Personalabteilung.</p>
            </div>
        </div>

    {{-- No Birth Date --}}
    @elseif($state === 'noBirthDate')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-amber-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Verifikation nicht moeglich</h1>
                <p class="text-gray-500 text-lg">Fuer Ihre Bewerbung ist kein Geburtsdatum hinterlegt. Bitte kontaktieren Sie die Personalabteilung, damit diese die Daten ergaenzen kann.</p>
            </div>
        </div>

    {{-- Verify --}}
    @elseif($state === 'verify')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10">
                <div class="text-center mb-8">
                    <div class="w-20 h-20 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">Hallo {{ $applicantName }}!</h1>
                    <p class="text-gray-500">Bitte verifizieren Sie sich mit Ihrem Geburtsdatum, um fortzufahren.</p>
                </div>

                <form wire:submit="verifyBirthDate" class="space-y-6">
                    <div>
                        <label for="birthDate" class="block text-sm font-semibold text-gray-700 mb-2">Geburtsdatum</label>
                        <input
                            type="date"
                            id="birthDate"
                            wire:model="birthDateInput"
                            class="applicant-input {{ $birthDateError ? 'border-red-300 focus:border-red-500 focus:ring-red-200' : '' }}"
                        >
                        @if($birthDateError)
                            <p class="mt-2 text-sm text-red-600">{{ $birthDateError }}</p>
                        @endif
                    </div>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="applicant-btn-primary w-full"
                    >
                        <span wire:loading.remove wire:target="verifyBirthDate">Verifizieren</span>
                        <span wire:loading wire:target="verifyBirthDate" class="inline-flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Wird geprueft...
                        </span>
                    </button>
                </form>
            </div>
        </div>

    {{-- Form --}}
    @elseif($state === 'form')
        {{-- Header --}}
        <header class="sticky top-0 z-50">
            <div class="applicant-header-glass">
                <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <h1 class="text-base font-semibold text-white truncate">Bewerbungsformular</h1>
                    </div>
                    <div class="flex items-center gap-4 flex-shrink-0 ml-4">
                        @if($totalFields > 0)
                            <span class="text-sm font-medium text-white/50">
                                {{ $filledFields }}<span class="text-white/30">/</span>{{ $totalFields }} ausgefuellt
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Progress Bar --}}
                @if($totalFields > 0)
                    <div class="h-0.5 bg-white/5">
                        <div
                            class="h-full transition-all duration-700 ease-out applicant-progress"
                            style="width: {{ $totalFields > 0 ? round(($filledFields / $totalFields) * 100) : 0 }}%"
                        ></div>
                    </div>
                @endif
            </div>
        </header>

        <main class="max-w-3xl mx-auto px-6 py-8">
            <form wire:submit="save">
                <div class="applicant-card p-8">
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-2">Offene Felder</h2>
                        <p class="text-gray-500">Bitte fuellen Sie die folgenden Felder aus.</p>
                    </div>

                    <div class="space-y-6">
                        @foreach($extraFieldDefinitions as $field)
                            @php
                                $fieldId = $field['id'];
                                $fieldType = $field['type'];
                                $fieldLabel = $field['label'];
                                $isRequired = $field['is_mandatory'] ?? false;
                                $options = $field['options'] ?? [];
                            @endphp

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    {{ $fieldLabel }}
                                    @if($isRequired)
                                        <span class="text-rose-500 ml-0.5">*</span>
                                    @endif
                                </label>

                                @switch($fieldType)
                                    @case('text')
                                        <input
                                            type="text"
                                            wire:model="extraFieldValues.{{ $fieldId }}"
                                            placeholder="{{ $options['placeholder'] ?? '' }}"
                                            class="applicant-input"
                                        >
                                        @break

                                    @case('textarea')
                                        <textarea
                                            wire:model="extraFieldValues.{{ $fieldId }}"
                                            rows="{{ $options['rows'] ?? 4 }}"
                                            placeholder="{{ $options['placeholder'] ?? '' }}"
                                            class="applicant-input resize-y"
                                        ></textarea>
                                        @break

                                    @case('number')
                                        <input
                                            type="number"
                                            wire:model="extraFieldValues.{{ $fieldId }}"
                                            placeholder="{{ $options['placeholder'] ?? '' }}"
                                            @if(isset($options['min'])) min="{{ $options['min'] }}" @endif
                                            @if(isset($options['max'])) max="{{ $options['max'] }}" @endif
                                            @if(isset($options['step'])) step="{{ $options['step'] }}" @endif
                                            class="applicant-input"
                                        >
                                        @break

                                    @case('date')
                                        <input
                                            type="date"
                                            wire:model="extraFieldValues.{{ $fieldId }}"
                                            class="applicant-input"
                                        >
                                        @break

                                    @case('boolean')
                                        <div class="grid grid-cols-2 gap-3">
                                            <button
                                                type="button"
                                                wire:click="$set('extraFieldValues.{{ $fieldId }}', '1')"
                                                class="applicant-bool-card {{ ($extraFieldValues[$fieldId] ?? null) === '1' ? 'applicant-option-active' : '' }}"
                                            >
                                                <svg class="w-8 h-8 {{ ($extraFieldValues[$fieldId] ?? null) === '1' ? 'text-emerald-500' : 'text-gray-300' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                <span class="text-sm font-semibold {{ ($extraFieldValues[$fieldId] ?? null) === '1' ? 'text-gray-900' : 'text-gray-400' }}">Ja</span>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="$set('extraFieldValues.{{ $fieldId }}', '0')"
                                                class="applicant-bool-card {{ ($extraFieldValues[$fieldId] ?? null) === '0' ? 'applicant-option-active' : '' }}"
                                            >
                                                <svg class="w-8 h-8 {{ ($extraFieldValues[$fieldId] ?? null) === '0' ? 'text-rose-500' : 'text-gray-300' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                <span class="text-sm font-semibold {{ ($extraFieldValues[$fieldId] ?? null) === '0' ? 'text-gray-900' : 'text-gray-400' }}">Nein</span>
                                            </button>
                                        </div>
                                        @break

                                    @case('select')
                                        @php
                                            $isMultiple = $options['multiple'] ?? false;
                                            $choices = $options['choices'] ?? [];
                                        @endphp
                                        @if($isMultiple)
                                            <div class="space-y-2">
                                                @foreach($choices as $choice)
                                                    @php
                                                        $currentVal = $extraFieldValues[$fieldId] ?? [];
                                                        $isSelected = is_array($currentVal) && in_array($choice, $currentVal);
                                                    @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="$js('
                                                            let v = $wire.extraFieldValues[{{ $fieldId }}] || [];
                                                            const idx = v.indexOf({{ json_encode($choice) }});
                                                            if (idx > -1) { v.splice(idx, 1); } else { v.push({{ json_encode($choice) }}); }
                                                            $wire.set(\"extraFieldValues.{{ $fieldId }}\", [...v]);
                                                        ')"
                                                        class="applicant-option-card {{ $isSelected ? 'applicant-option-active' : '' }}"
                                                    >
                                                        <span class="w-5 h-5 rounded flex items-center justify-center flex-shrink-0 border {{ $isSelected ? 'bg-blue-600 border-blue-600' : 'border-gray-300' }}">
                                                            @if($isSelected)
                                                                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                            @endif
                                                        </span>
                                                        <span class="text-sm font-medium text-gray-700">{{ $choice }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="space-y-2">
                                                @foreach($choices as $choice)
                                                    @php $isSelected = ($extraFieldValues[$fieldId] ?? null) === $choice; @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="$set('extraFieldValues.{{ $fieldId }}', '{{ $choice }}')"
                                                        class="applicant-option-card {{ $isSelected ? 'applicant-option-active' : '' }}"
                                                    >
                                                        <span class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 border {{ $isSelected ? 'border-blue-600' : 'border-gray-300' }}">
                                                            @if($isSelected)
                                                                <span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                                                            @endif
                                                        </span>
                                                        <span class="text-sm font-medium text-gray-700">{{ $choice }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                        @break
                                @endswitch

                                @error("extraFieldValues.{$fieldId}")
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                    </div>

                    {{-- Actions --}}
                    <div class="mt-8 flex justify-end">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="applicant-btn-primary"
                        >
                            <span wire:loading.remove wire:target="save">Speichern</span>
                            <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                Wird gespeichert...
                            </span>
                        </button>
                    </div>
                </div>
            </form>
        </main>

        <footer class="max-w-3xl mx-auto px-6 pb-8 text-center">
            <p class="text-[11px] text-white/20 tracking-wider uppercase">Powered by Recruiting</p>
        </footer>

    {{-- Saved --}}
    @elseif($state === 'saved')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-emerald-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Gespeichert!</h1>
                <p class="text-gray-500 text-lg mb-2">Ihre Angaben wurden erfolgreich gespeichert.</p>

                @if($totalFields > 0)
                    <div class="mt-6 mb-6">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-sm font-medium text-gray-600">Fortschritt</span>
                            <span class="text-sm font-semibold text-gray-900">{{ $filledFields }}/{{ $totalFields }}</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div class="bg-blue-600 h-2.5 rounded-full transition-all" style="width: {{ round(($filledFields / $totalFields) * 100) }}%"></div>
                        </div>
                    </div>
                @endif

                @if($filledFields < $totalFields)
                    <button
                        wire:click="continueEditing"
                        wire:loading.attr="disabled"
                        class="applicant-btn-primary"
                    >
                        <span wire:loading.remove wire:target="continueEditing">Weiter bearbeiten</span>
                        <span wire:loading wire:target="continueEditing" class="inline-flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        </span>
                    </button>
                @endif
            </div>
        </div>

    {{-- Completed --}}
    @elseif($state === 'completed')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-emerald-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Alles erledigt!</h1>
                <p class="text-gray-500 text-lg">Vielen Dank! Alle Felder sind ausgefuellt. Sie koennen diese Seite jetzt schliessen.</p>
            </div>
        </div>
    @endif
</div>

<style>
    /* ═══════════════════════════════════════════
       Applicant Form Styles — White Card Design
       ═══════════════════════════════════════════ */

    .applicant-wrap {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    }

    /* ── Background ── */
    .applicant-bg {
        position: fixed;
        inset: 0;
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        z-index: -10;
    }

    /* ── White Content Card ── */
    .applicant-card {
        background: white;
        border-radius: 24px;
        border: 1px solid rgba(0, 0, 0, 0.06);
        box-shadow:
            0 4px 6px -1px rgba(0, 0, 0, 0.05),
            0 25px 50px -12px rgba(0, 0, 0, 0.15);
    }

    /* ── Glass Header ── */
    .applicant-header-glass {
        background: rgba(15, 10, 26, 0.6);
        backdrop-filter: blur(30px);
        -webkit-backdrop-filter: blur(30px);
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    /* ── Progress Bar ── */
    .applicant-progress {
        background: linear-gradient(90deg, #3b82f6, #6366f1, #8b5cf6);
        box-shadow: 0 0 12px rgba(99, 102, 241, 0.5);
    }

    /* ── Form Inputs ── */
    .applicant-input {
        width: 100%;
        padding: 14px 18px;
        background: white;
        border: 1px solid #d1d5db;
        border-radius: 14px;
        color: #111827;
        font-size: 15px;
        outline: none;
        transition: all 0.2s ease;
    }

    .applicant-input::placeholder {
        color: #9ca3af;
    }

    .applicant-input:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        background: white;
    }

    /* ── Option Cards ── */
    .applicant-option-card {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 18px;
        border-radius: 14px;
        border: 1px solid #e5e7eb;
        background: white;
        text-align: left;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .applicant-option-card:hover {
        background: #f9fafb;
        border-color: #d1d5db;
    }

    .applicant-option-active {
        background: rgba(99, 102, 241, 0.05) !important;
        border-color: rgba(99, 102, 241, 0.4) !important;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.08);
    }

    /* ── Boolean Cards ── */
    .applicant-bool-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 32px 24px;
        border-radius: 18px;
        border: 1px solid #e5e7eb;
        background: white;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .applicant-bool-card:hover {
        background: #f9fafb;
        border-color: #d1d5db;
    }

    /* ── Buttons ── */
    .applicant-btn-primary {
        padding: 12px 28px;
        background: #6366f1;
        color: white;
        font-size: 14px;
        font-weight: 600;
        border-radius: 14px;
        transition: all 0.2s ease;
    }

    .applicant-btn-primary:hover {
        background: #4f46e5;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);
    }

    .applicant-btn-primary:disabled {
        opacity: 0.5;
    }

    /* Date input icon */
    .applicant-input[type="date"]::-webkit-calendar-picker-indicator {
        filter: none;
    }
</style>
