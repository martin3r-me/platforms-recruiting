<div>
    @if ($this->declines !== [])
        <div class="mb-6 rounded-lg border border-orange-200 bg-white">
            <div class="border-b border-orange-100 bg-orange-50 px-4 py-2.5 text-sm font-semibold text-orange-800">
                Dispo-Absagen ({{ count($this->declines) }})
            </div>
            <div class="divide-y divide-gray-100">
                @foreach ($this->declines as $d)
                    <div class="flex flex-wrap items-start gap-x-4 gap-y-2 px-4 py-3">
                        <div class="min-w-[14rem]">
                            <div class="text-sm font-semibold text-gray-900">{{ $d['name'] }}
                                @if ($d['pnr'] !== '')
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600 tabular-nums">{{ $d['pnr'] }}</span>
                                @endif
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500">{{ $d['event_label'] }} · {{ implode(', ', $d['days']) }}</div>
                        </div>
                        <div class="min-w-0 flex-1 text-sm">
                            <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700">{{ $d['reason'] === 'krank' ? 'krank gemeldet' : 'abgesagt' }}</span>
                            @if ($d['locked'])
                                <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">Portal gesperrt</span>
                            @endif
                            <span class="ml-1 text-xs text-gray-400">{{ $d['declined_at'] }}</span>
                            @if (trim((string) $d['note']) !== '')
                                <div class="mt-1 whitespace-pre-line text-xs text-gray-700">{{ $d['note'] }}</div>
                            @endif
                        </div>
                        <button type="button" wire:click="markDone({{ $d['event_id'] }}, {{ $d['employee_id'] }})"
                                class="rounded border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                            Erledigt
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
