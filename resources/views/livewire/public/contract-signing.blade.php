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
                <p class="text-gray-500">{{ $duzen ? 'Dieser Link ist abgelaufen. Bitte kontaktiere deinen Arbeitgeber.' : 'Dieser Link ist abgelaufen. Bitte kontaktieren Sie Ihren Arbeitgeber.' }}</p>
            </div>
        @elseif($state === 'already_signed')
            <div class="bg-white rounded-lg border border-green-200 p-12 text-center">
                @svg('heroicon-o-check-circle', 'w-16 h-16 text-green-500 mx-auto mb-4')
                <h2 class="text-xl font-bold text-gray-900 mb-2">Vertrag unterschrieben</h2>
                <p class="text-gray-500 mb-6">Dieser Vertrag wurde erfolgreich unterschrieben. Vielen Dank!</p>
                @if($portalUrl)
                    <a href="{{ $portalUrl }}"
                       class="inline-flex items-center gap-2 px-5 py-2.5 bg-[var(--ui-primary)] text-white text-sm font-medium rounded-md hover:bg-[var(--ui-primary-dark)] transition-colors">
                        @svg('heroicon-o-arrow-uturn-left', 'w-4 h-4')
                        Zurück zu meinen Verträgen
                    </a>
                    <p class="text-xs text-gray-400 mt-4">{{ $duzen ? 'Dort siehst du alle weiteren Dokumente, die noch zur Unterschrift anstehen.' : 'Dort sehen Sie alle weiteren Dokumente, die noch zur Unterschrift anstehen.' }}</p>
                @endif
            </div>
        @elseif($state === 'loading')
            <div class="bg-white rounded-lg border p-12 text-center">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
                <p class="text-gray-500">Vertrag wird geladen...</p>
            </div>
        @elseif($state === 'form')
            {{-- Fortschrittsanzeige (nur wenn Step 1 mit §15/§16 Pre-Signing relevant ist) --}}
            @if($requiresPreSigningStep)
                <div class="mb-8">
                    <div class="flex items-center justify-between mb-2">
                        @php
                            $stepOneLabel = $preSigningType === 'resttage' ? 'Deine Angabe' : '§15/§16 Angaben';
                        @endphp
                        @foreach([1 => $stepOneLabel, 2 => 'Vertrag & Unterschrift'] as $num => $label)
                            <div class="flex items-center {{ $num < 2 ? 'flex-1' : '' }}">
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
                                @if($num < 2)
                                    <div class="flex-1 mx-4 h-0.5 {{ $step > $num ? 'bg-blue-600' : 'bg-gray-200' }}"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Step 1: §15 + §16 zusammen (nur Arbeitsvertraege) --}}
            @if($step === 1 && $preSigningType === 'par1516')
                <div class="space-y-6">
                    {{-- §15 - Kurzfristige Beschäftigungen --}}
                    <div class="bg-white rounded-lg border border-gray-200 p-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-2">Angaben nach §15 — Kurzfristige Beschäftigungen</h2>
                        <p class="text-gray-500 text-sm mb-6">
                            {{ $duzen ? 'Warst du in den letzten 12 Monaten kurzfristig beschäftigt?' : 'Waren Sie in den letzten 12 Monaten kurzfristig beschäftigt?' }}
                        </p>

                        <div class="space-y-4">
                            <div class="flex items-center gap-6">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" wire:model.live="par15HasPrevious" value="1" class="text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm font-medium text-gray-700">Ja</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" wire:model.live="par15HasPrevious" value="0" class="text-blue-600 focus:ring-blue-500">
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
                                            {{ $duzen ? 'Noch keine Einträge. Klick auf "Eintrag hinzufügen".' : 'Noch keine Einträge. Klicken Sie auf "Eintrag hinzufügen".' }}
                                        </p>
                                    @endif
                                    @error('par15Entries') <span class="text-sm text-red-500">{{ $message }}</span> @enderror
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- §16 - Beschäftigungslose Zeiten --}}
                    <div class="bg-white rounded-lg border border-gray-200 p-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-2">Angaben nach §16 — Beschäftigungslose Zeiten</h2>
                        <p class="text-gray-500 text-sm mb-6">
                            {{ $duzen ? 'Warst du in den letzten 12 Monaten bei der Arbeitsagentur als arbeitssuchend gemeldet?' : 'Waren Sie in den letzten 12 Monaten bei der Arbeitsagentur als arbeitssuchend gemeldet?' }}
                        </p>

                        <div class="space-y-4">
                            <div class="flex items-center gap-6">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" wire:model.live="par16WasJobseeking" value="1" class="text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm font-medium text-gray-700">Ja</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" wire:model.live="par16WasJobseeking" value="0" class="text-blue-600 focus:ring-blue-500">
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
                                            {{ $duzen ? 'Noch keine Einträge. Klick auf "Eintrag hinzufügen".' : 'Noch keine Einträge. Klicken Sie auf "Eintrag hinzufügen".' }}
                                        </p>
                                    @endif
                                    @error('par16Entries') <span class="text-sm text-red-500">{{ $message }}</span> @enderror
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" wire:click="nextStep"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
                            Weiter @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        </button>
                    </div>
                </div>
            @endif

            {{-- Step 1 (Variante): Rest-Kontingent bei der 140-Tage-Erklaerung --}}
            @if($step === 1 && $preSigningType === 'resttage')
                @php
                    $resttageFrage = $duzen
                        ? 'Wie viele der 140 genehmigungsfreien Tage stehen dir in diesem Kalenderjahr noch zur Verfügung?'
                        : 'Wie viele der 140 genehmigungsfreien Tage stehen Ihnen in diesem Kalenderjahr noch zur Verfügung?';
                    $resttageHinweis = $duzen
                        ? 'Zähle alle Tage mit, die du dieses Jahr bereits bei anderen Arbeitgebern gearbeitet hast, und ziehe sie von 140 ab. Wenn du dieses Jahr noch nicht gearbeitet hast, sind es 140 Tage.'
                        : 'Zählen Sie alle Tage mit, die Sie dieses Jahr bereits bei anderen Arbeitgebern gearbeitet haben, und ziehen Sie sie von 140 ab. Wenn Sie dieses Jahr noch nicht gearbeitet haben, sind es 140 Tage.';
                    $resttageNachweis = $duzen
                        ? 'Hast du dieses Jahr schon woanders gearbeitet, brauchen wir zusätzlich eine Bescheinigung über die bereits gearbeiteten Tage.'
                        : 'Haben Sie dieses Jahr schon woanders gearbeitet, benötigen wir zusätzlich eine Bescheinigung über die bereits gearbeiteten Tage.';
                @endphp
                <div class="space-y-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-2">Verfügbare Arbeitstage</h2>
                        <p class="text-gray-500 text-sm mb-6">{{ $resttageFrage }}</p>

                        <div class="max-w-xs">
                            <label for="resttage" class="block text-sm font-medium text-gray-700 mb-1">
                                Verfügbare Tage
                            </label>
                            <input type="number" id="resttage" wire:model="resttage"
                                min="0" max="140" step="1" inputmode="numeric"
                                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500">
                            @error('resttage')
                                <span class="text-xs text-red-500">{{ $message }}</span>
                            @enderror
                        </div>

                        <p class="text-sm text-gray-500 mt-4">{{ $resttageHinweis }}</p>

                        <div class="mt-6 rounded-md bg-amber-50 border border-amber-200 p-4">
                            <p class="text-sm text-amber-900">{{ $resttageNachweis }}</p>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" wire:click="nextStep"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
                            Weiter @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        </button>
                    </div>
                </div>
            @endif

            {{-- Step 2: Vertrag & Unterschrift --}}
            @if($step === 2)
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

                        @if($contentIncomplete)
                            @php
                                $incompleteHinweis = $duzen
                                    ? 'Dieses Dokument ist noch nicht vollständig ausgefüllt. Bitte melde dich bei uns — wir klären das und schicken dir das Dokument neu.'
                                    : 'Dieses Dokument ist noch nicht vollständig ausgefüllt. Bitte melden Sie sich bei uns — wir klären das und schicken Ihnen das Dokument neu.';
                            @endphp
                            <div class="rounded-md bg-red-50 border border-red-200 p-4">
                                <p class="text-sm text-red-900">{{ $incompleteHinweis }}</p>
                            </div>
                        @else
                            <p class="text-sm text-gray-500 mb-4">
                                {{ $duzen ? 'Mit deiner Unterschrift bestätigst du, dass du den Vertrag gelesen hast und die oben gemachten Angaben korrekt sind.' : 'Mit Ihrer Unterschrift bestätigen Sie, dass Sie den Vertrag gelesen haben und die oben gemachten Angaben korrekt sind.' }}
                            </p>

                            @php
                                $signatureLabel = $duzen ? 'Deine Unterschrift' : 'Ihre Unterschrift';
                            @endphp
                            <x-ui-input-signature
                                name="signatureData"
                                :label="$signatureLabel"
                                wire:model="signatureData"
                                :required="true"
                                :height="200"
                            />
                        @endif

                        <div class="flex {{ $requiresPreSigningStep ? 'justify-between' : 'justify-end' }} mt-8">
                            @if($requiresPreSigningStep)
                                <button type="button" wire:click="previousStep"
                                    class="inline-flex items-center gap-2 px-6 py-2.5 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition">
                                    @svg('heroicon-o-arrow-left', 'w-4 h-4') Zurück
                                </button>
                            @endif
                            @if(!$contentIncomplete)
                                <button type="button" wire:click="sign"
                                    class="inline-flex items-center gap-2 px-8 py-3 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 transition">
                                    @svg('heroicon-o-check', 'w-5 h-5') Vertrag unterschreiben
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
