<div class="p-4 lg:p-6 space-y-6">
    @php
        $event = $this->event;
        $eventOnly = $this->eventOnly;
        $statusLabels = [0 => 'Angebot', 1 => 'Auftrag', 2 => 'Beendet', 3 => 'Storno'];
        $statusClasses = [
            0 => 'bg-gray-100 text-gray-700',
            1 => 'bg-green-100 text-green-800',
            2 => 'bg-blue-50 text-blue-700',
            3 => 'bg-red-100 text-red-800',
        ];
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold">
                {{ $event->name ?? $event->einsatz_ref }}
                @if ($event->alarmMessage?->status === 'failed')
                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 align-middle text-xs text-red-800">Alarm nicht zugestellt</span>
                @endif
            </h1>
            <p class="text-sm text-gray-500">
                {{ $event->einsatz_ref }}
                @if ($event->starts_on) · {{ $event->starts_on->format('d.m.Y') }}@endif
                @if ($event->ends_on && $event->starts_on && !$event->ends_on->isSameDay($event->starts_on)) – {{ $event->ends_on->format('d.m.Y') }}@endif
                @if ($event->einsatzfirma) · {{ $event->einsatzfirma }}@endif
            </p>
        </div>
        {{-- Zurueck ueber die Browser-History, damit die Filter der Liste erhalten bleiben (Kunde 03.09.); ohne History faellt der Link auf die Route zurueck. --}}
        <a href="{{ route('recruiting.dispo.events.index') }}"
           onclick="if (window.history.length > 1 && document.referrer.indexOf(window.location.host) !== -1) { window.history.back(); return false; }"
           class="text-sm text-blue-600 hover:underline">← Zurück zur Liste</a>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        @if ($event->venue_text)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="text-sm font-medium text-gray-500">Adresse</div>
                <div class="mt-1 whitespace-pre-line text-sm">{{ $event->venue_text }}</div>
            </div>
        @endif
        @if ($event->ort)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="text-sm font-medium text-gray-500">Anfahrt / wo genau</div>
                <div class="mt-1 whitespace-pre-line text-sm">{{ $event->ort }}</div>
            </div>
        @endif
        @if ($event->dresscode)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="text-sm font-medium text-gray-500">Kleidung / Infos</div>
                <div class="mt-1 whitespace-pre-line text-sm">{{ $event->dresscode }}</div>
            </div>
        @endif
        @php
            $contactEff = $this->contactEffective;
        @endphp
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between">
                <div class="text-sm font-medium text-gray-500">Ansprechpartner vor Ort</div>
                @if (!$eventOnly)
                    <button type="button" wire:click="openContactModal" class="text-xs text-blue-600 hover:underline">Anpassen</button>
                @endif
            </div>
            <div class="mt-1 text-sm">
                {{ $contactEff['label'] ?? '—' }}
                @if ($contactEff['source'] === 'auto')
                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">Teamleitung (Standard)</span>
                @elseif ($contactEff['source'] === 'manual')
                    <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">manuell</span>
                @endif
            </div>
        </div>
        @php
            $esc = $this->escalationEffective;
            $escEnabled = $this->dispoSettings['escalation_enabled'];
            $escDayLabel = match ($esc['day']) {
                'einsatztag' => 'am Einsatztag',
                'datum'      => 'am ' . \Carbon\Carbon::parse($esc['date'])->format('d.m.Y'),
                default      => 'am Vortag',
            };
            $escTimesLabel = $esc['times'][1] . ' / ' . $esc['times'][2] . ' / ' . $esc['times'][3];
        @endphp
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between">
                <div class="text-sm font-medium text-gray-500">Eskalation</div>
                @if (!$eventOnly)
                    <button type="button" wire:click="openEscalationModal" class="text-xs text-blue-600 hover:underline">Anpassen</button>
                @endif
            </div>
            <div class="mt-1 text-sm">
                {{ $escDayLabel }} · {{ $escTimesLabel }}
                @if ($esc['overridden'])
                    <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">angepasst</span>
                @else
                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">Standard</span>
                @endif
            </div>
            @if (!$escEnabled)
                <div class="mt-1 text-xs text-gray-500">Eskalation global deaktiviert (Disposition → Einstellungen).</div>
            @endif
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-4 py-3 font-medium">
            Einbuchungen ({{ $event->assignments->count() }})
            @php
                $templateConfigured = $this->dispoSettings['template_id'] !== null;
            @endphp
@if (!$eventOnly)
            <button wire:click="openInfoModal"
                    class="rounded bg-white px-3 py-1.5 text-sm font-medium text-blue-700 ring-1 ring-blue-200 hover:bg-blue-50">
                Info an Crew
            </button>
            <button wire:click="openSendModal"
                    @if (!$templateConfigured) disabled title="Kein Bestätigungs-Template konfiguriert (Disposition → Einstellungen)" @endif
                    class="rounded px-3 py-1.5 text-sm font-medium {{ $templateConfigured ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-400 cursor-not-allowed' }}">
                Bestätigungen senden
            </button>
            @endif
        </div>
        {{-- Mobil: Crew-Liste statt 9-Spalten-Tabelle — Name, Zeit, Chips, grosser Chat-Knopf.
             Desktop (lg:) rendert unveraendert die Tabelle darunter. --}}
        <div class="divide-y divide-gray-100 lg:hidden">
            @php
                $threadsM = $this->threadsByEmployee;
                $canonMapM = $this->identity['canon'];
            @endphp
            @forelse ($event->assignments as $assignment)
                @php
                    $cidM = $assignment->rec_employee_id ? ($canonMapM[(int) $assignment->rec_employee_id] ?? (int) $assignment->rec_employee_id) : null;
                    $thrM = $cidM !== null ? ($threadsM[$cidM] ?? null) : null;
                    $attListM = $assignment->rec_employee_id ? ($this->attachmentsByEmployee[$assignment->rec_employee_id] ?? []) : [];
                    $noteM = $assignment->rec_employee_id ? trim($notes[$assignment->rec_employee_id] ?? '') : '';
                    $zeitM = $assignment->datum->format('d.m.Y');
                    if ($assignment->von) {
                        $zeitM .= ' · ' . $assignment->von . ($assignment->bis ? '–' . $assignment->bis : '');
                    }
                    if ($assignment->taetigkeit) {
                        $zeitM .= ' · ' . $assignment->taetigkeit;
                    }
                @endphp
                <div class="flex items-start gap-3 px-4 py-3 {{ $assignment->missing_since ? 'opacity-50' : '' }}">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold text-gray-900">
                            @if ($assignment->employee)
                                <button type="button" wire:click="openCrew({{ $assignment->rec_employee_id }})" class="text-left">
                                    {{ $assignment->employee->first_name }} {{ $assignment->employee->last_name }}
                                </button>
                            @else
                                <span class="rounded bg-orange-50 px-1.5 py-0.5 text-xs font-normal text-orange-600">PNr unbekannt: {{ $assignment->pnr_raw }}</span>
                            @endif
                        </div>
                        <div class="mt-0.5 text-xs text-gray-500 tabular-nums">{{ $zeitM }}</div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1">
                            <span class="rounded px-1.5 py-0.5 text-xs {{ $statusClasses[$assignment->status_id] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ $statusLabels[$assignment->status_id] ?? $assignment->status_id }}
                            </span>
                            @if ($assignment->missing_since)
                                <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600">verschwunden</span>
                            @endif
                            @include('recruiting::livewire.dispo.events._confirmation-chips', ['assignment' => $assignment])
                        </div>
                        @if ($noteM !== '' || $attListM !== [])
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                                @if ($noteM !== '')
                                    @if ($eventOnly)
                                        <span class="max-w-full truncate">✎ {{ $noteM }}</span>
                                    @else
                                        <button type="button" wire:click="openNote({{ $assignment->rec_employee_id }})" class="max-w-full truncate text-left">✎ {{ $noteM }}</button>
                                    @endif
                                @endif
                                @foreach ($attListM as $attM)
                                    <a href="{{ route('recruiting.dispo.attachments.download', ['uuid' => $attM->uuid]) }}" target="_blank" rel="noopener" class="max-w-full truncate text-blue-600">📎 {{ $attM->original_filename }}</a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if ($thrM)
                        <button type="button" wire:click="openChat({{ $cidM }})"
                                class="relative mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $thrM['is_unread'] ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-500' }}"
                                title="{{ $thrM['is_unread'] ? 'Neue Nachricht' : 'Nachrichten ansehen' }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v12H7l-3 3z"/></svg>
                            @if ($thrM['is_unread'])
                                <span class="absolute -right-0.5 -top-0.5 h-3 w-3 rounded-full bg-red-500 ring-2 ring-white"></span>
                            @endif
                        </button>
                    @endif
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-gray-500">Keine Einbuchungen.</div>
            @endforelse
        </div>

        <div class="hidden lg:block">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Datum</th>
                    <th class="px-4 py-2 font-medium">Zeit</th>
                    <th class="px-4 py-2 font-medium">Tätigkeit</th>
                    <th class="px-4 py-2 font-medium">Mitarbeiter</th>
                    <th class="px-4 py-2 font-medium text-center" title="Kommunikation">💬</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                    <th class="px-4 py-2 font-medium">Bestätigung</th>
                    <th class="px-4 py-2 font-medium">Hinweis</th>
                    <th class="px-4 py-2 font-medium">Anhang</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @php
                    $threads = $this->threadsByEmployee;
                    $canonMap = $this->identity['canon'];
                @endphp
                @forelse ($event->assignments as $assignment)
                    <tr class="{{ $assignment->missing_since ? 'opacity-50' : '' }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $assignment->datum->format('d.m.Y') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $assignment->von ?? '—' }}@if ($assignment->bis)–{{ $assignment->bis }}@endif</td>
                        <td class="px-4 py-2">{{ $assignment->taetigkeit ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($assignment->employee)
                                {{-- Kunde 02.09.: kein Link in die volle MA-Akte — abgespecktes Kaertchen als Modal. --}}
                                <button type="button" wire:click="openCrew({{ $assignment->rec_employee_id }})" class="text-left text-blue-600 hover:underline">
                                    {{ $assignment->employee->first_name }} {{ $assignment->employee->last_name }}
                                </button>
                            @else
                                <span class="rounded bg-orange-50 px-1.5 py-0.5 text-xs text-orange-600">PNr unbekannt: {{ $assignment->pnr_raw }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-center">
                            @php
                                $cid = $assignment->rec_employee_id ? ($canonMap[(int) $assignment->rec_employee_id] ?? (int) $assignment->rec_employee_id) : null;
                                $thr = $cid !== null ? ($threads[$cid] ?? null) : null;
                            @endphp
                            @if ($thr)
                                <button type="button" wire:click="openChat({{ $cid }})"
                                        class="relative inline-flex h-7 w-7 items-center justify-center rounded-full {{ $thr['is_unread'] ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' }}"
                                        title="{{ $thr['is_unread'] ? 'Neue Nachricht' : 'Nachrichten ansehen' }}">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v12H7l-3 3z"/></svg>
                                    @if ($thr['is_unread'])
                                        <span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white"></span>
                                    @endif
                                </button>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <span class="rounded px-1.5 py-0.5 text-xs {{ $statusClasses[$assignment->status_id] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ $statusLabels[$assignment->status_id] ?? $assignment->status_id }}
                            </span>
                            @if ($assignment->missing_since)
                                <span class="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-xs text-red-600" title="Fehlt seit {{ $assignment->missing_since->format('d.m.Y H:i') }} im ZAS-Vollbestand">verschwunden</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @include('recruiting::livewire.dispo.events._confirmation-chips', ['assignment' => $assignment])
                        </td>
                        <td class="px-4 py-2">
                            @if ($assignment->rec_employee_id)
                                @php $note = trim($notes[$assignment->rec_employee_id] ?? ''); @endphp
                                @if ($eventOnly)
                                    @if ($note !== '')
                                        <span class="block max-w-[14rem] truncate text-xs text-gray-700" title="{{ $note }}">{{ $note }}</span>
                                    @endif
                                @else
                                <button type="button" wire:click="openNote({{ $assignment->rec_employee_id }})"
                                        class="group flex max-w-[14rem] items-center gap-1 text-left text-xs {{ $note !== '' ? 'text-gray-700' : 'text-gray-400' }} hover:text-blue-600"
                                        title="{{ $note !== '' ? $note : 'Hinweis hinzufügen' }}">
                                    @if ($note !== '')
                                        <span class="truncate">{{ $note }}</span>
                                        <span class="shrink-0 text-blue-500 opacity-0 group-hover:opacity-100">✎</span>
                                    @else
                                        <span>✎ Hinweis hinzufügen</span>
                                    @endif
                                </button>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($assignment->rec_employee_id)
                                @php $attList = $this->attachmentsByEmployee[$assignment->rec_employee_id] ?? []; @endphp
                                <div class="space-y-0.5">
                                    @foreach ($attList as $att)
                                        <div class="flex items-center gap-2 text-xs">
                                            <a href="{{ route('recruiting.dispo.attachments.download', ['uuid' => $att->uuid]) }}" target="_blank" rel="noopener"
                                               class="max-w-[12rem] truncate text-blue-600 hover:underline" title="{{ $att->original_filename }}">📎 {{ $att->original_filename }}</a>
                                            @if (!$eventOnly)
                                                <button type="button" wire:click="removeAttachment({{ $att->id }})" wire:confirm="Anhang „{{ $att->original_filename }}" entfernen?" class="text-gray-400 hover:text-red-600" title="Entfernen">✕</button>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if (!$eventOnly)
                                        <button type="button" wire:click="openAttachment({{ $assignment->rec_employee_id }})" class="text-xs text-gray-400 hover:text-blue-600">+ Anhang</button>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">Keine Einbuchungen.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if ($showSendModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showSendModal', false)">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Bestätigungen senden</h2>

                @if ($sendResult === null)
                    @php
                        $preview = $this->sendPreview;
                        $eventDays = $this->eventDays;
                    @endphp
                    @if (count($eventDays) > 1)
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium text-gray-700">Tag</span>
                            <select wire:model.live="sendDay" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Alle Tage</option>
                                @foreach ($eventDays as $day)
                                    @php $dayLabel = implode('.', array_reverse(explode('-', $day))); @endphp
                                    <option value="{{ $day }}">{{ $dayLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700">Vorlaufzeit <span class="text-red-600">*</span></span>
                        <div class="flex items-center gap-2">
                            <input type="number" min="0" max="480" wire:model="vorlaufMinuten" placeholder="z. B. 30"
                                   class="w-28 rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-blue-500 focus:ring-blue-500">
                            <span class="text-sm text-gray-600">Minuten vor Dienstbeginn</span>
                        </div>
                        <span class="mt-1 block text-xs text-gray-500">Steht in der WhatsApp und auf der Einsatz-Seite als „Bitte sei X Minuten vor Dienstbeginn vor Ort!".</span>
                    </label>
                    @error('vorlaufMinuten') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700">Ansprechpartner vor Ort <span class="text-gray-400">(optional)</span></span>
                        @include('recruiting::livewire.dispo.events._contact-field', ['leads' => $this->teamLeads])
                    </label>

                    @php
                        $escDefaults = $this->dispoSettings['escalation_defaults'];
                        $escDayPart = match ($escDay) {
                            'einsatztag' => 'Einsatztag',
                            'datum'      => ($escDate !== '' ? 'am ' . \Carbon\Carbon::parse($escDate)->format('d.m.') : 'Datum'),
                            default      => 'Vortag',
                        };
                        $escSummary = $escDayPart . ' · '
                            . ($escTime1 !== '' ? ($escTime1 . ' / ' . $escTime2 . ' / ' . $escTime3) : ('Standard ' . $escDefaults[1] . ' / ' . $escDefaults[2] . ' / ' . $escDefaults[3]));
                    @endphp
                    <details class="rounded border border-gray-200 p-3" @if ($errors->has('escTime1')) open @endif>
                        <summary class="cursor-pointer text-sm font-medium text-gray-700">Eskalation: {{ $escSummary }} <span class="text-xs font-normal text-blue-600">anpassen</span></summary>
                        <div class="mt-3">
                            @include('recruiting::livewire.dispo.events._escalation-fields', ['defaults' => $escDefaults])
                        </div>
                    </details>

                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" wire:model.live="includeReminders" class="rounded border-gray-300">
                        Erinnerung an bereits Angeschriebene ohne Antwort erneut senden
                    </label>

                    <div class="rounded bg-gray-50 p-3 text-sm space-y-1">
                        <div>Sendet an <strong>{{ count($preview['recipients']) }}</strong> Mitarbeiter.</div>
                        @if (($preview['reconfirm'] ?? 0) > 0)
                            <div class="text-amber-700">{{ $preview['reconfirm'] }} × Zeit geändert — erneute Bestätigung (gleiches Template)</div>
                        @endif
                        @php
                            $labels = ['past' => 'in der Vergangenheit', 'not_matched' => 'ohne MA-Zuordnung', 'no_phone' => 'ohne Handynummer', 'confirmed' => 'bereits bestätigt', 'already_sent' => 'bereits angeschrieben', 'wrong_status' => 'nicht im Status Auftrag', 'missing' => 'aus ZAS verschwunden', 'deletion_marked' => 'zur Löschung gemeldet'];
                        @endphp
                        @foreach ($labels as $key => $label)
                            @if (($preview['skipped'][$key] ?? 0) > 0)
                                <div class="text-gray-500">{{ $preview['skipped'][$key] }} × {{ $label }}</div>
                            @endif
                        @endforeach
                    </div>

                    <div class="flex justify-end gap-3">
                        <button wire:click="$set('showSendModal', false)" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                        <button wire:click="sendConfirmations"
                                wire:loading.attr="disabled" wire:target="sendConfirmations"
                                @if (count($preview['recipients']) === 0) disabled @endif
                                class="rounded px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60 {{ count($preview['recipients']) > 0 ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-400 cursor-not-allowed' }}">
                            <span wire:loading.remove wire:target="sendConfirmations">Jetzt senden</span>
                            <span wire:loading wire:target="sendConfirmations">Wird gesendet …</span>
                        </button>
                    </div>
                @else
                    <div class="rounded bg-green-50 p-3 text-sm text-green-800">{{ $sendResult['sent'] }} Nachricht(en) gesendet.</div>
                    @if ($sendResult['failed'] !== [])
                        <div class="rounded bg-red-50 p-3 text-sm text-red-800">
                            <div class="font-medium">{{ count($sendResult['failed']) }} fehlgeschlagen:</div>
                            @foreach ($sendResult['failed'] as $failure)
                                <div>MA #{{ $failure['employee_id'] }}: {{ $failure['error'] }}</div>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex justify-end">
                        <button wire:click="$set('showSendModal', false)" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Schließen</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($showNoteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeNoteModal">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Hinweis für {{ $noteEmployeeName !== '' ? $noteEmployeeName : 'diesen Mitarbeiter' }}</h2>
                <p class="text-sm text-gray-500">Erscheint auf der Einsatz-Seite dieses Mitarbeiters unter „Hinweis für dich" — für alle Tage dieser Veranstaltung.</p>

                <textarea wire:model="noteDraft" rows="5" placeholder="z. B. Bitte am Nebeneingang melden, Türcode 1234"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"></textarea>

                <div class="flex justify-end gap-3">
                    <button wire:click="closeNoteModal" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                    <button wire:click="saveNoteFromModal" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showAttachmentModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeAttachmentModal">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Anhang für {{ $attachmentEmployeeName !== '' ? $attachmentEmployeeName : 'diesen Mitarbeiter' }}</h2>
                <p class="text-sm text-gray-500">Eine Datei (PDF, JPG oder PNG, max. 10 MB). Der Mitarbeiter öffnet sie über seine Einsatz-Seite — für alle Tage dieser Veranstaltung. Erneutes Hochladen ersetzt die bisherige Datei.</p>

                <input type="file" wire:model="attachmentUpload" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm">
                <div wire:loading wire:target="attachmentUpload" class="text-xs text-gray-500">Wird hochgeladen …</div>
                @error('attachmentUpload') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="flex justify-end gap-3">
                    <button wire:click="closeAttachmentModal" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                    <button wire:click="saveAttachment" wire:loading.attr="disabled" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showContactModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showContactModal', false)">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Ansprechpartner vor Ort</h2>
                <p class="text-sm text-gray-500">Gilt für alle Einsatztage dieser Veranstaltung und erscheint sofort auf der Einsatz-Seite — kein Neu-Senden nötig.</p>
                <label class="block text-sm">
                    @include('recruiting::livewire.dispo.events._contact-field', ['leads' => $this->teamLeads])
                </label>
                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showContactModal', false)" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                    <button wire:click="saveContact" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showEscalationModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showEscalationModal', false)">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Eskalation für diese Veranstaltung</h2>
                <p class="text-sm text-gray-500">Gilt für alle Einsatztage dieser Veranstaltung. Rausnahme erfolgt nur, wenn die Bestätigungsanfrage vor Stufe 2 rausging — am Einsatztag also früh senden.</p>
                @include('recruiting::livewire.dispo.events._escalation-fields', ['defaults' => $this->dispoSettings['escalation_defaults']])
                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showEscalationModal', false)" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                    <button wire:click="saveEscalation" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
                </div>
            </div>
        </div>
    @endif

    {{-- "Info an Crew" (Kunde 03.09.): Anhang/Hinweis gefiltert nach Qualifikation
         an viele MA auf einmal + Info-WhatsApp mit Link auf die Einsatz-Seite. --}}
    @if ($showInfoModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showInfoModal', false)">
            <div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Info an Crew</h2>

                @if ($infoResult === null)
                    @php
                        $infoPrev = $this->infoPreview;
                        $withNote = collect($infoPrev['persons'])->where('selected', true)->where('has_note', true)->count();
                    @endphp

                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700">Wer?</span>
                        <select wire:model.live="infoFilter" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Alle disponierten Mitarbeiter</option>
                            @if ($this->infoTaetigkeitOptions !== [])
                                <optgroup label="Tätigkeit (aus dieser VA)">
                                    @foreach ($this->infoTaetigkeitOptions as $taetigkeit)
                                        <option value="t:{{ $taetigkeit }}">{{ $taetigkeit }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if ($this->infoQualOptions !== [])
                                <optgroup label="Qualifikation (aus der MA-Akte)">
                                    @foreach ($this->infoQualOptions as $qualValue => $qualLabel)
                                        <option value="q:{{ $qualValue }}">{{ $qualLabel }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                        <span class="mt-1 block text-xs text-gray-500">Nur kommende Einsatztage; mehrere Personalnummern derselben Person zählen einmal. Einzelne unten abwählbar.</span>
                    </label>

                    @if ($infoPrev['persons'] !== [])
                        <div class="max-h-44 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2">
                            @foreach ($infoPrev['persons'] as $person)
                                <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-gray-50 {{ $person['selected'] ? '' : 'opacity-50' }}">
                                    <input type="checkbox" wire:click="toggleInfoPerson({{ $person['canonical'] }})"
                                           @if ($person['selected']) checked @endif class="rounded border-gray-300">
                                    <span class="truncate">{{ $person['name'] }}</span>
                                    @if ($person['phone'] === null)
                                        <span class="shrink-0 rounded bg-amber-50 px-1.5 py-0.5 text-[10.5px] text-amber-700">kein Telefon</span>
                                    @endif
                                    @if ($person['has_note'])
                                        <span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] text-gray-500" title="Hat bereits einen Hinweis — neuer Text wird ergänzt">✎ Hinweis vorhanden</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700">Datei anhängen <span class="text-gray-400">(optional, für alle identisch)</span></span>
                        <input type="file" wire:model="infoUpload" accept=".pdf,.jpg,.jpeg,.png"
                               class="w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100">
                        <span class="mt-1 block text-xs text-gray-500">PDF/JPG/PNG, max. 10 MB — z. B. Einteilung, Briefing, Plan, Zugangscode. Wird zusätzlich zu bestehenden Anhängen abgelegt.</span>
                        <div wire:loading wire:target="infoUpload" class="mt-1 text-xs text-blue-600">Wird hochgeladen …</div>
                    </label>

                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700">Hinweis <span class="text-gray-400">(optional, für alle identisch)</span></span>
                        <textarea wire:model="infoNote" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="z. B. Treffpunkt geändert: Eingang Nord, Tor 3"></textarea>
                        @if ($withNote > 0)
                            <span class="mt-1 block text-xs text-gray-500">{{ $withNote }} der ausgewählten Personen {{ $withNote === 1 ? 'hat' : 'haben' }} bereits einen Hinweis — der neue Text wird darunter ergänzt.</span>
                        @endif
                    </label>
                    @error('infoNote') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('infoUpload') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model.live="infoSendWhatsApp" class="rounded border-gray-300">
                        WhatsApp „Neue Infos" mitschicken
                    </label>

                    @php
                        $infoSelected = collect($infoPrev['persons'])->where('selected', true);
                        $infoSelNoPhone = $infoSelected->whereNull('phone')->count();
                        $infoWaOn = (bool) $infoSendWhatsApp;
                    @endphp
                    <div class="rounded bg-gray-50 p-3 text-sm space-y-1">
                        @if ($infoWaOn)
                            <div>Geht an <strong>{{ $infoSelected->count() }}</strong> von {{ count($infoPrev['persons']) }} Mitarbeitern — jede/r bekommt die WhatsApp „Neue Infos" mit Link auf die Einsatz-Seite.</div>
                            @if ($infoSelNoPhone > 0)
                                <div class="text-gray-500">{{ $infoSelNoPhone }} × ohne Handynummer (bekommen Anhang/Hinweis, aber keine WhatsApp)</div>
                            @endif
                        @else
                            <div>Wird <strong>{{ $infoSelected->count() }}</strong> von {{ count($infoPrev['persons']) }} Mitarbeitern zugewiesen — <span class="font-medium">ohne WhatsApp</span>. Die Infos stehen auf der Einsatz-Seite, sobald der Link rausgeht (z. B. mit der Bestätigung).</div>
                        @endif
                    </div>

                    <div class="flex justify-end gap-3">
                        <button wire:click="$set('showInfoModal', false)" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                        <button wire:click="sendCrewInfo"
                                wire:loading.attr="disabled" wire:target="sendCrewInfo, infoUpload"
                                @if ($infoSelected->count() === 0) disabled @endif
                                class="rounded px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60 {{ $infoSelected->count() > 0 ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-400' }}">
                            <span wire:loading.remove wire:target="sendCrewInfo">{{ $infoWaOn ? 'Info senden' : 'Zuweisen' }}</span>
                            <span wire:loading wire:target="sendCrewInfo">{{ $infoWaOn ? 'Wird gesendet …' : 'Wird zugewiesen …' }}</span>
                        </button>
                    </div>
                @else
                    <div class="rounded bg-green-50 p-3 text-sm text-green-800">
                        @if ($infoResult['sent'] > 0)
                            {{ $infoResult['sent'] }} WhatsApp(s) gesendet
                        @else
                            Zugewiesen (ohne WhatsApp)
                        @endif
                        @if ($infoResult['attached'] > 0)
                            · {{ $infoResult['attached'] }} × Datei angehängt
                        @endif
                        @if ($infoResult['noted'] > 0)
                            · {{ $infoResult['noted'] }} Einbuchung(en) mit neuem Hinweis
                        @endif
                    </div>
                    @if ($infoResult['no_phone'] > 0)
                        <div class="rounded bg-amber-50 p-3 text-sm text-amber-800">{{ $infoResult['no_phone'] }} Person(en) ohne Handynummer — Infos liegen auf der Einsatz-Seite, aber keine WhatsApp.</div>
                    @endif
                    @if ($infoResult['failed'] !== [])
                        <div class="rounded bg-red-50 p-3 text-sm text-red-800">
                            <div class="font-medium">{{ count($infoResult['failed']) }} nicht zugestellt:</div>
                            @foreach ($infoResult['failed'] as $failure)
                                <div>MA #{{ $failure['employee_id'] }}: {{ $failure['error'] }}</div>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex justify-end">
                        <button wire:click="$set('showInfoModal', false)" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Schließen</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Crew-Modal (Kunde 02.09.): abgespecktes Personal-Kaertchen — Selfie, Sterne,
         bestaetigte Einsaetze, Qualifikationen. Mobil als Bottom-Sheet. --}}
    @if ($crewEmployeeId !== null)
        @php $crew = $this->crewCard; @endphp
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" wire:click.self="closeCrew"
             x-data="{ zoom: false }">
            <div class="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white p-6 sm:max-w-lg sm:rounded-2xl sm:p-7" wire:key="crew-{{ $crewEmployeeId }}">
                @if ($crew === null)
                    <div class="text-sm text-gray-500">Keine Daten gefunden.</div>
                @else
                    <div class="flex items-start gap-4 sm:gap-5">
                        @if ($crew['selfie_url'])
                            {{-- Tipp aufs Foto vergroessert (Teamleiter muss das Gesicht zuordnen koennen). --}}
                            <button type="button" x-on:click="zoom = true" class="group relative shrink-0" title="Foto vergrößern">
                                <img src="{{ $crew['selfie_url'] }}" alt="Foto von {{ $crew['name'] }}"
                                     class="h-28 w-28 rounded-2xl border border-gray-200 object-cover sm:h-32 sm:w-32">
                                <span class="absolute bottom-1 right-1 grid h-6 w-6 place-items-center rounded-full bg-black/50 text-xs text-white">⤢</span>
                            </button>
                        @else
                            <div class="grid h-28 w-28 shrink-0 place-items-center rounded-2xl bg-gray-100 text-3xl font-bold text-gray-400 sm:h-32 sm:w-32">
                                {{ mb_strtoupper(mb_substr($crew['name'], 0, 1)) }}
                            </div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="text-xl font-semibold leading-tight text-gray-900">{{ $crew['name'] }}</div>
                            @if ($crew['pnrs'] !== [])
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($crew['pnrs'] as $pnr)
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600 tabular-nums">{{ $pnr }}</span>
                                    @endforeach
                                </div>
                            @endif

                        </div>
                        <button type="button" wire:click="closeCrew" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-gray-100 text-gray-600" aria-label="Schließen">✕</button>
                    </div>

                    <div class="mt-5 rounded-xl bg-gray-50 px-4 py-3.5">
                        <span class="text-3xl font-bold tabular-nums text-gray-900">{{ $crew['confirmed_past'] }}</span>
                        <span class="ml-1.5 text-sm text-gray-600">bestätigte {{ $crew['confirmed_past'] === 1 ? 'Einsatz' : 'Einsätze' }} bisher</span>
                    </div>

                    <div class="mt-4">
                        <div class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">Bewertung</div>
                        @if ($crew['ratings'] !== [])
                            <div class="mt-1.5 space-y-1.5">
                                @foreach ($crew['ratings'] as $ratingLabel => $ratingValue)
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-sm text-gray-700">{{ $ratingLabel }}</span>
                                        <span class="shrink-0 text-base leading-none tracking-wide"><span class="text-amber-400">{{ str_repeat('★', $ratingValue) }}</span><span class="text-gray-300">{{ str_repeat('★', 5 - $ratingValue) }}</span></span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="mt-1 text-sm text-gray-400">noch keine Bewertung</div>
                        @endif
                    </div>

                    <div class="mt-4">
                        <div class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">Qualifikationen</div>
                        @if ($crew['qualifications'] !== [])
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @foreach ($crew['qualifications'] as $qual)
                                    <span class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm font-medium text-gray-700">{{ $qual }}</span>
                                @endforeach
                            </div>
                        @else
                            <div class="mt-1 text-sm text-gray-400">keine hinterlegt</div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Vollbild-Zoom des Selfies (Alpine, kein Server-Roundtrip). --}}
            @if ($crew !== null && $crew['selfie_full_url'])
                <div x-cloak x-show="zoom" x-on:click="zoom = false"
                     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4">
                    <img src="{{ $crew['selfie_full_url'] }}" alt="Foto von {{ $crew['name'] }}"
                         class="max-h-[90vh] max-w-full rounded-xl object-contain">
                    <button type="button" x-on:click.stop="zoom = false" class="absolute right-4 top-4 grid h-10 w-10 place-items-center rounded-full bg-white/20 text-xl text-white" aria-label="Schließen">✕</button>
                </div>
            @endif
        </div>
    @endif

    @php $chat = $chatEmployeeId !== null ? $this->chat : null; @endphp
    @if ($chatEmployeeId !== null)
        <div class="fixed inset-0 z-40 bg-black/30 lg:hidden" wire:click="closeChat"></div>
        <aside class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-gray-50 shadow-xl lg:w-[28rem]" wire:key="chat-{{ $chatEmployeeId }}" wire:poll.visible.20s>
            @if ($chat === null)
                <div class="p-4 text-sm text-gray-500">Kein Thread gefunden. <button type="button" wire:click="closeChat" class="text-blue-600 underline">Schließen</button></div>
            @else
                @php
                    $w = $chat['window'];
                    $wcClass = $w['state'] === 'open' ? 'bg-green-50 text-green-700' : ($w['state'] === 'closed' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500');
                    $wcText = $w['state'] === 'open' ? ('offen · ' . $w['left']) : ($w['state'] === 'closed' ? 'Fenster abgelaufen' : 'noch keine Antwort');
                @endphp
                <div class="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-[15px] font-semibold">
                            <span class="truncate">{{ $chat['name'] }}</span>
                            @foreach ($chat['pnrs'] as $pnr)
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600 tabular-nums">{{ $pnr }}</span>
                            @endforeach
                            <span class="rounded px-1.5 py-0.5 text-[10.5px] font-semibold {{ $wcClass }}">{{ $wcText }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-500 tabular-nums">
                            {{ $chat['phone'] }}
                            @if ($chat['portal_url'] && !$eventOnly)
                                <a href="{{ $chat['portal_url'] }}" target="_blank" rel="noopener" class="font-semibold text-blue-700 hover:underline" title="Persönlicher Link des Mitarbeiters — nicht weitergeben.">Was der MA sieht ↗</a>
                            @endif
                        </div>
                    </div>
                    <button type="button" wire:click="closeChat" class="grid h-9 w-9 place-items-center rounded-lg bg-gray-100 text-gray-600" aria-label="Schließen">✕</button>
                </div>
                <div class="flex items-center gap-2 border-b border-gray-200 bg-white px-4 py-2 text-xs">
                    <button type="button" wire:click="setChatFilter('seit_versand')" class="rounded px-2 py-1 {{ $chatFilter === 'seit_versand' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600' }}">seit Versand</button>
                    <button type="button" wire:click="setChatFilter('alle')" class="rounded px-2 py-1 {{ $chatFilter === 'alle' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600' }}">alle</button>
                    @if ($chat['since'])
                        <span class="text-gray-400">ab {{ $chat['since'] }}</span>
                    @endif
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-4 py-4"
                     wire:key="chatmsgs-{{ $chatEmployeeId }}-{{ count($chat['messages']) }}-{{ $chatFilter }}"
                     x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })">
                    @include('recruiting::livewire.dispo._messages', ['messages' => $chat['messages'], 'portalUrl' => $eventOnly ? null : $chat['portal_url']])
                </div>
                <div class="border-t border-gray-200 bg-white p-3">
                    @if ($w['state'] === 'open')
                        {{-- Vorlagen auch bei offenem Fenster (Kunde 02.09.). --}}
                        <div class="mb-2 flex flex-wrap gap-2">
                            @foreach ($this->chatTemplates as $tpl)
                                <button type="button" wire:click="sendChatTemplate('{{ $tpl['key'] }}')"
                                        wire:loading.attr="disabled" wire:target="sendChatTemplate"
                                        class="rounded-lg border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-600 hover:border-blue-300 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
                                    {{ $tpl['label'] }}
                                </button>
                            @endforeach
                        </div>
                        <form wire:submit="sendChatReply" class="flex items-end gap-2">
                            <textarea wire:model="chatReply" rows="2" placeholder="Antwort schreiben …" class="flex-1 resize-none rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                            <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">Senden</button>
                        </form>
                    @else
                        {{-- Kein Fenster offen: die drei festen Chat-Vorlagen (Kunde 01.09.). --}}
                        <div class="mb-2 flex flex-wrap gap-2">
                            @foreach ($this->chatTemplates as $tpl)
                                <button type="button" wire:click="sendChatTemplate('{{ $tpl['key'] }}')"
                                        wire:loading.attr="disabled" wire:target="sendChatTemplate"
                                        class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-[13px] font-semibold text-blue-700 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60">
                                    {{ $tpl['label'] }}
                                </button>
                            @endforeach
                        </div>
                        <div class="text-xs text-gray-500">Freitext geht erst, wenn der Mitarbeiter antwortet — die Vorlage öffnet das Gespräch.</div>
                    @endif
                    @if ($chatError)
                        <div class="mt-2 text-sm text-red-600">{{ $chatError }}</div>
                    @endif
                </div>
            @endif
        </aside>
    @endif
</div>
