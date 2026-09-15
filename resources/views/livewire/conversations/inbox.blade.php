{{-- Kommunikation (Vorschau) → Postfach-Layout (Liste | Chat), mobil Master-Detail.
     Grundmenge kommt aus InboxQuery::snapshot() — EIN Aufruf pro Render.
     Blade-Regeln: PHP-Bloecke nur in Blockform, keine an Woerter geklebten
     Direktiven, keine x-ui-Komponenten. --}}
@php
    $counts = $this->counts;
    $pills = [
        ['key' => 'unread', 'label' => 'Ungelesen', 'value' => $counts['unread'], 'class' => 'text-orange-600'],
        ['key' => 'yellow', 'label' => 'Gelb',      'value' => $counts['yellow'], 'class' => 'text-amber-600'],
        ['key' => 'red',    'label' => 'Rot',       'value' => $counts['red'],    'class' => 'text-red-600'],
        ['key' => 'missed', 'label' => 'Verpasst',  'value' => $counts['missed'], 'class' => 'text-gray-700'],
    ];
    $levelBar = [
        'missed' => 'bg-gray-800',
        'red'    => 'bg-red-500',
        'yellow' => 'bg-amber-400',
        'green'  => 'bg-emerald-500',
        'none'   => 'bg-gray-200',
    ];
    $fensterText = function ($escalation) {
        if ($escalation->level === 'missed') {
            $stunden = abs($escalation->hoursLeftInWindow);
            return $stunden >= 24
                ? 'verpasst seit ' . floor($stunden / 24) . ' Tg'
                : 'verpasst seit ' . round($stunden) . ' h';
        }
        if (!$escalation->windowOpen) {
            return '';
        }
        $h = $escalation->hoursLeftInWindow;
        return $h >= 1 ? 'noch ' . round($h, 1) . ' h' : 'noch ' . max(1, round($h * 60)) . ' min';
    };
@endphp
<div class="flex h-[calc(100vh-4rem)] flex-col lg:h-[calc(100vh-3rem)]" wire:poll.visible.20s>

    {{-- Kopf: Titel + Ampel-Pillen (auf dem Handy ausgeblendet, sobald ein Chat offen ist) --}}
    <div class="{{ $selectedThreadId !== null ? 'hidden lg:block' : '' }} border-b border-gray-200 bg-white px-4 py-3 lg:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold tracking-tight">Kommunikation</h1>
                <p class="text-xs text-gray-500">Vorschau · {{ $this->total }} Konversationen</p>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach ($pills as $pill)
                    <button type="button" wire:click="setLevel('{{ $pill['key'] }}')"
                            class="rounded-full border px-3 py-1 text-xs font-semibold {{ $level === $pill['key'] ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white ' . $pill['class'] }}">
                        {{ $pill['label'] }} {{ $pill['value'] }}
                    </button>
                @endforeach
                <button type="button" wire:click="toggleHandledView"
                        class="rounded-full border px-3 py-1 text-xs font-semibold {{ $showHandled ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-500' }}">
                    Erledigt {{ $counts['handled'] }}
                </button>
            </div>
        </div>
    </div>

    <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[360px_1fr]">

        {{-- ===== Liste ===== --}}
        <div class="{{ $selectedThreadId !== null ? 'hidden lg:flex' : 'flex' }} min-h-0 flex-col border-r border-gray-200 bg-white">
            @if ($this->fallback)
                <div class="border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Kein WhatsApp-Konto erreichbar — es werden nur zugeordnete Chats angezeigt.
                    In Einstellungen → Kommunikation ein Konto wählen.
                </div>
            @endif
            <div class="min-h-0 flex-1 overflow-y-auto">
                @forelse ($this->rows as $row)
                    @php
                        $istGewaehlt = $selectedThreadId === $row->threadId;
                        $balken = $levelBar[$row->escalation->level] ?? $levelBar['none'];
                        $fenster = $fensterText($row->escalation);
                    @endphp
                    <button type="button" wire:click="select({{ $row->threadId }})" wire:key="row-{{ $row->threadId }}"
                            class="flex w-full items-start gap-3 border-b border-gray-100 border-l-[3px] px-3 py-3 text-left hover:bg-gray-50 {{ $istGewaehlt ? 'border-l-gray-900 bg-gray-50' : 'border-l-transparent' }}">
                        <span class="mt-1 inline-block h-8 w-1 shrink-0 rounded {{ $balken }}"></span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5 text-sm {{ $row->isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-700' }}">
                                <span class="truncate">{{ $row->title }}</span>
                                @if ($row->isUnread)
                                    <span class="h-2 w-2 shrink-0 rounded-full bg-orange-500"></span>
                                @endif
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-gray-500">{{ $row->preview ?: '—' }}</span>
                            <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10.5px] font-semibold">
                                @if ($fenster !== '')
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600">{{ $fenster }}</span>
                                @endif
                                @if ($row->subjectType === 'employee')
                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700">MA</span>
                                @endif
                            </span>
                        </span>
                    </button>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">
                        @if ($search !== '')
                            Nichts gefunden für „{{ $search }}".
                        @elseif ($showHandled)
                            Noch nichts als erledigt markiert.
                        @else
                            Keine Konversationen im gewählten Filter.
                        @endif
                    </div>
                @endforelse
                @if (count($this->rows) < $this->total)
                    <button type="button" wire:click="loadMore" class="w-full border-t border-gray-100 px-3 py-3 text-xs font-semibold text-gray-500 hover:bg-gray-50">
                        mehr laden ({{ count($this->rows) }} von {{ $this->total }})
                    </button>
                @endif
            </div>
        </div>

        {{-- ===== Chat ===== --}}
        <div class="{{ $selectedThreadId !== null ? 'flex' : 'hidden lg:flex' }} min-h-0 flex-col bg-gray-50">
            @if ($selectedThreadId === null)
                <div class="grid flex-1 place-items-center p-8 text-center text-sm text-gray-500">
                    Chat auswählen, um den Verlauf zu sehen.
                </div>
            @elseif ($this->selectedRow === null)
                <div class="grid flex-1 place-items-center p-8 text-center text-sm text-gray-500">
                    <div>
                        <button type="button" wire:click="back" class="mb-3 inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 lg:hidden">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m15 5-7 7 7 7"/></svg>
                            Zurück
                        </button>
                        <div>Diese Konversation ist im aktuellen Filter nicht mehr sichtbar.</div>
                    </div>
                </div>
            @else
                @php
                    $selRow = $this->selectedRow;
                    $selFenster = $fensterText($selRow->escalation);
                    $selOwnerName = null;
                    foreach ($this->teamUsers as $teamUser) {
                        if ($teamUser['id'] === $selRow->ownerUserId) {
                            $selOwnerName = $teamUser['name'];
                            break;
                        }
                    }
                @endphp

                {{-- Kopfzeile --}}
                <div class="flex items-center gap-3 border-b border-gray-200 bg-white px-3 py-2.5 lg:px-5">
                    <button type="button" wire:click="back" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-gray-100 text-gray-600 lg:hidden" aria-label="Zurück zur Liste">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m15 5-7 7 7 7"/></svg>
                    </button>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-[15px] font-semibold">
                            <span class="truncate">{{ $selRow->title }}</span>
                            @if ($selFenster !== '')
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600">{{ $selFenster }}</span>
                            @endif
                            @if ($selOwnerName !== null)
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600">{{ $selOwnerName }}</span>
                            @endif
                            @if ($selRow->url)
                                <a href="{{ $selRow->url }}" class="rounded bg-blue-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-blue-700 hover:bg-blue-100" title="Akte öffnen">Akte öffnen ↗</a>
                            @endif
                        </div>
                        <div class="truncate text-xs text-gray-500 tabular-nums">{{ $selRow->phone }}</div>
                    </div>
                </div>

                {{-- Kontextzeile --}}
                @if ($this->contextChips !== [])
                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-white px-3 py-2 text-xs lg:px-5">
                        @foreach ($this->contextChips as $chip)
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2 py-1">
                                <span class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">{{ $chip['label'] }}</span>
                                <span class="font-semibold text-gray-700">{{ $chip['value'] }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- Verlauf: wire:key aus Thread+Anzahl -> bei neuer Nachricht wird der Container neu
                     aufgebaut und x-init scrollt ans Ende. --}}
                <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-3 py-4 lg:px-5"
                     wire:key="msgs-{{ $selectedThreadId }}-{{ count($this->messages) }}"
                     x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })">
                    @include('recruiting::livewire.dispo._messages', ['messages' => $this->messages, 'portalUrl' => null])
                </div>

                {{-- Antwortleiste: Freitext nur solange das 24h-Fenster offen ist. --}}
                <div class="border-t border-gray-200 bg-white px-3 py-2.5 lg:px-5">
                    @if ($this->windowOpen)
                        <div class="flex items-end gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2 pl-3 focus-within:border-gray-400"
                             x-data="{ fit(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 200) + 'px'; } }">
                            <textarea wire:model="replyText" rows="1" placeholder="Antwort schreiben …"
                                      x-init="fit($el)" x-on:input="fit($el)"
                                      x-on:reply-sent.window="$nextTick(() => fit($el))"
                                      class="max-h-[200px] min-h-[36px] w-full resize-none overflow-y-auto border-0 bg-transparent p-1.5 text-sm leading-snug text-gray-900 placeholder:text-gray-400 focus:ring-0"></textarea>
                            <button type="button" wire:click="sendReply" wire:loading.attr="disabled"
                                    class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-gray-900 text-white hover:bg-gray-800 disabled:opacity-50" aria-label="Senden">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 12 20 4l-4 16-4-7z"/></svg>
                            </button>
                        </div>
                    @endif
                    @if ($sendError)
                        <p class="mt-2 text-sm text-red-600">{{ $sendError }}</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
