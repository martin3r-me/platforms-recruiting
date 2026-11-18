{{-- Fenster „Zeit“ (Zeiterfassung, Spec 2026-11-16 §4). Greift auf $this->zeitFenster, $this->zeitAssignmentId zu.
     Erledigte Datensaetze sind nur lesbar. Der Eventzugang ($eventOnly) bucht wie die Disposition, aber mit dem
     gewaehlten Teamleiter-Namen (Markus 18.11.2026) — nur „Wieder oeffnen“ bleibt ihm verwehrt.
     Zeitfelder in Viertelstunden-Schritten: gespeichert wird ohnehin gerundet. --}}
@php
    $zf = $this->zeitFenster;
    $zfAssignmentId = $this->zeitAssignmentId;
    $zfNurLesen = $zf !== null && $zf['gesperrt'];
    $zfDarfOeffnen = $zf !== null && $zf['gesperrt'] && $this->zeitDarfOeffnen;
    $zfKannErledigen = $zf !== null && !$zfNurLesen && $zf['zustand'] === 'ausgecheckt';
    $zfRegel = $zf === null ? '' : 'nach Regel: ' . $zf['regelpause'];
    $zfKopfTeile = array_values(array_filter([$zf['name'] ?? '', $zf['tag'] ?? ''], fn ($t) => $t !== ''));
    $zfKopf = $zfKopfTeile === [] ? '' : ' – ' . implode(' · ', $zfKopfTeile);
    $zfZustandTexte = ['offen' => 'nicht eingecheckt', 'eingecheckt' => 'eingecheckt', 'ausgecheckt' => 'ausgecheckt', 'erledigt' => 'erledigt'];
@endphp
@if ($zf !== null)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:items-center" wire:click.self="closeZeit">
        <div class="w-full max-w-lg my-auto flex max-h-[calc(100dvh-2rem)] flex-col rounded-lg bg-white">
            <div class="shrink-0 px-6 pt-6 pb-3">
                <h2 class="text-lg font-semibold">Zeit{{ $zfKopf }}</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Soll-Schicht {{ $zf['soll'] !== '' ? $zf['soll'] : '—' }} · {{ $zfZustandTexte[$zf['zustand']] ?? $zf['zustand'] }}
                    @if ($zf['gesperrt'])
                        <span class="ml-1" title="Erledigt und gesperrt">🔒</span>
                    @endif
                </p>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-6 pb-2 space-y-4">
                @if ($zf['gesperrt'])
                    <p class="rounded bg-gray-50 px-3 py-2 text-sm text-gray-600">Erledigt und gesperrt. Die Werte sind nur noch lesbar.</p>
                @elseif ($eventOnly)
                    <div class="rounded bg-gray-50 px-3 py-2">
                        @include('recruiting::livewire.dispo.events._zeit-teamleiter-name', ['zeitTlVorschlaege' => $this->zeitTeamleiterVorschlaege])
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="text-gray-600">Kommen</span>
                        <input type="datetime-local" step="900" wire:model="zeitKommen" @if ($zfNurLesen) disabled @endif
                               class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-500">
                        @error('zeitKommen') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-gray-600">Gehen</span>
                        <input type="datetime-local" step="900" wire:model="zeitGehen" @if ($zfNurLesen) disabled @endif
                               class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-500">
                        @error('zeitGehen') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="text-gray-600">Pause (Minuten)</span>
                        <input type="text" inputmode="numeric" wire:model="zeitPause" placeholder="{{ $zfRegel }}" @if ($zfNurLesen) disabled @endif
                               class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-500">
                        @error('zeitPause') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm sm:col-span-2">
                        <span class="text-gray-600">Bemerkung</span>
                        <input type="text" wire:model="zeitBemerkung" @if ($zfNurLesen) disabled @endif
                               class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-500">
                    </label>
                </div>
                @if (!$zfNurLesen)
                    <p class="text-xs text-gray-500">Zeiten werden auf die nächste Viertelstunde gerundet.</p>
                @endif

                @if (!$zfNurLesen)
                    <label class="block text-sm">
                        <span class="text-gray-600">Grund der Änderung <span class="text-red-600">*</span></span>
                        <input type="text" wire:model="zeitGrund" maxlength="255" placeholder="Pflicht, z. B. „Teamleiter bestätigt 22:30“"
                               class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('zeitGrund') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif

                @if ($zfDarfOeffnen)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <label class="block text-sm">
                            <span class="text-gray-600">Grund für das Wiederöffnen <span class="text-red-600">*</span></span>
                            <input type="text" wire:model="zeitOeffnenGrund" maxlength="255" placeholder="Pflicht, steht im Verlauf"
                                   class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('zeitOeffnenGrund') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <button type="button" wire:click="zeitOeffnen" class="mt-2 rounded border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Wieder öffnen</button>
                    </div>
                @endif

                @if ($zeitMeldung !== null)
                    <p class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ $zeitMeldung }}</p>
                @endif

                <div>
                    <h3 class="mb-1 text-sm font-medium text-gray-700">Verlauf</h3>
                    @if ($zf['verlauf'] === [])
                        <p class="text-sm text-gray-400">Noch keine Einträge.</p>
                    @else
                        <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                            @foreach ($zf['verlauf'] as $zeile)
                                <li class="px-3 py-2">
                                    <div class="flex flex-wrap items-baseline gap-x-2">
                                        <span class="text-xs text-gray-400">{{ $zeile['wann'] }}</span>
                                        <span class="font-medium text-gray-700">{{ $zeile['wer'] }}</span>
                                        <span class="text-xs text-gray-400">{{ $zeile['art'] }}</span>
                                    </div>
                                    @if (($zeile['text'] ?? null) !== null)
                                        <div class="text-gray-700">{{ $zeile['text'] }}</div>
                                    @else
                                        <div class="text-gray-700">
                                            {{ $zeile['feld'] }}:
                                            <span class="text-gray-500">{{ $zeile['alt'] ?? '—' }}</span>
                                            →
                                            <span>{{ $zeile['neu'] ?? '—' }}</span>
                                        </div>
                                    @endif
                                    @if ($zeile['grund'] !== null && $zeile['grund'] !== '')
                                        <div class="text-xs text-gray-500">Grund: {{ $zeile['grund'] }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
            <div class="shrink-0 border-t border-gray-100 px-6 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    @if (!$zfNurLesen)
                        <div class="flex gap-2">
                            <button type="button" wire:click="zeitJetztKommen" class="rounded border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50" title="Stellvertretend: Uhrzeit jetzt, auf die Viertelstunde gerundet, ohne Zeitfenster-Prüfung">Jetzt kommen</button>
                            <button type="button" wire:click="zeitJetztGehen" class="rounded border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50" title="Stellvertretend: Uhrzeit jetzt, auf die Viertelstunde gerundet, ohne Zeitfenster-Prüfung">Jetzt gehen</button>
                            @if ($zfKannErledigen)
                                <button type="button" wire:click="zeitErledigt" class="rounded border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50" title="Sperrt den Eintrag für alle. Wieder öffnen kann ihn nur jemand mit eigener Anmeldung.">Erledigt</button>
                            @endif
                        </div>
                    @else
                        <span></span>
                    @endif
                    <div class="flex gap-3">
                        <button type="button" wire:click="closeZeit" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">{{ $zfNurLesen ? 'Schließen' : 'Abbrechen' }}</button>
                        @if (!$zfNurLesen)
                            <button type="button" wire:click="zeitSpeichern" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
