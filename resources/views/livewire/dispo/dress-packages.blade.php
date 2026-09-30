<div class="p-6 space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Wäschepakete</h1>
        <p class="mt-1 text-sm text-gray-500">Der Inhalt steht wortwörtlich auf der Einsatz-Seite des Mitarbeiters. Den Namen sieht er nie — er ist nur eure Auswahlhilfe.</p>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 space-y-3">
        <div class="font-medium text-gray-700">{{ $editingId ? 'Paket bearbeiten' : 'Neues Paket' }}</div>
        <label class="block text-sm">
            <span class="mb-1 block text-gray-600">Name (intern)</span>
            <input type="text" wire:model="name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        </label>
        @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        <label class="block text-sm">
            <span class="mb-1 block text-gray-600">Kleidung (mit Semikolon trennen)</span>
            <textarea wire:model="itemsText" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        </label>
        @error('itemsText') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        <div class="flex items-center gap-2">
            <button wire:click="save" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Speichern</button>
            @if ($editingId)
                <button wire:click="cancel" class="rounded px-3 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
            @endif
            @if ($saved)
                <span class="text-xs text-green-600">✓ gespeichert</span>
            @endif
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Kleidung</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($this->packages as $package)
                    <tr class="{{ $package->is_active ? '' : 'text-gray-400' }}">
                        <td class="px-4 py-2 font-medium">{{ $package->name }}</td>
                        <td class="px-4 py-2">{{ $package->items_text }}</td>
                        <td class="px-4 py-2">{{ $package->is_active ? 'aktiv' : 'ausgemustert' }}</td>
                        <td class="px-4 py-2 text-right">
                            <button wire:click="edit({{ $package->id }})" class="text-xs text-blue-600 hover:underline">bearbeiten</button>
                            <button wire:click="toggleActive({{ $package->id }})" class="ml-3 text-xs text-gray-500 hover:underline">{{ $package->is_active ? 'ausmustern' : 'wieder aktivieren' }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">Noch keine Pakete angelegt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
