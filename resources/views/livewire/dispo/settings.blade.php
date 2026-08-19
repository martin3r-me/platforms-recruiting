<div class="p-6 max-w-2xl space-y-6">
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
            <span class="mb-1 block text-gray-600">Ansprechpartner (Name, optional mit Telefon)</span>
            <input type="text" wire:model="contactLine" placeholder="z. B. Sheran (0170 1234567)" class="w-full rounded-lg border border-gray-300 px-3 py-2">
            <span class="mt-1 block text-xs text-gray-400">Erscheint auf der Einsatz-Seite als „Dein Ansprechpartner ist …".</span>
        </label>

        <label class="block text-sm">
            <span class="mb-1 block text-gray-600">Bestätigungs-Deadline (Stunden vor Einsatzbeginn)</span>
            <input type="number" min="1" max="72" wire:model="deadlineHours" class="w-32 rounded-lg border border-gray-300 px-3 py-2">
        </label>

        <div class="flex items-center gap-3">
            <button wire:click="save" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
            @if ($saved)
                <span class="text-sm text-green-600">✓ Gespeichert</span>
            @endif
        </div>
    </div>
</div>
