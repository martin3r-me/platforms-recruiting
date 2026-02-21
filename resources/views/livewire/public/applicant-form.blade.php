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
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Link ungültig</h1>
                <p class="text-gray-500 text-lg">Dieser Link ist ungültig oder existiert nicht mehr. Bitte kontaktieren Sie die Personalabteilung.</p>
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
                                {{ $filledFields }}<span class="text-white/30">/</span>{{ $totalFields }} ausgefüllt
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

        @php
            // Pre-resolve lookup values for visibility conditions (client-side evaluation)
            $lookupCache = [];

            // Resolve lookups for ALL field definitions (needed for condition evaluation on filled fields)
            $allDefsForJs = $allFieldDefinitions;
            foreach ($allDefsForJs as &$def) {
                $vc = $def['visibility_config'] ?? null;
                if (!$vc || !($vc['enabled'] ?? false)) continue;
                foreach ($vc['groups'] ?? [] as $gi => $group) {
                    foreach ($group['conditions'] ?? [] as $ci => $condition) {
                        if (in_array($condition['operator'] ?? '', ['is_in', 'is_not_in'])) {
                            $source = $condition['list_source'] ?? 'manual';
                            if ($source === 'lookup') {
                                $lookupId = $condition['list_lookup_id'] ?? null;
                                if ($lookupId) {
                                    if (!isset($lookupCache[$lookupId])) {
                                        $lookup = \Platform\Core\Models\CoreLookup::with('activeValues')->find($lookupId);
                                        $lookupCache[$lookupId] = $lookup ? $lookup->activeValues->pluck('value')->all() : [];
                                    }
                                    $def['visibility_config']['groups'][$gi]['conditions'][$ci]['_resolved_list'] = $lookupCache[$lookupId];
                                }
                            }
                        }
                    }
                }
            }
            unset($def);

            // Build the filtered form definitions from allDefsForJs (same IDs as extraFieldDefinitions)
            $formFieldIds = collect($extraFieldDefinitions)->pluck('id')->all();
            $defsForJs = array_values(array_filter($allDefsForJs, fn($d) => in_array($d['id'], $formFieldIds)));
        @endphp

        <main class="max-w-3xl mx-auto px-6 py-8">
            <form wire:submit="save"
                x-data="{
                    fieldValues: @entangle('extraFieldValues').live,
                    allFieldValues: @entangle('allFieldValues').live,
                    fieldDefinitions: @js($defsForJs),
                    allFieldDefinitions: @js($allDefsForJs),

                    init() {
                        this.$watch('fieldValues', (values) => {
                            for (const [key, val] of Object.entries(values)) {
                                this.allFieldValues[key] = val;
                            }
                        });
                    },

                    isFieldVisible(fieldId) {
                        const field = this.allFieldDefinitions.find(f => f.id === fieldId);
                        if (!field) return true;
                        const visibility = field.visibility_config;
                        if (!visibility || !visibility.enabled) return true;
                        return this.evaluateVisibility(visibility);
                    },

                    evaluateVisibility(config) {
                        if (!config.groups || config.groups.length === 0) return true;
                        const mainLogic = (config.logic || 'AND').toUpperCase();
                        const groupResults = config.groups.map(group => this.evaluateGroup(group));
                        if (mainLogic === 'OR') return groupResults.includes(true);
                        return !groupResults.includes(false);
                    },

                    evaluateGroup(group) {
                        if (!group.conditions || group.conditions.length === 0) return true;
                        const groupLogic = (group.logic || 'AND').toUpperCase();
                        const conditionResults = group.conditions.map(cond => this.evaluateCondition(cond));
                        if (groupLogic === 'OR') return conditionResults.includes(true);
                        return !conditionResults.includes(false);
                    },

                    evaluateCondition(condition) {
                        if (!condition.field) return true;
                        const targetField = this.allFieldDefinitions.find(f => f.name === condition.field);
                        if (!targetField) return true;
                        const actualValue = this.allFieldValues[targetField.id] !== undefined
                            ? this.allFieldValues[targetField.id]
                            : this.fieldValues[targetField.id];
                        const operator = condition.operator || 'equals';
                        if (operator === 'is_in' || operator === 'is_not_in') {
                            let comparisonList;
                            const source = condition.list_source || 'manual';
                            if (source === 'lookup' && condition._resolved_list) {
                                comparisonList = condition._resolved_list;
                            } else {
                                comparisonList = Array.isArray(condition.value) ? condition.value : (condition.value ? [condition.value] : []);
                            }
                            return this.compareValues(actualValue, operator, comparisonList);
                        }
                        return this.compareValues(actualValue, operator, condition.value);
                    },

                    compareValues(actual, operator, expected) {
                        switch (operator) {
                            case 'equals': return this.isEqual(actual, expected);
                            case 'not_equals': return !this.isEqual(actual, expected);
                            case 'greater_than': return parseFloat(actual) > parseFloat(expected);
                            case 'greater_or_equal': return parseFloat(actual) >= parseFloat(expected);
                            case 'less_than': return parseFloat(actual) < parseFloat(expected);
                            case 'less_or_equal': return parseFloat(actual) <= parseFloat(expected);
                            case 'is_null': return this.isEmpty(actual);
                            case 'is_not_null': return !this.isEmpty(actual);
                            case 'in': case 'is_in': return this.isIn(actual, expected);
                            case 'not_in': case 'is_not_in': return !this.isIn(actual, expected);
                            case 'contains': return String(actual || '').toLowerCase().includes(String(expected || '').toLowerCase());
                            case 'starts_with': return String(actual || '').toLowerCase().startsWith(String(expected || '').toLowerCase());
                            case 'ends_with': return String(actual || '').toLowerCase().endsWith(String(expected || '').toLowerCase());
                            case 'is_true': return actual === true || actual === 1 || actual === '1' || String(actual).toLowerCase() === 'true';
                            case 'is_false': return actual === false || actual === 0 || actual === '0' || String(actual).toLowerCase() === 'false' || this.isEmpty(actual);
                            default: return true;
                        }
                    },

                    isEqual(a, b) {
                        if (a === b) return true;
                        if (a === null && b === null) return true;
                        if (typeof a === 'number' && typeof b === 'number') return a === b;
                        if (!isNaN(a) && !isNaN(b)) return parseFloat(a) === parseFloat(b);
                        return String(a || '').toLowerCase() === String(b || '').toLowerCase();
                    },

                    isEmpty(value) {
                        if (value === null || value === undefined) return true;
                        if (typeof value === 'string' && value.trim() === '') return true;
                        if (Array.isArray(value) && value.length === 0) return true;
                        return false;
                    },

                    isIn(actual, expected) {
                        if (!Array.isArray(expected)) expected = [expected];
                        if (Array.isArray(actual)) return actual.some(item => expected.includes(item));
                        return expected.includes(actual);
                    }
                }"
            >
                <div class="applicant-card p-8">
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-2">Offene Felder</h2>
                        <p class="text-gray-500">Bitte füllen Sie die folgenden Felder aus.</p>
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

                            <div
                                x-show="isFieldVisible({{ $fieldId }})"
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 -translate-y-2"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 -translate-y-2"
                            >
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
                                            <select wire:model="extraFieldValues.{{ $fieldId }}" class="applicant-input">
                                                <option value="">— Bitte wählen —</option>
                                                @foreach($choices as $choice)
                                                    <option value="{{ $choice }}">{{ $choice }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        @break

                                    @case('lookup')
                                        @php
                                            $isMultiple = $options['multiple'] ?? false;
                                            $lookupChoices = $field['lookup']['choices'] ?? [];
                                        @endphp
                                        @if($isMultiple)
                                            <div class="space-y-2">
                                                @foreach($lookupChoices as $choice)
                                                    @php
                                                        $currentVal = $extraFieldValues[$fieldId] ?? [];
                                                        $isSelected = is_array($currentVal) && in_array($choice['value'], $currentVal);
                                                    @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="$js('
                                                            let v = $wire.extraFieldValues[{{ $fieldId }}] || [];
                                                            const idx = v.indexOf({{ json_encode($choice['value']) }});
                                                            if (idx > -1) { v.splice(idx, 1); } else { v.push({{ json_encode($choice['value']) }}); }
                                                            $wire.set(\"extraFieldValues.{{ $fieldId }}\", [...v]);
                                                        ')"
                                                        class="applicant-option-card {{ $isSelected ? 'applicant-option-active' : '' }}"
                                                    >
                                                        <span class="w-5 h-5 rounded flex items-center justify-center flex-shrink-0 border {{ $isSelected ? 'bg-blue-600 border-blue-600' : 'border-gray-300' }}">
                                                            @if($isSelected)
                                                                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                            @endif
                                                        </span>
                                                        <span class="text-sm font-medium text-gray-700">{{ $choice['label'] }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @else
                                            <select wire:model="extraFieldValues.{{ $fieldId }}" class="applicant-input">
                                                <option value="">— Bitte wählen —</option>
                                                @foreach($lookupChoices as $choice)
                                                    <option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        @break

                                    @case('file')
                                        @php
                                            $isMultiple = $options['multiple'] ?? false;
                                            $currentFileIds = $extraFieldValues[$fieldId] ?? ($isMultiple ? [] : null);
                                            $currentFileIds = is_array($currentFileIds) ? $currentFileIds : ($currentFileIds ? [$currentFileIds] : []);
                                        @endphp
                                        <div>
                                            {{-- Uploaded files preview --}}
                                            @if(!empty($currentFileIds))
                                                <div class="space-y-2 mb-3">
                                                    @foreach($currentFileIds as $fileId_item)
                                                        @php $fileData = $uploadedFileData[$fileId_item] ?? null; @endphp
                                                        @if($fileData)
                                                            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border border-gray-200">
                                                                @if($fileData['is_image'] && $fileData['thumbnail_url'])
                                                                    <img src="{{ $fileData['thumbnail_url'] }}" alt="" class="w-10 h-10 rounded-lg object-cover flex-shrink-0">
                                                                @else
                                                                    <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                                                                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                                                        </svg>
                                                                    </div>
                                                                @endif
                                                                <div class="flex-1 min-w-0">
                                                                    <p class="text-sm font-medium text-gray-700 truncate">{{ $fileData['original_name'] }}</p>
                                                                    <p class="text-xs text-gray-400">{{ $this->formatFileSize($fileData['file_size'] ?? 0) }}</p>
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    wire:click="removeFile({{ $fieldId }}, {{ $fileId_item }})"
                                                                    class="p-1.5 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors flex-shrink-0"
                                                                >
                                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                                    </svg>
                                                                </button>
                                                            </div>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif

                                            {{-- Upload zone --}}
                                            @if($isMultiple || empty($currentFileIds))
                                                <div
                                                    x-data="{ dragging: false }"
                                                    x-on:dragover.prevent="dragging = true"
                                                    x-on:dragleave.prevent="dragging = false"
                                                    x-on:drop.prevent="dragging = false; $refs.fileInput{{ $fieldId }}.files = $event.dataTransfer.files; $refs.fileInput{{ $fieldId }}.dispatchEvent(new Event('change'))"
                                                    class="relative"
                                                >
                                                    <label
                                                        :class="dragging ? 'border-blue-400 bg-blue-50/50' : 'border-gray-300 hover:border-gray-400 hover:bg-gray-50'"
                                                        class="flex flex-col items-center justify-center p-6 border-2 border-dashed rounded-xl cursor-pointer transition-all"
                                                    >
                                                        <div wire:loading.remove wire:target="pendingFileUploads.{{ $fieldId }}">
                                                            <svg class="w-8 h-8 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                                            </svg>
                                                            <p class="text-sm text-gray-500 text-center">
                                                                <span class="font-medium text-blue-600">Datei auswählen</span> oder hierher ziehen
                                                            </p>
                                                        </div>
                                                        <div wire:loading wire:target="pendingFileUploads.{{ $fieldId }}" class="flex flex-col items-center">
                                                            <svg class="animate-spin w-6 h-6 text-blue-500 mb-2" fill="none" viewBox="0 0 24 24">
                                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                                            </svg>
                                                            <p class="text-sm text-gray-500">Wird hochgeladen...</p>
                                                        </div>
                                                        <input
                                                            type="file"
                                                            x-ref="fileInput{{ $fieldId }}"
                                                            wire:model="pendingFileUploads.{{ $fieldId }}"
                                                            class="hidden"
                                                            @if($isMultiple) multiple @endif
                                                        >
                                                    </label>
                                                </div>
                                            @endif
                                        </div>
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
                <p class="text-gray-500 text-lg">Vielen Dank! Alle Felder sind ausgefüllt. Sie können diese Seite jetzt schließen.</p>
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
