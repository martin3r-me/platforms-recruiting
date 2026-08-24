<div class="p-6 max-w-4xl space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Dispo-Einstellungen</h1>
        <p class="text-sm text-gray-500">Konfiguration des Bestätigungs-Versands — getrennt von den Bewerber-Einstellungen.</p>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-4">
        <label class="block text-sm">
            <span class="mb-1 block text-gray-600">Bestätigungs-Template (WhatsApp, freigegeben)</span>
            <select wire:model="templateId" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                <option value="">— kein Template gewählt (Sende-Button deaktiviert) —</option>
                @foreach ($this->templates as $template)
                    <option value="{{ $template['id'] }}">{{ $template['name'] }} (#{{ $template['id'] }})</option>
                @endforeach
            </select>
            <span class="mt-1 block text-xs text-gray-400">Der Sende-Kanal ergibt sich aus dem WhatsApp-Account des Templates (Dispo-Nummer).</span>
        </label>

        <label class="block text-sm">
            <span class="mb-1 block text-gray-600">Bestätigungs-Deadline (Stunden vor Einsatzbeginn)</span>
            <input type="number" min="1" max="72" wire:model="deadlineHours" class="w-32 rounded-lg border border-gray-300 px-3 py-2">
        </label>

        <div class="border-t border-gray-200 pt-4 space-y-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="escalationEnabled" class="rounded border-gray-300">
                <span class="text-gray-700 font-medium">Eskalation aktiv</span>
            </label>
            <p class="text-xs text-gray-400 -mt-2">Ohne diesen Schalter laeuft der Eskalations-Command als No-op.</p>

            <div class="grid grid-cols-3 gap-4">
                <label class="block text-sm">
                    <span class="mb-1 block text-gray-600">Stufe 1 (Uhrzeit)</span>
                    <input type="time" wire:model="escalationTime1" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-gray-600">Stufe 2 (Uhrzeit)</span>
                    <input type="time" wire:model="escalationTime2" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-gray-600">Stufe 3 (Uhrzeit, Alarm)</span>
                    <input type="time" wire:model="escalationTime3" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                </label>
            </div>

            <label class="block text-sm">
                <span class="mb-1 block text-gray-600">Eskalations-Template Stufe 1 (WhatsApp, freigegeben)</span>
                <select wire:model="escalationTemplate1Id" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                    <option value="">— kein Template gewählt —</option>
                    @foreach ($this->templates as $template)
                        <option value="{{ $template['id'] }}">{{ $template['name'] }} (#{{ $template['id'] }})</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block text-gray-600">Eskalations-Template Stufe 2 (WhatsApp, freigegeben)</span>
                <select wire:model="escalationTemplate2Id" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                    <option value="">— kein Template gewählt —</option>
                    @foreach ($this->templates as $template)
                        <option value="{{ $template['id'] }}">{{ $template['name'] }} (#{{ $template['id'] }})</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block text-gray-600">Alarm-Template Stufe 3 (WhatsApp, freigegeben)</span>
                <select wire:model="alarmTemplateId" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                    <option value="">— kein Template gewählt —</option>
                    @foreach ($this->templates as $template)
                        <option value="{{ $template['id'] }}">{{ $template['name'] }} (#{{ $template['id'] }})</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs text-gray-400">Stufe 3 markiert die Einbuchung als storniert und sperrt das Portal — das Alarm-Template ist die Info dazu (Kanal wie Stufe 1/2).</span>
            </label>
        </div>

        <div class="flex items-center gap-3">
            <button wire:click="save" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
            @if ($saved)
                <span class="text-sm text-green-600">✓ Gespeichert</span>
            @endif
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-4">
        <div>
            <h2 class="text-base font-semibold">Filial-Konfiguration</h2>
            <p class="text-sm text-gray-500">Versand-Kanal und Diensthandy je Filiale — überschreibt den Default-Kanal aus dem Bestätigungs-Template. Jede Zeile wird einzeln gespeichert.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-2 pr-4">Filiale</th>
                        <th class="pb-2 pr-4">Kanal (WhatsApp)</th>
                        <th class="pb-2 pr-4">Diensthandy</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->filialen as $nr => $code)
                        <tr class="border-t border-gray-100">
                            <td class="py-2 pr-4 font-medium text-gray-700">{{ $code }} <span class="text-gray-400">(#{{ $nr }})</span></td>
                            <td class="py-2 pr-4">
                                <select wire:model="filialeChannelId.{{ $nr }}" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                    <option value="">— kein Kanal (nutzt Default) —</option>
                                    @foreach ($this->whatsappChannels as $channel)
                                        <option value="{{ $channel['id'] }}">{{ $channel['name'] }} (#{{ $channel['id'] }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-2 pr-4">
                                <input type="text" wire:model="filialeDutyPhone.{{ $nr }}" placeholder="z. B. 0170 1234567" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                            </td>
                            <td class="py-2">
                                <button wire:click="saveFiliale({{ $nr }})" class="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">Speichern</button>
                                @if ($savedFilialNr === $nr)
                                    <span class="ml-2 text-xs text-green-600">✓</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
