<div class="p-6">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-semibold">Dispo-Kommunikation</h1>
        <div class="flex gap-2 text-sm">
            <button wire:click="$set('filter', 'alle')" class="rounded px-3 py-1 {{ $filter === 'alle' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600' }}">Alle</button>
            <button wire:click="$set('filter', 'ungelesen')" class="rounded px-3 py-1 {{ $filter === 'ungelesen' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600' }}">Ungelesen</button>
        </div>
    </div>

    @if ($this->channelId === null)
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600">
            Kein Dispo-Kanal konfiguriert — bitte in <a href="{{ route('recruiting.dispo.settings') }}" class="text-blue-600 hover:underline">Disposition → Einstellungen</a> ein Bestätigungs-Template wählen.
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- Thread-Liste --}}
            <div class="rounded-lg border border-gray-200 bg-white lg:col-span-1">
                @forelse ($this->threads as $thread)
                    <button wire:click="select({{ $thread['id'] }})"
                            class="block w-full border-b border-gray-100 px-4 py-3 text-left hover:bg-gray-50 {{ $selectedThreadId === $thread['id'] ? 'bg-blue-50' : '' }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-medium {{ $thread['is_unread'] ? 'text-gray-900' : 'text-gray-600' }}">
                                @if ($thread['is_unread'])<span class="mr-1 inline-block h-2 w-2 rounded-full bg-blue-600"></span>@endif
                                {{ $thread['label'] }}
                            </span>
                            <span class="shrink-0 text-xs text-gray-400">{{ $thread['last_at'] }}</span>
                        </div>
                        <div class="mt-0.5 truncate text-xs text-gray-500">{{ $thread['preview'] }}</div>
                    </button>
                @empty
                    <div class="p-6 text-center text-sm text-gray-500">Noch keine Nachrichten auf der Dispo-Nummer.</div>
                @endforelse
            </div>

            {{-- Detail --}}
            <div class="lg:col-span-2 space-y-4">
                @if ($this->selected === null)
                    <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-500">Thread auswählen.</div>
                @else
                    @php
                        $panel = $this->employeePanel;
                        $windowOpen = \Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator::isReplyWindowOpen($this->selected->last_inbound_at, now());
                        $statusLabels = ['offen' => ['—', 'bg-gray-100 text-gray-600'], 'angeschrieben' => ['angeschrieben', 'bg-blue-50 text-blue-700'], 'bestaetigt' => ['✓ bestätigt', 'bg-green-100 text-green-800'], 'geloescht_gemeldet' => ['zur Löschung gemeldet', 'bg-red-100 text-red-800']];
                    @endphp

                    {{-- Einsatz-Panel --}}
                    @if ($panel !== [])
                        <div class="rounded-lg border border-gray-200 bg-white p-4">
                            <div class="mb-2 text-sm font-medium text-gray-700">Kommende Einsätze</div>
                            <div class="space-y-1">
                                @foreach ($panel as $row)
                                    <div class="flex items-center justify-between text-sm">
                                        <span>{{ $row['datum'] }}@if ($row['zeit']) · {{ $row['zeit'] }} Uhr @endif · {{ $row['event'] }}</span>
                                        <span class="rounded px-1.5 py-0.5 text-xs {{ $statusLabels[$row['status']][1] }}">{{ $statusLabels[$row['status']][0] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Verlauf --}}
                    <div class="max-h-[28rem] space-y-2 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4">
                        @forelse ($this->messages as $message)
                            <div class="flex {{ $message['direction'] === 'outbound' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[80%] rounded-lg px-3 py-2 text-sm {{ $message['direction'] === 'outbound' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-900' }}">
                                    <div class="whitespace-pre-line">{{ $message['body'] }}</div>
                                    <div class="mt-1 text-xs {{ $message['direction'] === 'outbound' ? 'text-blue-200' : 'text-gray-400' }}">{{ $message['at'] }}@if ($message['direction'] === 'outbound' && $message['status']) · {{ $message['status'] }}@endif</div>
                                </div>
                            </div>
                        @empty
                            <div class="text-sm text-gray-500">Keine Nachrichten.</div>
                        @endforelse
                    </div>

                    {{-- Antwort --}}
                    <div class="rounded-lg border border-gray-200 bg-white p-4">
                        @if ($windowOpen)
                            <div class="flex items-end gap-2">
                                <textarea wire:model="replyText" rows="2" placeholder="Antwort schreiben…"
                                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                                <button wire:click="sendReply" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Senden</button>
                            </div>
                        @else
                            <p class="text-sm text-gray-500">24h-Fenster abgelaufen — Freitext nicht mehr möglich. Erinnerungen laufen über die VA-Seite (Template).</p>
                        @endif
                        @if ($sendError)
                            <p class="mt-2 text-sm text-red-600">{{ $sendError }}</p>
                        @endif
                        <div class="mt-2 text-right">
                            <button wire:click="toggleUnread({{ $this->selected->id }})" class="text-xs text-gray-400 hover:text-gray-600">als {{ $this->selected->is_unread ? 'gelesen' : 'ungelesen' }} markieren</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
