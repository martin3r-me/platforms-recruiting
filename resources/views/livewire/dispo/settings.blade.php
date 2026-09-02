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
            <span class="mt-1 block text-xs text-gray-400">Der Sende-Kanal ergibt sich aus der Filial-Zuordnung unten, sonst aus dem WhatsApp-Account dieses Templates (Dispo-Nummer).</span>
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

    <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2">
        <h2 class="text-base font-semibold">Zugriff „Nur Veranstaltungen"</h2>
        <p class="text-sm text-gray-600">
            Konten auf dieser Liste (z. B. Teamleiter wie <span class="font-mono text-xs">event@rheingedeck.de</span>) sehen im Recruiting ausschließlich
            Disposition → Veranstaltungen: VA-Liste und VA-Seite lesend, Chat mit Antworten und Vorlagen. Kein Dashboard, keine Bewerber, keine Mitarbeiter-Akten, keine Einstellungen.
        </p>
        <label class="block text-sm">
            <span class="mb-1 block font-medium text-gray-700">E-Mail-Adressen (eine pro Zeile)</span>
            <textarea wire:model="eventOnlyEmails" rows="3" placeholder="event@rheingedeck.de"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
        </label>
        <p class="text-xs text-gray-500">Wird mit dem Speichern-Button oben übernommen. Gilt ab dem nächsten Seitenaufruf des Kontos.</p>
    </div>

    @php
        $linkReport = $this->contactLinkReport;
        $linkSkips = collect($linkReport['rows'])->where('state', 'skip')->count();
        // Der Zähler zählt nur die (ggf. gekappten) angezeigten Zeilen — bei Kappung
        // koennen weitere Skips ausserhalb der Anzeige liegen, daher "mindestens".
        $linkSkipsCapped = $linkReport['total'] > count($linkReport['rows']);
        $linkSkipsLabel = $linkSkipsCapped ? ('mindestens ' . $linkSkips) : (string) $linkSkips;
        // Bei "mindestens n" ist die Zahl eine Untergrenze, also immer Plural —
        // ausser sie steht fuer sich und ist genau 1.
        $linkSkipsSentence = (!$linkSkipsCapped && $linkSkips === 1)
            ? ($linkSkipsLabel . ' Fall braucht')
            : ($linkSkipsLabel . ' Fälle brauchen');
    @endphp
    <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold">CRM-Zuordnung offen ({{ $linkReport['total'] }})</h2>
            <span class="text-xs text-gray-500">Der Abgleich läuft stündlich automatisch.</span>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="contactBackfillEnabled" class="rounded border-gray-300">
            <span class="text-gray-700">Automatischer CRM-Abgleich (stündlich) — legt fehlende Kontakte automatisch an</span>
        </label>
        <p class="text-xs text-gray-400 -mt-2">Wird mit dem Speichern-Button oben übernommen. Ausgeschaltet läuft der stündliche Lauf als No-op; ein Aufruf von Hand bleibt möglich.</p>
        <p class="text-sm text-gray-600">
            Mitarbeiter ohne verknüpften CRM-Kontakt erscheinen in der Kommunikation nur mit Telefonnummer und werden bei zwei Personalnummern nicht als eine Person erkannt.
            <strong>{{ $linkSkipsSentence }}</strong> eine manuelle Zuordnung in der MA-Akte.
        </p>
        @if ($linkReport['rows'] === [])
            <div class="rounded bg-green-50 p-3 text-sm text-green-800">Alle aktiven Mitarbeiter haben einen CRM-Kontakt.</div>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="px-2 py-1 font-medium">Mitarbeiter</th>
                        <th class="px-2 py-1 font-medium">PNr</th>
                        <th class="px-2 py-1 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($linkReport['rows'] as $row)
                        <tr>
                            <td class="px-2 py-1.5">
                                <a href="{{ route('recruiting.employees.show', $row['employee_id']) }}" class="text-blue-600 hover:underline">{{ $row['name'] !== '' ? $row['name'] : ('#' . $row['employee_id']) }}</a>
                            </td>
                            <td class="px-2 py-1.5 tabular-nums text-gray-600">{{ $row['personnel_number'] !== '' ? $row['personnel_number'] : '—' }}</td>
                            <td class="px-2 py-1.5">
                                @if ($row['state'] === 'skip')
                                    <span class="rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">manuell zuordnen</span>
                                @else
                                    <span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700">automatisch</span>
                                @endif
                                <span class="ml-1 text-xs text-gray-500">{{ $row['reason'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($linkReport['total'] > count($linkReport['rows']))
                <div class="text-xs text-gray-500">Zeigt die ersten {{ count($linkReport['rows']) }} von {{ $linkReport['total'] }}.</div>
            @endif
        @endif
    </div>
</div>
