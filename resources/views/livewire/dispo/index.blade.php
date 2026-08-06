<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">ZAS-Eingang</h1>
        <span class="text-sm text-gray-500">Eingegangene Dispo-Dateien (Veranstaltungen + eingebuchtes Personal)</span>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Eingang</th>
                    <th class="px-4 py-2 font-medium">Datei</th>
                    <th class="px-4 py-2 font-medium">Format</th>
                    <th class="px-4 py-2 font-medium">Größe</th>
                    <th class="px-4 py-2 font-medium">Zeilen</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($files as $file)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $file->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-2">
                            {{ $file->original_filename ?: '(Raw-Body)' }}
                            @if ($file->is_test)
                                <span class="ml-1 rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-800">Test</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $file->detected_format ?: 'unbekannt' }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($file->size_bytes / 1024, 1, ',', '.') }} KB</td>
                        <td class="px-4 py-2">{{ $file->row_count !== null ? number_format($file->row_count, 0, ',', '.') : '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($file->parse_status === 'viewable')
                                <span class="rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800">lesbar</span>
                            @else
                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">{{ $file->parse_status }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('recruiting.dispo.show', ['fileId' => $file->id]) }}" class="text-blue-600 hover:underline">Ansehen</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                            Noch keine Dateien eingegangen. ZAS pusht an <code>POST /recruiting/zas/dispo-inbound</code>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
