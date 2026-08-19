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
            <h1 class="text-xl font-semibold">{{ $event->name ?? $event->einsatz_ref }}</h1>
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
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm font-medium text-gray-500">Ort / Venue</div>
            <div class="mt-1 whitespace-pre-line text-sm">{{ $event->venue_text ?? $event->ort ?? '—' }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm font-medium text-gray-500">Anfahrt / Dresscode</div>
            <div class="mt-1 whitespace-pre-line text-sm">{{ $event->anfahrt ?? 'Anfahrt: folgt von ZAS' }}</div>
            <div class="mt-1 whitespace-pre-line text-sm">{{ $event->dresscode ?? 'Dresscode: folgt von ZAS' }}</div>
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
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">Keine Einbuchungen.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showSendModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" wire:click.self="$set('showSendModal', false)">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Bestätigungen senden</h2>

                @if ($sendResult === null)
                    @php $preview = $this->sendPreview; @endphp
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700">Vorlaufzeit <span class="text-red-600">*</span></span>
                        <div class="flex items-center gap-2">
                            <input type="number" min="0" max="480" wire:model="vorlaufMinuten" placeholder="z. B. 30"
                                   class="w-28 rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-blue-500 focus:ring-blue-500">
                            <span class="text-sm text-gray-600">Minuten vor Dienstbeginn</span>
                        </div>
                        <span class="mt-1 block text-xs text-gray-500">Steht in der WhatsApp („Check in X min vor Dienstbeginn") und bestimmt die „Bitte sei um … Uhr da"-Zeit auf der Einsatz-Seite.</span>
                    </label>
                    @error('vorlaufMinuten') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700">Ansprechpartner vor Ort <span class="text-gray-400">(optional)</span></span>
                        <input type="text" wire:model="ansprechpartner" placeholder="z. B. Sheran (0170 1234567)"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <span class="mt-1 block text-xs text-gray-500">Erscheint auf der Einsatz-Seite als „Dein Ansprechpartner ist …".</span>
                    </label>

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
</div>
