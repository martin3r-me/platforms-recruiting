{{-- Fenster „Zeit“ (Zeiterfassung, Spec 2026-11-16 §4). Greift auf $this->zeitFenster, $this->zeitAssignmentId zu.
     Erledigte Datensaetze sind nur lesbar. Der Eventzugang ($eventOnly) bucht wie die Disposition, aber mit dem
     gewaehlten Teamleiter-Namen (Markus 18.11.2026) — nur „Wieder oeffnen“ bleibt ihm verwehrt.
     Zeitfelder in Viertelstunden-Schritten: gespeichert wird ohnehin gerundet. --}}
@php
    $zf = $this->zeitFenster;
    $zfAssignmentId = $this->zeitAssignmentId;
    $zfNurLesen = $zf !== null && $zf['gesperrt'];
    $zfAnZas = $zf !== null && ($zf['an_zas'] ?? false);
    $zfDarfOeffnen = $zf !== null && $zf['gesperrt'] && !$zfAnZas && $this->zeitDarfOeffnen;
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
                    <p class="rounded bg-gray-50 px-3 py-2 text-sm text-gray-600">
                        @if ($zfAnZas)
                            Erledigt und an ZAS übergeben. Änderungen bitte direkt in ZAS.
                        @else
                            Erledigt und gesperrt. Die Werte sind nur noch lesbar.
                        @endif
                    </p>
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

                @php
                    $zw = $this->zeitWaesche;
                    $zwArtikel = \Platform\Recruiting\Services\Zeit\Waesche\WaescheArtikel::fest();
                @endphp
                @if ($zw !== null)
                    <div class="rounded-lg border border-gray-200 p-3">
                        <div class="mb-2 flex items-baseline justify-between gap-2">
                            <h3 class="text-sm font-medium text-gray-700">Wäsche</h3>
                            @if ($zw['posten'] !== [])
                                <span class="text-xs text-gray-500">Abzug Lohn: {{ $zw['abzug'] }} €</span>
                            @endif
                        </div>
                        @if ($zw['posten'] === [])
                            <p class="text-sm text-gray-400">Keine Wäsche ausgegeben.</p>
                        @else
                            <ul class="divide-y divide-gray-100 text-sm">
                                @foreach ($zw['posten'] as $wp)
                                    <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="waesche-{{ $wp['id'] }}-{{ $wp['zurueck'] }}-{{ $waescheStand }}">
                                        <span class="text-gray-700">
                                            {{ $wp['anzahl'] }} × {{ $wp['name'] }}
                                            @if ($wp['sonstiges'])
                                                <span class="text-xs text-gray-400">({{ $wp['preis'] }} €)</span>
                                            @endif
                                        </span>
                                        <span class="flex items-center gap-2">
                                            <label class="flex items-center gap-1 text-xs text-gray-600">
                                                zurück
                                                <select wire:change="waescheZurueck({{ $wp['id'] }}, $event.target.value)" @if ($zfNurLesen) disabled @endif
                                                        class="rounded border border-gray-300 px-1 py-0.5 text-sm disabled:bg-gray-50 disabled:text-gray-500">
                                                    @for ($n = 0; $n <= $wp['anzahl']; $n++)
                                                        <option value="{{ $n }}" @if ($n === $wp['zurueck']) selected @endif>{{ $n }}</option>
                                                    @endfor
                                                </select>
                                            </label>
                                            @if (!$zfNurLesen)
                                                <button type="button" wire:click="waescheEntfernen({{ $wp['id'] }})" wire:confirm="Posten entfernen?"
                                                        class="rounded px-1.5 text-gray-400 hover:bg-gray-100 hover:text-red-600" title="Entfernen">✕</button>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($zw['fehlt'] !== [])
                                <p class="mt-1 text-xs text-amber-700">Noch nicht zurück: {{ implode(', ', $zw['fehlt']) }}</p>
                            @endif
                        @endif

                        @if (!$zfNurLesen)
                            <div class="mt-2 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-2">
                                <label class="block text-xs text-gray-600">
                                    Artikel
                                    <select wire:model.live="waescheArtikel" class="mt-0.5 block rounded border border-gray-300 px-2 py-1 text-sm">
                                        @foreach ($zwArtikel as $wk => $wa)
                                            <option value="{{ $wk }}">{{ $wa->name }}</option>
                                        @endforeach
                                        <option value="sonstiges">Sonstiges …</option>
                                    </select>
                                </label>
                                <label class="block text-xs text-gray-600">
                                    Anzahl
                                    <input type="text" inputmode="numeric" wire:model="waescheAnzahl" class="mt-0.5 block w-14 rounded border border-gray-300 px-2 py-1 text-sm">
                                </label>
                                @if ($waescheArtikel === 'sonstiges')
                                    <label class="block text-xs text-gray-600">
                                        Bezeichnung
                                        <input type="text" wire:model="waescheName" maxlength="80" placeholder="z. B. Hose" class="mt-0.5 block w-32 rounded border border-gray-300 px-2 py-1 text-sm">
                                    </label>
                                    <label class="block text-xs text-gray-600">
                                        Preis €
                                        <input type="text" inputmode="decimal" wire:model="waescheEuro" placeholder="19,90" class="mt-0.5 block w-20 rounded border border-gray-300 px-2 py-1 text-sm">
                                    </label>
                                @endif
                                <button type="button" wire:click="waescheAusgeben" class="rounded border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-50">Ausgeben</button>
                            </div>
                            @error('waescheEuro') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        @endif
                    </div>
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
