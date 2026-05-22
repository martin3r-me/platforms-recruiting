<div class="p-6 max-w-7xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold mb-1">Test-Dashboard</h1>
        <p class="text-sm text-gray-600">
            Sandbox-Positionen ({{ $sandboxPositions->count() }}):
            @forelse($sandboxPositions as $pos)
                <span class="inline-block bg-gray-100 rounded px-2 py-0.5 mr-1">
                    #{{ $pos->id }} {{ $pos->title }}
                    @if($pos->beschaftigungsort_lookup_value)
                        <span class="text-blue-600">({{ $pos->beschaftigungsort_lookup_value }})</span>
                    @endif
                </span>
            @empty
                <span class="text-gray-400">— keine Sandbox-Positionen gefunden</span>
            @endforelse
        </p>
        <p class="text-sm text-gray-500 mt-2">{{ $totalCount }} Bewerber gesamt</p>
    </div>

    @foreach($byPhase as $order => $row)
        @php
            $phase = $row['phase'];
            $phaseApplicants = $row['applicants'];
            $count = $phaseApplicants->count();
        @endphp
        <div class="mb-6 border {{ $phase->is_active ? 'border-gray-200' : 'border-gray-200 opacity-70' }} rounded-lg overflow-hidden">
            <div class="bg-gray-100 px-4 py-2 font-semibold flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="inline-block bg-blue-600 text-white text-xs rounded-full w-5 h-5 leading-5 text-center">{{ $order }}</span>
                    <span>{{ $phase->name }}</span>
                    @if(!$phase->is_active)
                        <span class="text-[10px] uppercase tracking-wide text-gray-500 bg-gray-200 px-1.5 py-0.5 rounded">inaktiv</span>
                    @endif
                    @if(($phase->completion_config['creates_employee_on_completion'] ?? false) === true)
                        <span class="text-[10px] uppercase tracking-wide text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded" title="Beim Abschluss dieser Phase wird der Bewerber zum Mitarbeiter">→ MA</span>
                    @endif
                </div>
                <div class="text-sm text-gray-600">{{ $count }} {{ $count === 1 ? 'Bewerber' : 'Bewerber' }}</div>
            </div>
            @if($count > 0)
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left">ID</th>
                            <th class="px-3 py-2 text-left">Name</th>
                            <th class="px-3 py-2 text-left">Position</th>
                            <th class="px-3 py-2 text-left">Progress</th>
                            <th class="px-3 py-2 text-left">AutoPilot</th>
                            <th class="px-3 py-2 text-left">Erstellt</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($phaseApplicants as $applicant)
                            @php
                                $contact = $applicant->crmContactLinks->first()?->contact;
                                $name = $contact?->full_name ?: "(#{$applicant->id})";
                                $position = $applicant->postings->first()?->position;
                            @endphp
                            <tr class="border-t border-gray-100 hover:bg-gray-50">
                                <td class="px-3 py-2 text-gray-500">#{{ $applicant->id }}</td>
                                <td class="px-3 py-2 font-medium">{{ $name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $position?->title ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="w-16 bg-gray-200 rounded-full h-2 overflow-hidden">
                                            <div class="bg-blue-600 h-2" style="width: {{ $applicant->progress }}%"></div>
                                        </div>
                                        <span class="text-xs text-gray-600">{{ $applicant->progress }}%</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    @if($applicant->auto_pilot_completed_at)
                                        <span class="text-green-600">✓ abgeschlossen</span>
                                    @elseif($applicant->auto_pilot)
                                        <span class="text-blue-600">🔄 aktiv</span>
                                    @else
                                        <span class="text-gray-400">⏸ aus</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-500 text-xs">{{ $applicant->created_at?->format('d.m. H:i') }}</td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('recruiting.applicants.show', $applicant->id) }}"
                                       class="text-blue-600 hover:underline text-xs">Anzeigen →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-4 py-4 text-xs text-gray-400 italic">Keine Bewerber in dieser Phase.</div>
            @endif
        </div>
    @endforeach

    @if($unassigned->count() > 0)
        <div class="mb-6 border border-orange-200 rounded-lg overflow-hidden">
            <div class="bg-orange-50 px-4 py-2 font-semibold flex items-center justify-between">
                <span class="text-orange-700">Ohne Phase</span>
                <div class="text-sm text-orange-700">{{ $unassigned->count() }} Bewerber</div>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    @foreach($unassigned as $applicant)
                        @php
                            $contact = $applicant->crmContactLinks->first()?->contact;
                            $name = $contact?->full_name ?: "(#{$applicant->id})";
                            $position = $applicant->postings->first()?->position;
                        @endphp
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-3 py-2 text-gray-500">#{{ $applicant->id }}</td>
                            <td class="px-3 py-2 font-medium">{{ $name }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $position?->title ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('recruiting.applicants.show', $applicant->id) }}"
                                   class="text-blue-600 hover:underline text-xs">Anzeigen →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($totalCount === 0 && empty($byPhase))
        <div class="text-center py-12 text-gray-500 border border-dashed border-gray-300 rounded-lg">
            Noch keine Bewerber in den Sandbox-Positionen.
            <p class="text-xs mt-1">Lege per MCP einen Test-Bewerber an um den Flow zu starten.</p>
        </div>
    @endif
</div>
