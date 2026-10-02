<div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[360px_1fr]" wire:poll.30s>
    @php
        $rows = $this->rows;
        $sel = $this->selected;
    @endphp

    {{-- Liste --}}
    <div class="{{ $sel ? 'hidden lg:flex' : 'flex' }} min-h-0 flex-col border-r border-gray-200 bg-white">
        <div class="min-h-0 flex-1 overflow-y-auto">
            @forelse ($rows as $row)
                <button type="button" wire:click="select({{ $row['id'] }})"
                        class="block w-full border-b border-gray-100 border-l-[3px] px-4 py-3 text-left hover:bg-gray-50 {{ ($sel['id'] ?? null) === $row['id'] ? 'border-l-blue-600 bg-blue-50/60' : 'border-l-transparent' }}">
                    <span class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-semibold text-gray-900">{{ $row['name'] }}</span>
                        <span class="shrink-0 text-[11px] text-gray-400 tabular-nums">{{ $row['forwarded_at'] }}</span>
                    </span>
                    <span class="mt-0.5 block truncate text-xs text-gray-500">{{ $row['preview'] }}</span>
                    <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        @if ($row['pnr'] !== '')
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600 tabular-nums">{{ $row['pnr'] }}</span>
                        @endif
                        @if ($row['by'] !== '')
                            <span class="text-[10.5px] text-gray-400">von {{ $row['by'] }}</span>
                        @endif
                        @if ($row['first_contact'])
                            <span class="rounded bg-green-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-green-700">angeschrieben</span>
                        @else
                            <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-violet-700">neu</span>
                        @endif
                    </span>
                </button>
            @empty
                <div class="p-8 text-center text-sm text-gray-500">Keine offenen Weiterleitungen aus der Dispo.</div>
            @endforelse
        </div>
    </div>

    {{-- Detail --}}
    <div class="{{ $sel ? 'flex' : 'hidden lg:flex' }} min-h-0 flex-col bg-gray-50">
        @if ($sel === null)
            <div class="m-auto text-sm text-gray-500">Weiterleitung auswählen.</div>
        @else
            <div class="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
                <button type="button" wire:click="back" class="lg:hidden text-sm text-blue-700">‹ zurück</button>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold text-gray-900">{{ $sel['name'] }}</div>
                    <div class="text-xs text-gray-500 tabular-nums">{{ $sel['phone'] }}</div>
                </div>
                <button type="button" wire:click="markDone({{ $sel['id'] }})" class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">Erledigt</button>
            </div>

            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                @include('recruiting::livewire.conversations._forward-card', ['card' => $sel])

                @if ($sel['hr_messages'] !== [])
                    <div class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">Verlauf HR-Nummer</div>
                    <div class="flex flex-col gap-2">
                        @include('recruiting::livewire.dispo._messages', ['messages' => $sel['hr_messages'], 'portalUrl' => null])
                    </div>
                    @if (!$sel['target_listed'])
                        <div class="text-xs text-gray-500">Der Chat erscheint unter „Alle", sobald der MA antwortet.</div>
                    @endif
                @endif
            </div>

            <div class="border-t border-gray-200 bg-white px-4 py-3">
                @if ($sel['last_error'] || $actionError)
                    <div class="mb-2 text-xs font-semibold text-red-600">{{ $actionError ?? $sel['last_error'] }}</div>
                @endif
                @if ($sel['first_contact_at'])
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-600">
                        <span>Erstnachricht gesendet am {{ $sel['first_contact_at'] }}.</span>
                        @if ($sel['target_listed'])
                            <button type="button" wire:click="openChat({{ $sel['id'] }})" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white">Chat öffnen</button>
                        @endif
                    </div>
                @elseif ($sel['sibling_first_contact_at'])
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-600">
                        <span>Diese Person wurde am {{ $sel['sibling_first_contact_at'] }} schon angeschrieben (andere Weiterleitung).</span>
                        <button type="button" wire:click="sendFirstContact({{ $sel['id'] }})" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white">Dem HR-Chat zuordnen</button>
                    </div>
                @elseif ($sel['open_thread_id'])
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-600">
                        <span>Der MA hat der HR-Nummer in den letzten 24 h geschrieben – du kannst direkt antworten.</span>
                        <button type="button" wire:click="openChat({{ $sel['id'] }})" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white">Chat öffnen</button>
                    </div>
                @elseif ($sel['can_send'])
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-600">
                        <span>Schickt „Gespräch starten" über die HR-Nummer.</span>
                        <button type="button" wire:click="sendFirstContact({{ $sel['id'] }})" wire:loading.attr="disabled" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white">Erstnachricht senden</button>
                    </div>
                @else
                    <div class="text-xs text-gray-600">Kein MA zugeordnet – die Vorlage braucht den Vornamen. Bitte telefonisch klären und dann „Erledigt".</div>
                @endif
            </div>
        @endif
    </div>
</div>
