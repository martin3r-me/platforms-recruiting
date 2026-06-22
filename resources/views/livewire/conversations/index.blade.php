<div class="p-6 space-y-6">
    @php
        $report = $this->report;
        $rows = $this->rows;

        // Owner-ID → Name
        $ownerNames = collect($this->teamUsers)->pluck('name', 'id');

        // Ampel-Metadaten je Level (vorberechnet — keine Inline-Logik in Komponenten).
        $levelMeta = [
            'missed' => ['label' => 'Verpasst', 'badge' => 'bg-gray-800 text-white',        'bar' => 'bg-gray-800'],
            'red'    => ['label' => 'Rot',      'badge' => 'bg-red-100 text-red-700',        'bar' => 'bg-red-500'],
            'yellow' => ['label' => 'Gelb',     'badge' => 'bg-amber-100 text-amber-700',    'bar' => 'bg-amber-400'],
            'green'  => ['label' => 'Grün',     'badge' => 'bg-emerald-100 text-emerald-700','bar' => 'bg-emerald-500'],
            'none'   => ['label' => 'Beantwortet','badge' => 'bg-gray-100 text-gray-500',    'bar' => 'bg-gray-200'],
        ];

        $fmtWindow = function ($esc) {
            if ($esc->level === 'missed') {
                $closedHours = abs($esc->hoursLeftInWindow);
                return $closedHours >= 24
                    ? 'verpasst seit ' . floor($closedHours / 24) . ' Tg'
                    : 'verpasst seit ' . round($closedHours) . ' h';
            }
            if (!$esc->windowOpen) {
                return '—';
            }
            $h = $esc->hoursLeftInWindow;
            return $h >= 1 ? 'noch ' . round($h, 1) . ' h offen' : 'noch < 1 h offen';
        };
    @endphp

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">Kommunikation</h1>
        <span class="text-sm text-gray-500">Unbeantwortete WhatsApp-Konversationen &amp; 24h-Fenster</span>
    </div>

    {{-- Flash --}}
    @if (session('message'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    {{-- Kennzahlen --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">Ungelesen</div>
            <div class="mt-1 text-2xl font-semibold text-orange-600">{{ $report->unreadCount }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">Läuft bald ab (gelb)</div>
            <div class="mt-1 text-2xl font-semibold text-amber-600">{{ $report->yellowCount }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">Dringend (rot)</div>
            <div class="mt-1 text-2xl font-semibold text-red-600">{{ $report->redCount }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="text-sm text-gray-500">Verpasst</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800">{{ $report->missedCount }}</div>
        </div>
    </div>

    {{-- Filter --}}
    <div class="flex flex-wrap items-end gap-4 rounded-lg border border-gray-200 bg-white p-4">
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Ansicht</span>
            <select wire:model.live="view" class="rounded border-gray-300">
                <option value="escalation">Eskalation (gelb/rot/verpasst)</option>
                <option value="unread">Nur ungelesen</option>
                <option value="all">Alle</option>
            </select>
        </label>
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Ampel</span>
            <select wire:model.live="level" class="rounded border-gray-300">
                <option value="all">Alle</option>
                <option value="missed">Verpasst</option>
                <option value="red">Rot</option>
                <option value="yellow">Gelb</option>
                <option value="green">Grün</option>
            </select>
        </label>
        <label class="flex flex-col text-sm">
            <span class="mb-1 text-gray-600">Zuständig</span>
            <select wire:model.live="owner" class="rounded border-gray-300">
                <option value="all">Alle</option>
                <option value="mine">Mir zugewiesen</option>
                @foreach ($this->teamUsers as $u)
                    <option value="{{ $u['id'] }}">{{ $u['name'] }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col text-sm flex-1 min-w-[12rem]">
            <span class="mb-1 text-gray-600">Suche</span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Name oder Nummer" class="rounded border-gray-300">
        </label>
        <button wire:click="markAllReadFiltered"
                class="rounded bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">
            Alle als gelesen markieren
        </button>
    </div>

    {{-- Tisch-Ansicht --}}
    <div class="rounded-lg border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Status</th>
                    <th class="px-4 py-2 font-medium">Kontakt</th>
                    <th class="px-4 py-2 font-medium">Letzte Nachricht</th>
                    <th class="px-4 py-2 font-medium">Zuständig</th>
                    <th class="px-4 py-2 font-medium">Fenster</th>
                    <th class="px-4 py-2 font-medium text-right">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $meta = $levelMeta[$row->escalation->level] ?? $levelMeta['none'];
                    @endphp
                    <tr class="border-t border-gray-50 {{ $row->isUnread ? 'bg-orange-50/40' : '' }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-8 w-1.5 rounded {{ $meta['bar'] }}"></span>
                                <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium {{ $meta['badge'] }}">{{ $meta['label'] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-800">
                                {{ $row->contactName }}
                                @if ($row->isUnread)
                                    <span class="ml-1 inline-block h-2 w-2 rounded-full bg-orange-500" title="ungelesen"></span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-400">{{ $row->phone }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            <span class="line-clamp-1 max-w-xs">{{ $row->preview ?: '—' }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            {{ $row->ownerUserId ? ($ownerNames[$row->ownerUserId] ?? '—') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $fmtWindow($row->escalation) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                @if ($row->applicantId)
                                    <button wire:click="open({{ $row->applicantId }})"
                                            class="rounded bg-[var(--ui-primary)] px-2.5 py-1 text-xs font-medium text-white hover:opacity-90">Öffnen</button>
                                @endif
                                @if ($row->isUnread)
                                    <button wire:click="markRead({{ $row->threadId }})"
                                            class="rounded bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">Gelesen</button>
                                @else
                                    <button wire:click="markUnread({{ $row->threadId }})"
                                            class="rounded bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">Ungelesen</button>
                                @endif
                                @if ($row->applicantId && in_array($row->escalation->level, ['red', 'missed'], true))
                                    <button wire:click="sendHolding({{ $row->applicantId }})"
                                            class="rounded bg-gray-800 px-2.5 py-1 text-xs font-medium text-white hover:bg-gray-700"
                                            title="Allgemeines Template senden, um das Fenster wieder zu öffnen">Template</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-400">
                            Keine Konversationen im aktuellen Filter. 🎉
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-400">
        WhatsApp erlaubt freie Antworten nur innerhalb von 24h nach der letzten eingehenden Nachricht.
        Danach (Status „Verpasst") öffnet erst das allgemeine Template das Fenster wieder, sobald die
        Person antwortet.
    </p>
</div>
