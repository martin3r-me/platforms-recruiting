<div class="p-6 space-y-6">
    @php
        $file = $this->file;
        $parsed = $this->parsed;
    @endphp

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">{{ $file->original_filename ?: 'Dispo-Datei #' . $file->id }}</h1>
            <p class="text-sm text-gray-500">
                Eingang {{ $file->created_at->format('d.m.Y H:i') }} ·
                {{ number_format($file->size_bytes / 1024, 1, ',', '.') }} KB ·
                Format {{ $file->detected_format ?: 'unbekannt' }}
                @if ($file->is_test) · Test-Lieferung @endif
            </p>
        </div>
        <a href="{{ route('recruiting.dispo.index') }}" class="text-sm text-blue-600 hover:underline">← Zurück zur Liste</a>
    </div>

    @if ($parsed['format'] === 'csv')
        {{-- Spaltenübersicht: rechnet über die GANZE Datei --}}
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium">
                Spaltenübersicht ({{ count($parsed['columns']) }} Spalten, {{ number_format($parsed['row_count'], 0, ',', '.') }} Zeilen)
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Spalte</th>
                            <th class="px-4 py-2 font-medium">Füllgrad</th>
                            <th class="px-4 py-2 font-medium">Beispielwerte</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($parsed['profile'] as $col)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $col['column'] }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ $col['filled'] }} / {{ number_format($parsed['row_count'], 0, ',', '.') }}
                                    ({{ number_format($col['fill_ratio'] * 100, 1, ',', '.') }} %)
                                </td>
                                <td class="px-4 py-2 text-gray-600">{{ implode(' · ', $col['examples']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Datentabelle: Row-Cap, damit fünfstellige Bestände die Seite nicht sprengen --}}
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium">
                Daten
                @if ($parsed['row_count'] > count($parsed['rows']))
                    <span class="ml-2 text-sm font-normal text-gray-500">
                        Zeige {{ count($parsed['rows']) }} von {{ number_format($parsed['row_count'], 0, ',', '.') }} Zeilen
                    </span>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            @foreach ($parsed['columns'] as $column)
                                <th class="px-3 py-2 font-medium whitespace-nowrap">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($parsed['rows'] as $row)
                            <tr>
                                @foreach ($parsed['columns'] as $column)
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $row[$column] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($parsed['format'] === 'json')
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium">JSON-Inhalt</div>
            <pre class="overflow-x-auto p-4 text-xs">{{ $parsed['pretty'] }}</pre>
        </div>
    @elseif ($parsed['format'] === 'missing')
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium text-red-800">Datei nicht verfügbar</div>
            <div class="p-4 text-sm text-gray-600">Rohdatei nicht mehr auf dem Storage vorhanden (Disk/Pfad siehe Metadaten oben).</div>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium">Roh-Ansicht (Format nicht erkannt, erste 20.000 Zeichen)</div>
            <pre class="overflow-x-auto p-4 text-xs whitespace-pre-wrap">{{ $parsed['raw_excerpt'] }}</pre>
        </div>
    @endif
</div>
