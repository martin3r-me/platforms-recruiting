<div class="p-6 space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Wäschepakete</h1>
        <p class="mt-1 text-sm text-gray-500">Der Inhalt steht wortwörtlich auf der Einsatz-Seite des Mitarbeiters. Den Namen sieht er nie — er ist nur eure Auswahlhilfe.</p>
    </div>

    @php
        $lastItemIndex = count($items) - 1;
        $suggestions = $this->itemSuggestions;
    @endphp
    <div class="rounded-lg border border-gray-200 p-4">
        <div class="font-medium text-gray-700 mb-3">{{ $editingId ? 'Paket bearbeiten' : 'Neues Paket' }}</div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="space-y-3 lg:col-span-2">
                <label class="block text-sm">
                    <span class="mb-1 block text-gray-600">Name (intern)</span>
                    <input type="text" wire:model="name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </label>
                @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="block text-sm">
                    <span class="mb-1 block text-gray-600">Kleidung</span>
                    <div class="flex flex-wrap gap-2 rounded-lg border border-gray-300 p-2 min-h-[2.75rem]">
                        @forelse ($items as $i => $item)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700">
                                <button type="button" wire:click="moveItem({{ $i }}, -1)" @if ($i === 0) disabled @endif
                                        class="text-gray-400 hover:text-gray-700 disabled:opacity-30 disabled:hover:text-gray-400" title="nach oben">↑</button>
                                <button type="button" wire:click="moveItem({{ $i }}, 1)" @if ($i === $lastItemIndex) disabled @endif
                                        class="text-gray-400 hover:text-gray-700 disabled:opacity-30 disabled:hover:text-gray-400" title="nach unten">↓</button>
                                <span>{{ $item }}</span>
                                <button type="button" wire:click="removeItem({{ $i }})" class="text-gray-400 hover:text-red-600" title="entfernen">×</button>
                            </span>
                        @empty
                            <span class="text-xs text-gray-400">Noch keine Teile.</span>
                        @endforelse
                    </div>
                </label>
                @error('items') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="flex items-center gap-2">
                    <input type="text" wire:model.live.debounce.300ms="newItem" wire:keydown.enter.prevent="addItem"
                           placeholder="Teil eingeben, Enter zum Hinzufügen"
                           class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <button type="button" wire:click="addItem" class="rounded border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Hinzufügen</button>
                </div>
                @error('newItem') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                @if (count($suggestions) > 0)
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="mr-1 text-xs text-gray-400">Vorschläge:</span>
                        @foreach ($suggestions as $s)
                            <button type="button" wire:click="addSuggestion(@js($s))"
                                    class="rounded-full border border-gray-200 px-2.5 py-1 text-xs text-gray-600 hover:bg-gray-50">{{ $s }}</button>
                        @endforeach
                    </div>
                @endif

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

            <div class="lg:col-span-1">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <div class="text-xs font-medium text-gray-500">So sieht es der Mitarbeiter</div>
                    <div class="mt-2 rounded-md border border-gray-200 bg-white p-3">
                        <div class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">Deine Kleidung</div>
                        <div class="mt-1 text-sm leading-snug whitespace-pre-line">{{ implode('; ', $items) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        $usage = $this->usageCounts;
        $packagesList = $this->packages;
        $lastPackageIndex = count($packagesList) - 1;
    @endphp
    <div class="overflow-hidden rounded-lg border border-gray-200">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-2 py-2"></th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Kleidung</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Verwendung</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($packagesList as $pIndex => $package)
                    @php
                        $count = $usage[$package->id] ?? 0;
                        $confirming = $confirmingRetire[$package->id] ?? false;
                    @endphp
                    <tr class="{{ $package->is_active ? '' : 'text-gray-400' }}">
                        <td class="px-2 py-2 whitespace-nowrap">
                            <button type="button" wire:click="moveUp({{ $package->id }})" @if ($pIndex === 0) disabled @endif
                                    class="text-gray-400 hover:text-gray-700 disabled:opacity-30 disabled:hover:text-gray-400" title="nach oben">↑</button>
                            <button type="button" wire:click="moveDown({{ $package->id }})" @if ($pIndex === $lastPackageIndex) disabled @endif
                                    class="text-gray-400 hover:text-gray-700 disabled:opacity-30 disabled:hover:text-gray-400" title="nach unten">↓</button>
                        </td>
                        <td class="px-4 py-2 font-medium">{{ $package->name }}</td>
                        <td class="px-4 py-2">{{ $package->items_text }}</td>
                        <td class="px-4 py-2">{{ $package->is_active ? 'aktiv' : 'ausgemustert' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">
                            @if ($count > 0)
                                in {{ $count }} {{ $count === 1 ? 'Veranstaltung' : 'Veranstaltungen' }} im Einsatz
                            @else
                                nicht zugeordnet
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if ($confirming)
                                <span class="text-xs text-amber-700">Dieses Paket ist in {{ $count }} Veranstaltungen zugeordnet. Die Zuordnungen bleiben bestehen, das Paket verschwindet nur aus der Auswahl.</span>
                                <button wire:click="confirmRetire({{ $package->id }})" class="ml-2 text-xs text-red-600 hover:underline">Ja, ausmustern</button>
                                <button wire:click="cancelRetire({{ $package->id }})" class="ml-2 text-xs text-gray-500 hover:underline">Abbrechen</button>
                            @else
                                <button wire:click="edit({{ $package->id }})" class="text-xs text-blue-600 hover:underline">bearbeiten</button>
                                <button wire:click="duplicate({{ $package->id }})" class="ml-3 text-xs text-gray-600 hover:underline">duplizieren</button>
                                @if ($package->is_active)
                                    <button wire:click="requestRetire({{ $package->id }})" class="ml-3 text-xs text-gray-500 hover:underline">ausmustern</button>
                                @else
                                    <button wire:click="toggleActive({{ $package->id }})" class="ml-3 text-xs text-gray-500 hover:underline">wieder aktivieren</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Noch keine Pakete angelegt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
