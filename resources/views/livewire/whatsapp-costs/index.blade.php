<div class="p-6 space-y-6">
    @php
        $report = $this->report;
        $fmt = fn (float $v) => number_format($v, 2, ',', '.') . ' ' . $report->currency;
    @endphp

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">WhatsApp-Kosten</h1>
        <span class="text-sm text-gray-500">Geschätzte Kosten zugestellter Templates</span>
    </div>

    {{-- Filter --}}
    <div class="flex flex-wrap items-end gap-4 rounded-lg border border-gray-200 bg-white p-4">
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Von</span>
            <input type="date" wire:model.live="from" class="rounded border-gray-300">
        </label>
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Bis</span>
            <input type="date" wire:model.live="to" class="rounded border-gray-300">
        </label>
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Typ</span>
            <select wire:model.live="type" class="rounded border-gray-300">
                <option value="all">Alle</option>
                <option value="manual">Manuell</option>
                <option value="automatic">Automatisch (System)</option>
            </select>
        </label>
    </div>

    {{-- Kennzahlen --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">Zugestellte Templates</div>
            <div class="mt-1 text-2xl font-semibold">{{ $report->totalCount }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">Geschätzte Kosten gesamt</div>
            <div class="mt-1 text-2xl font-semibold">{{ $fmt($report->totalCost) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">davon manuell</div>
            <div class="mt-1 text-2xl font-semibold">{{ $report->manualCount }}</div>
            <div class="text-sm text-gray-500">{{ $fmt($report->manualCost) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">davon automatisch (System)</div>
            <div class="mt-1 text-2xl font-semibold">{{ $report->automaticCount }}</div>
            <div class="text-sm text-gray-500">{{ $fmt($report->automaticCost) }}</div>
        </div>
    </div>

    {{-- Breakdown --}}
    <div class="rounded-lg border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-4 py-3 font-medium">Aufschlüsselung pro Template</div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Template</th>
                    <th class="px-4 py-2 font-medium text-right">Anzahl</th>
                    <th class="px-4 py-2 font-medium text-right">Geschätzte Kosten</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report->templates as $tpl)
                    <tr class="border-t border-gray-50">
                        <td class="px-4 py-2">{{ $tpl->templateName }}</td>
                        <td class="px-4 py-2 text-right">{{ $tpl->count }}</td>
                        <td class="px-4 py-2 text-right">{{ $fmt($tpl->cost) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-gray-400">
                            Keine zugestellten Templates im gewählten Zeitraum.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-400">
        Kosten sind geschätzt (Anzahl × konfigurierter Preis je Template). Utility-Templates
        innerhalb eines offenen 24-Stunden-Service-Fensters sind bei Meta kostenfrei und
        können hier leicht überschätzt werden.
    </p>
</div>
