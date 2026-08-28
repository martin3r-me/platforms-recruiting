{{-- Disposition → Kommunikation: Postfach-Layout (Liste | Thread), mobil Master-Detail.
     Logik unveraendert (Kanal-Set-Regel, Antwort ueber Thread-Kanal, 24h-Fenster);
     hier nur Darstellung. Blade-Regeln: PHP-Bloecke nur in Blockform, keine an
     Woerter geklebten Direktiven, keine x-ui-Komponenten. --}}
@php
    $tabs = $this->filialeTabs;
    $threads = $this->threads;
    $info = $this->selectedInfo;
    $hasSelection = $info !== null;
    $totalUnread = $tabs[0]['unread'] ?? 0;
    // Avatar-Farbe je Filiale (stabil ueber die Filial-Nr), Fallback grau.
    $avatarClass = function ($filialNr) {
        if ($filialNr === null) {
            return 'bg-gray-400';
        }
        $palette = ['bg-blue-600', 'bg-cyan-700', 'bg-violet-600', 'bg-emerald-600', 'bg-rose-600'];
        return $palette[((int) $filialNr) % count($palette)];
    };
    $windowChip = function (array $window) {
        return match ($window['state']) {
            'open'   => ['text' => 'offen · ' . $window['left'], 'class' => 'bg-green-50 text-green-700', 'dot' => 'bg-green-500'],
            'closed' => ['text' => 'abgelaufen', 'class' => 'bg-amber-50 text-amber-700', 'dot' => 'bg-amber-500'],
            default  => ['text' => 'noch keine Antwort', 'class' => 'bg-gray-100 text-gray-500', 'dot' => 'bg-gray-400'],
        };
    };
@endphp
<div class="flex h-[calc(100vh-4rem)] flex-col lg:h-[calc(100vh-3rem)]">

    {{-- Kopf: Titel + Filial-Tabs + Alle/Ungelesen (auf dem Handy ausgeblendet, sobald ein Thread offen ist) --}}
    <div class="{{ $hasSelection ? 'hidden lg:block' : '' }} border-b border-gray-200 bg-white px-4 py-3 lg:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold tracking-tight">Kommunikation</h1>
                <p class="text-xs text-gray-500">Dispo-Nummern · {{ $totalUnread }} ungelesen</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($tabs !== [])
                    <div class="flex max-w-full gap-1 overflow-x-auto rounded-full bg-gray-100 p-1 text-xs font-semibold">
                        @foreach ($tabs as $tab)
                            <button type="button" wire:click="$set('tabFilial', '{{ $tab['key'] }}')"
                                    class="flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 {{ $tabFilial === $tab['key'] ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
                                {{ $tab['label'] }}
                                @if ($tab['unread'] > 0)
                                    <span class="rounded-full px-1.5 text-[11px] {{ $tabFilial === $tab['key'] ? 'bg-blue-50 text-blue-700' : 'text-gray-500' }}">{{ $tab['unread'] }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
                <div class="flex gap-1 rounded-full bg-gray-100 p-1 text-xs font-semibold">
                    <button type="button" wire:click="$set('filter', 'alle')" class="rounded-full px-3 py-1.5 {{ $filter === 'alle' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">Alle</button>
                    <button type="button" wire:click="$set('filter', 'ungelesen')" class="rounded-full px-3 py-1.5 {{ $filter === 'ungelesen' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">Ungelesen</button>
                </div>
            </div>
        </div>
    </div>

    @if ($this->channelIds === [])
        <div class="m-6 rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600">
            Kein Dispo-Kanal konfiguriert — bitte in <a href="{{ route('recruiting.dispo.settings') }}" class="text-blue-600 hover:underline">Disposition → Einstellungen</a> ein Bestätigungs-Template wählen.
        </div>
    @else
        <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[340px_1fr]">

            {{-- ===== Thread-Liste ===== --}}
            <div class="{{ $hasSelection ? 'hidden lg:flex' : 'flex' }} min-h-0 flex-col border-r border-gray-200 bg-white">
                <div class="border-b border-gray-200 p-3">
                    <label class="flex items-center gap-2 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-500">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name oder Nummer suchen" class="w-full border-0 bg-transparent p-0 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-0">
                    </label>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    @forelse ($threads as $thread)
                        @php
                            $isSel = $selectedThreadId === $thread['id'];
                            $wc = $windowChip($thread['window']);
                        @endphp
                        <button type="button" wire:click="select({{ $thread['id'] }})"
                                class="grid w-full grid-cols-[38px_1fr_auto] items-start gap-3 border-b border-gray-100 border-l-[3px] px-3 py-3 text-left hover:bg-gray-50 {{ $isSel ? 'border-l-blue-600 bg-blue-50/60' : 'border-l-transparent' }}">
                            <span class="grid h-[38px] w-[38px] place-items-center rounded-full text-xs font-bold text-white {{ $avatarClass($thread['filial_nr']) }}">{{ $thread['initials'] }}</span>
                            <span class="min-w-0">
                                <span class="flex items-center gap-1.5 text-sm {{ $thread['is_unread'] ? 'font-semibold text-gray-900' : 'font-medium text-gray-700' }}">
                                    @if ($thread['is_unread'])
                                        <span class="h-2 w-2 shrink-0 rounded-full bg-blue-600"></span>
                                    @endif
                                    <span class="truncate">{{ $thread['label'] }}</span>
                                </span>
                                <span class="mt-0.5 flex items-center gap-1 truncate text-xs {{ $thread['preview_is_template'] ? 'text-gray-400' : 'text-gray-500' }}">
                                    @if ($thread['preview_is_template'])
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><path d="M4 12h16M14 6l6 6-6 6"/></svg>
                                    @endif
                                    <span class="truncate">{{ $thread['preview'] }}</span>
                                </span>
                                <span class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10.5px] font-semibold {{ $wc['class'] }}"><span class="h-1.5 w-1.5 rounded-full {{ $wc['dot'] }}"></span>{{ $wc['text'] }}</span>
                                    @if ($thread['employee_id'] === null)
                                        <span class="rounded bg-red-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-red-700">kein MA</span>
                                    @endif
                                </span>
                            </span>
                            <span class="flex flex-col items-end gap-1.5 text-[11px] text-gray-400">
                                <span class="tabular-nums {{ $thread['is_unread'] ? 'text-gray-600' : '' }}" title="{{ $thread['last_at'] }}">{{ $thread['last_short'] }}</span>
                                @if ($tabFilial === '')
                                    <span class="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500">{{ $thread['filiale'] }}</span>
                                @endif
                            </span>
                        </button>
                    @empty
                        <div class="p-8 text-center text-sm text-gray-500">
                            @if ($search !== '')
                                Nichts gefunden für „{{ $search }}".
                            @elseif ($filter === 'ungelesen')
                                Alles gelesen — keine offenen Nachrichten.
                            @else
                                Noch keine Nachrichten auf den Dispo-Nummern.
                            @endif
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- ===== Thread ===== --}}
            <div class="{{ $hasSelection ? 'flex' : 'hidden lg:flex' }} min-h-0 flex-col bg-gray-50">
                @if (!$hasSelection)
                    <div class="grid flex-1 place-items-center p-8 text-center text-sm text-gray-500">
                        <div>
                            <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-white text-gray-400 shadow-sm">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v12H7l-3 3z"/></svg>
                            </div>
                            Thread auswählen, um den Verlauf zu sehen.
                        </div>
                    </div>
                @else
                    @php
                        $panel = $this->employeePanel;
                        $window = $info['window'];
                        $wc = $windowChip($window);
                        $statusMeta = [
                            'offen'              => ['—', 'bg-gray-400', 'text-gray-500'],
                            'angeschrieben'      => ['angeschrieben', 'bg-blue-500', 'text-blue-700'],
                            'bestaetigt'         => ['bestätigt', 'bg-green-500', 'text-green-700'],
                            'geloescht_gemeldet' => ['rausgenommen', 'bg-red-500', 'text-red-700'],
                        ];
                        $messages = $this->messages;
                        $lastDay = null;
                    @endphp

                    {{-- Kopfzeile --}}
                    <div class="flex items-center gap-3 border-b border-gray-200 bg-white px-3 py-2.5 lg:px-5">
                        <button type="button" wire:click="back" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-gray-100 text-gray-600 lg:hidden" aria-label="Zurück zur Liste">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m15 5-7 7 7 7"/></svg>
                        </button>
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold text-white {{ $avatarClass($info['filial_nr']) }}">{{ $info['initials'] }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 text-[15px] font-semibold">
                                <span class="truncate">{{ $info['label'] }}</span>
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold uppercase tracking-wide text-gray-500">{{ $info['filiale'] }}</span>
                                @if (!$info['matched'])
                                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-red-700">kein MA zugeordnet</span>
                                @endif
                            </div>
                            <div class="truncate text-xs text-gray-500 tabular-nums">{{ $info['phone'] }}</div>
                        </div>
                        <button type="button" wire:click="toggleUnread({{ $selectedThreadId }})" class="hidden shrink-0 items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50 sm:inline-flex">
                            als {{ $info['is_unread'] ? 'gelesen' : 'ungelesen' }} markieren
                        </button>
                    </div>

                    {{-- Einsätze des MA --}}
                    @if ($panel !== [])
                        <div class="flex items-center gap-2 overflow-x-auto border-b border-gray-200 bg-white px-3 py-2 text-xs lg:flex-wrap lg:px-5">
                            <span class="shrink-0 text-[10.5px] font-bold uppercase tracking-wider text-gray-400">Einsätze</span>
                            @foreach ($panel as $row)
                                @php $sm = $statusMeta[$row['status']] ?? $statusMeta['offen']; @endphp
                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2 py-1 text-gray-700">
                                    <span class="h-2 w-2 rounded-full {{ $sm[1] }}"></span>
                                    <span class="font-semibold tabular-nums">{{ $row['datum'] }}</span>
                                    @if ($row['zeit'])
                                        <span class="tabular-nums">{{ $row['zeit'] }}</span>
                                    @endif
                                    <span class="max-w-[14rem] truncate">{{ $row['event'] }}</span>
                                    <span class="font-semibold {{ $sm[2] }}">{{ $sm[0] }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Verlauf --}}
                    <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-3 py-4 lg:px-5">
                        @forelse ($messages as $message)
                            @if ($message['day'] !== $lastDay)
                                @php $lastDay = $message['day']; @endphp
                                <div class="my-1 self-center rounded-full border border-gray-200 bg-white px-3 py-0.5 text-[11px] font-semibold text-gray-400">{{ $message['day_label'] }}</div>
                            @endif
                            @if ($message['kind'] === 'template')
                                <div class="grid max-w-[85%] grid-cols-[30px_1fr] items-center gap-x-2.5 gap-y-0.5 self-end rounded-xl border border-gray-200 bg-white px-3 py-2 lg:max-w-[68%]">
                                    <span class="row-span-2 grid h-[30px] w-[30px] place-items-center rounded-lg bg-blue-50 text-blue-700">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v12H7l-3 3z"/><path d="M8 9h8M8 12h5"/></svg>
                                    </span>
                                    <span class="text-[13px] font-semibold text-gray-800">{{ $message['template_label'] }} gesendet</span>
                                    <span class="text-[11px] text-gray-400 tabular-nums">{{ $message['time'] }}@if ($message['status']) · {{ $message['status'] }}@endif</span>
                                </div>
                            @else
                                <div class="flex max-w-[85%] flex-col gap-0.5 lg:max-w-[68%] {{ $message['direction'] === 'outbound' ? 'self-end items-end' : 'self-start' }}">
                                    <div class="whitespace-pre-line rounded-2xl px-3 py-2 text-sm leading-relaxed {{ $message['direction'] === 'outbound' ? 'rounded-br-md bg-blue-600 text-white' : 'rounded-bl-md bg-white text-gray-900 shadow-sm' }}">{{ $message['body'] }}</div>
                                    <div class="px-1 text-[11px] text-gray-400 tabular-nums">{{ $message['time'] }}@if ($message['direction'] === 'outbound' && $message['status']) · {{ $message['status'] }}@endif</div>
                                </div>
                            @endif
                        @empty
                            <div class="self-center text-sm text-gray-500">Keine Nachrichten.</div>
                        @endforelse
                    </div>

                    {{-- Antwort (unten fixiert) --}}
                    <div class="border-t border-gray-200 bg-white px-3 pb-3 pt-2.5 lg:px-5">
                        <div class="mb-2 flex items-center gap-2 text-xs">
                            <span class="inline-flex items-center gap-1.5 font-semibold {{ $window['state'] === 'open' ? 'text-green-700' : ($window['state'] === 'closed' ? 'text-amber-700' : 'text-gray-500') }}">
                                <span class="h-2 w-2 rounded-full {{ $wc['dot'] }}"></span>
                                @if ($window['state'] === 'open')
                                    Antwortfenster offen — noch {{ $window['left'] }}
                                @elseif ($window['state'] === 'closed')
                                    Antwortfenster abgelaufen
                                @else
                                    Noch keine Nachricht vom Mitarbeiter
                                @endif
                            </span>
                            <button type="button" wire:click="toggleUnread({{ $selectedThreadId }})" class="ml-auto text-xs text-gray-400 hover:text-gray-600 sm:hidden">als {{ $info['is_unread'] ? 'gelesen' : 'ungelesen' }} markieren</button>
                        </div>
                        @if ($window['state'] === 'open')
                            <div class="flex items-end gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2 pl-3 focus-within:border-blue-400">
                                <textarea wire:model="replyText" rows="1" placeholder="Antwort schreiben …"
                                          class="max-h-40 min-h-[36px] w-full resize-y border-0 bg-transparent p-1.5 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-0"></textarea>
                                <button type="button" wire:click="sendReply" wire:loading.attr="disabled" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50" aria-label="Senden">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 12 20 4l-4 16-4-7z"/></svg>
                                </button>
                            </div>
                        @else
                            <div class="rounded-xl bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
                                @if ($window['state'] === 'closed')
                                    Freitext ist nur bis 24 h nach der letzten Nachricht des Mitarbeiters möglich. Erinnerungen laufen als Vorlage über die <a href="{{ route('recruiting.dispo.events.index') }}" class="font-semibold underline">Veranstaltung</a>.
                                @else
                                    Sobald der Mitarbeiter antwortet, kannst du hier 24 h lang frei schreiben. Bis dahin laufen Nachrichten als Vorlage über die <a href="{{ route('recruiting.dispo.events.index') }}" class="font-semibold underline">Veranstaltung</a>.
                                @endif
                            </div>
                        @endif
                        @if ($sendError)
                            <p class="mt-2 text-sm text-red-600">{{ $sendError }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
