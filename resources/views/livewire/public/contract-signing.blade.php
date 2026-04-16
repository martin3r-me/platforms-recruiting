<div class="min-h-screen bg-gray-50 py-8 px-4 sm:px-6 lg:px-8">
    <div class="max-w-3xl mx-auto">

        {{-- Ungültiger Token --}}
        @if($state === 'invalid')
            <div class="bg-white rounded-lg border border-red-200 p-12 text-center">
                @svg('heroicon-o-exclamation-triangle', 'w-16 h-16 text-red-400 mx-auto mb-4')
                <h2 class="text-xl font-bold text-gray-900 mb-2">Ungültiger Link</h2>
                <p class="text-gray-500">Dieser Link ist ungültig oder der Vertrag ist nicht verfügbar.</p>
            </div>
        @elseif($state === 'expired')
            <div class="bg-white rounded-lg border border-yellow-200 p-12 text-center">
                @svg('heroicon-o-clock', 'w-16 h-16 text-yellow-400 mx-auto mb-4')
                <h2 class="text-xl font-bold text-gray-900 mb-2">Link abgelaufen</h2>
                <p class="text-gray-500">Dieser Link ist abgelaufen. Bitte kontaktieren Sie Ihren Arbeitgeber.</p>
            </div>
        @elseif($state === 'already_signed')
            <div class="bg-white rounded-lg border border-green-200 p-12 text-center">
                @svg('heroicon-o-check-circle', 'w-16 h-16 text-green-500 mx-auto mb-4')
                <h2 class="text-xl font-bold text-gray-900 mb-2">Vertrag unterschrieben</h2>
                <p class="text-gray-500">Dieser Vertrag wurde bereits erfolgreich unterschrieben. Vielen Dank!</p>
            </div>
        @elseif($state === 'loading')
            <div class="bg-white rounded-lg border p-12 text-center">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
                <p class="text-gray-500">Vertrag wird geladen...</p>
            </div>
        @elseif($state === 'form')
            {{-- Fortschrittsanzeige --}}
            <div class="mb-8">
                <div class="flex items-center justify-between mb-2">
                    @foreach([1 => '§15 Angaben', 2 => '§16 Angaben', 3 => 'Vertrag & Unterschrift'] as $num => $label)
                        <div class="flex items-center {{ $num < 3 ? 'flex-1' : '' }}">
                            <div class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold
                                {{ $step >= $num ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                                @if($step > $num)
                                    @svg('heroicon-o-check', 'w-5 h-5')
                                @else
                                    {{ $num }}
                                @endif
                            </div>
                            <span class="ml-2 text-sm font-medium {{ $step >= $num ? 'text-gray-900' : 'text-gray-400' }} hidden sm:inline">
                                {{ $label }}
                            </span>
                            @if($num < 3)
                                <div class="flex-1 mx-4 h-0.5 {{ $step > $num ? 'bg-blue-600' : 'bg-gray-200' }}"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Step 1: §15 - Kurzfristige Beschäftigungen --}}
            @if($step === 1)
                <div class="bg-white rounded-lg border border-gray-200 p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">Angaben nach §15 — Kurzfristige Beschäftigungen</h2>
                    <p class="text-gray-500 text-sm mb-6">
                        Waren Sie in den letzten 12 Monaten kurzfristig beschäftigt?
                    </p>

                    <div class="space-y-4">
                        <div class="flex items-center gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model.live="par15HasPrevious" value="1" class="text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-700">Ja</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model.live="par15HasPrevious" value="" class="text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-700">Nein</span>
                            </label>
                        </div>

                        @if($par15HasPrevious)
                            <div class="mt-4 space-y-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-sm font-semibold text-gray-700">Bisherige kurzfristige Beschäftigungen</h3>
                                    <button type="button" wire:click="addPar15Entry"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-sm text-blue-600 hover:bg-blue-50 rounded-md transition">
                                        @svg('heroicon-o-plus', 'w-4 h-4') Eintrag hinzufügen
                                    </button>
                                </div>

                                @foreach($par15Entries as $index => $entry)
                                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                        <div class="flex items-start justify-between mb-3">
                                            <span class="text-xs font-medium text-gray-500">Beschäftigung {{ $index + 1 }}</span>
                                            <button type="button" wire:click="removePar15Entry({{ $index }})"
                                                class="text-red-400 hover:text-red-600 transition">
                                                @svg('heroicon-o-x-mark', 'w-4 h-4')
                                            </button>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Beginn</label>
                                                <input type="date" wire:model="par15Entries.{{ $index }}.beginn"
                                                    class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error("par15Entries.{$index}.beginn") <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Ende</label>
                                                <input type="date" wire:model="par15Entries.{{ $index }}.ende"
                                                    class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error("par15Entries.{$index}.ende") <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Arbeitgeber</label>
                                                <input type="text" wire:model="par15Entries.{{ $index }}.arbeitgeber"
                                                    placeholder="Firma, Ort"
                                                    class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error("par15Entries.{$index}.arbeitgeber") <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Anzahl Arbeitstage</label>
                                                <input type="number" wire:model="par15Entries.{{ $index }}.tage" min="1"
                                                    placeholder="z.B. 30"
                                                    class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error("par15Entries.{$index}.tage") <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                        </div>
                                    </div>
                                @endforeach

                                @if(count($par15Entries) === 0)
                                    <p class="text-sm text-gray-400 text-center py-4">
                                        Noch keine Einträge. Klicken Sie auf "Eintrag hinzufügen".
                                    </p>
                                @endif
                                @error('par15Entries') <span class="text-sm text-red-500">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-end mt-8">
                        <button type="button" wire:click="nextStep"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
                            Weiter @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        </button>
                    </div>
                </div>
            @endif

            {{-- Step 2: §16 - Beschäftigungslose Zeiten --}}
            @if($step === 2)
                <div class="bg-white rounded-lg border border-gray-200 p-8">
                    <h2 class="text-xl font-bold text-gray-900 mb-2">Angaben nach §16 — Beschäftigungslose Zeiten</h2>
                    <p class="text-gray-500 text-sm mb-6">
                        Waren Sie in den letzten 12 Monaten bei der Arbeitsagentur als arbeitssuchend gemeldet?
                    </p>

                    <div class="space-y-4">
                        <div class="flex items-center gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model.live="par16WasJobseeking" value="1" class="text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-700">Ja</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model.live="par16WasJobseeking" value="" class="text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-700">Nein</span>
                            </label>
                        </div>

                        @if($par16WasJobseeking)
                            <div class="mt-4 space-y-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-sm font-semibold text-gray-700">Beschäftigungslose Zeiten</h3>
                                    <button type="button" wire:click="addPar16Entry"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-sm text-blue-600 hover:bg-blue-50 rounded-md transition">
                                        @svg('heroicon-o-plus', 'w-4 h-4') Eintrag hinzufügen
                                    </button>
                                </div>

                                @foreach($par16Entries as $index => $entry)
                                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                        <div class="flex items-start justify-between mb-3">
                                            <span class="text-xs font-medium text-gray-500">Zeitraum {{ $index + 1 }}</span>
                                            <button type="button" wire:click="removePar16Entry({{ $index }})"
                                                class="text-red-400 hover:text-red-600 transition">
                                                @svg('heroicon-o-x-mark', 'w-4 h-4')
                                            </button>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Beginn</label>
                                                <input type="date" wire:model="par16Entries.{{ $index }}.beginn"
                                                    class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error("par16Entries.{$index}.beginn") <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Ende</label>
                                                <input type="date" wire:model="par16Entries.{{ $index }}.ende"
                                                    class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error("par16Entries.{$index}.ende") <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600 mb-1">Arbeitsagentur</label>
                                                <input type="text" wire:model="par16Entries.{{ $index }}.arbeitsagentur"
                                                    placeholder="Ort/Name der Agentur"
                                                    class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                @error("par16Entries.{$index}.arbeitsagentur") <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                        </div>
                                    </div>
                                @endforeach

                                @if(count($par16Entries) === 0)
                                    <p class="text-sm text-gray-400 text-center py-4">
                                        Noch keine Einträge. Klicken Sie auf "Eintrag hinzufügen".
                                    </p>
                                @endif
                                @error('par16Entries') <span class="text-sm text-red-500">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-between mt-8">
                        <button type="button" wire:click="previousStep"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition">
                            @svg('heroicon-o-arrow-left', 'w-4 h-4') Zurück
                        </button>
                        <button type="button" wire:click="nextStep"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
                            Weiter @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        </button>
                    </div>
                </div>
            @endif

            {{-- Step 3: Vertrag & Unterschrift --}}
            @if($step === 3)
                <div class="space-y-6">
                    {{-- Vertragstext --}}
                    <div class="bg-white rounded-lg border border-gray-200 p-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4">{{ $contractTemplateName }}</h2>
                        <div class="prose prose-sm max-w-none border border-gray-100 rounded-lg p-6 bg-gray-50 max-h-[60vh] overflow-y-auto">
                            {!! $contractContent !!}
                        </div>
                    </div>

                    {{-- Unterschrift --}}
                    <div class="bg-white rounded-lg border border-gray-200 p-8">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">Unterschrift</h3>
                        <p class="text-sm text-gray-500 mb-4">
                            Mit Ihrer Unterschrift bestätigen Sie, dass Sie den Vertrag gelesen haben und die oben gemachten Angaben korrekt sind.
                        </p>

                        <x-ui-input-signature
                            name="signatureData"
                            label="Ihre Unterschrift"
                            wire:model="signatureData"
                            :required="true"
                            :height="200"
                        />

                        <div class="flex justify-between mt-8">
                            <button type="button" wire:click="previousStep"
                                class="inline-flex items-center gap-2 px-6 py-2.5 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition">
                                @svg('heroicon-o-arrow-left', 'w-4 h-4') Zurück
                            </button>
                            <button type="button" wire:click="sign"
                                class="inline-flex items-center gap-2 px-8 py-3 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 transition">
                                @svg('heroicon-o-check', 'w-5 h-5') Vertrag unterschreiben
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
