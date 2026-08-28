<div class="p-6 space-y-6">
    @php
        $event = $this->event;
        $statusLabels = [0 => 'Angebot', 1 => 'Auftrag', 2 => 'Beendet', 3 => 'Storno'];
        $statusClasses = [
            0 => 'bg-gray-100 text-gray-700',
            1 => 'bg-green-100 text-green-800',
            2 => 'bg-blue-50 text-blue-700',
            3 => 'bg-red-100 text-red-800',
        ];
    @endphp

    <div class="flex items-center justify-between">
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
        <a href="{{ route('recruiting.dispo.events.index') }}" class="text-sm text-blue-600 hover:underline">← Zurück zur Liste</a>
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
                <button type="button" wire:click="openContactModal" class="text-xs text-blue-600 hover:underline">Anpassen</button>
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
            $escDayLabel = $esc['day'] === 'einsatztag' ? 'am Einsatztag' : 'am Vortag';
            $escTimesLabel = $esc['times'][1] . ' / ' . $esc['times'][2] . ' / ' . $esc['times'][3];
        @endphp
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between">
                <div class="text-sm font-medium text-gray-500">Eskalation</div>
                <button type="button" wire:click="openEscalationModal" class="text-xs text-blue-600 hover:underline">Anpassen</button>
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
            <button wire:click="openSendModal"
                    @if (!$templateConfigured) disabled title="Kein Bestätigungs-Template konfiguriert (Disposition → Einstellungen)" @endif
                    class="rounded px-3 py-1.5 text-sm font-medium {{ $templateConfigured ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-400 cursor-not-allowed' }}">
                Bestätigungen senden
            </button>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Datum</th>
                    <th class="px-4 py-2 font-medium">Zeit</th>
                    <th class="px-4 py-2 font-medium">Tätigkeit</th>
                    <th class="px-4 py-2 font-medium">Mitarbeiter</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                    <th class="px-4 py-2 font-medium">Bestätigung</th>
                    <th class="px-4 py-2 font-medium">Hinweis</th>
                    <th class="px-4 py-2 font-medium">Anhang</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($event->assignments as $assignment)
                    <tr class="{{ $assignment->missing_since ? 'opacity-50' : '' }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $assignment->datum->format('d.m.Y') }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $assignment->von ?? '—' }}@if ($assignment->bis)–{{ $assignment->bis }}@endif</td>
                        <td class="px-4 py-2">{{ $assignment->taetigkeit ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($assignment->employee)
                                <a href="{{ route('recruiting.employees.show', $assignment->employee->id) }}" class="text-blue-600 hover:underline">
                                    {{ $assignment->employee->first_name }} {{ $assignment->employee->last_name }}
                                </a>
                            @else
                                <span class="rounded bg-orange-50 px-1.5 py-0.5 text-xs text-orange-600">PNr unbekannt: {{ $assignment->pnr_raw }}</span>
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
                            @php
                                $msgStatus = $assignment->reminderMessage?->status;
                                $escalation1Status = $assignment->escalation1Message?->status;
                                $escalation2Status = $assignment->escalation2Message?->status;
                            @endphp
                            @if ($assignment->deletion_marked_at)
                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">zur Löschung gemeldet</span>
                            @elseif ($assignment->confirmed_at)
                                <span class="rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800" title="{{ $assignment->confirmed_at->format('d.m.Y H:i') }}">✓ bestätigt</span>
                            @elseif ($assignment->reminder_sent_at)
                                <span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700" title="Gesendet {{ $assignment->reminder_sent_at->format('d.m.Y H:i') }}">angeschrieben</span>
                                @if ($msgStatus === 'failed')
                                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">nicht zugestellt</span>
                                @elseif (in_array($msgStatus, ['delivered', 'read'], true))
                                    <span class="ml-1 rounded bg-green-50 px-1.5 py-0.5 text-xs text-green-700">{{ $msgStatus === 'read' ? 'gelesen' : 'zugestellt' }}</span>
                                @endif
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                            @if ($escalation1Status === 'failed')
                                <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">14-Uhr nicht zugestellt</span>
                            @endif
                            @if ($escalation2Status === 'failed')
                                <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">15-Uhr nicht zugestellt</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($assignment->rec_employee_id)
                                @php $note = trim($notes[$assignment->rec_employee_id] ?? ''); @endphp
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
                        </td>
                        <td class="px-4 py-2">
                            @if ($assignment->rec_employee_id)
                                @php $att = $this->attachmentsByEmployee[$assignment->rec_employee_id] ?? null; @endphp
                                @if ($att)
                                    <div class="flex items-center gap-2 text-xs">
                                        <a href="{{ route('recruiting.dispo.attachments.download', ['uuid' => $att->uuid]) }}" target="_blank" rel="noopener"
                                           class="max-w-[12rem] truncate text-blue-600 hover:underline" title="{{ $att->original_filename }}">📎 {{ $att->original_filename }}</a>
                                        <button type="button" wire:click="openAttachment({{ $assignment->rec_employee_id }})" class="text-gray-400 hover:text-blue-600" title="Ersetzen">✎</button>
                                        <button type="button" wire:click="removeAttachment({{ $assignment->rec_employee_id }})" wire:confirm="Anhang entfernen?" class="text-gray-400 hover:text-red-600" title="Entfernen">✕</button>
                                    </div>
                                @else
                                    <button type="button" wire:click="openAttachment({{ $assignment->rec_employee_id }})" class="text-xs text-gray-400 hover:text-blue-600">+ Anhang</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">Keine Einbuchungen.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showSendModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" wire:click.self="$set('showSendModal', false)">
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
                        $escSummary = ($escDay === 'einsatztag' ? 'Einsatztag' : 'Vortag') . ' · '
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
                        <button wire:click="sendConfirmations" @if (count($preview['recipients']) === 0) disabled @endif
                                class="rounded px-4 py-2 text-sm font-medium {{ count($preview['recipients']) > 0 ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-400 cursor-not-allowed' }}">
                            Jetzt senden
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
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" wire:click.self="closeNoteModal">
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
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" wire:click.self="closeAttachmentModal">
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
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" wire:click.self="$set('showContactModal', false)">
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
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" wire:click.self="$set('showEscalationModal', false)">
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
</div>
