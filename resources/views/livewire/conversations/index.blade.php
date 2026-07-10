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

    {{-- HR-Abwesenheitsmodus: Zustand IMMER aus OooMode (3 Zustaende), nie aus dem rohen Flag --}}
    @php
        $oooState = $this->oooState;
        $oooView = $this->oooView;
    @endphp
    <div class="rounded-lg border px-4 py-3 text-sm
        @if($oooState === 'active') border-amber-300 bg-amber-50
        @elseif($oooState === 'pending') border-sky-200 bg-sky-50
        @else border-gray-200 bg-white @endif">
        <div class="flex items-center justify-between gap-4">
            <div>
                @if($oooState === 'active')
                    <span class="font-medium text-amber-900">Abwesenheitsmodus aktiv</span>
                    <span class="text-amber-800">— wieder da am {{ $oooView['back_at'] }}. Eingehende Nachrichten erhalten automatisch die Abwesenheitsnotiz (1×/24h je Konversation).</span>
                @elseif($oooState === 'pending')
                    <span class="font-medium text-sky-900">Abwesenheitsmodus geplant</span>
                    <span class="text-sky-800">— ab {{ $oooView['from'] }} (wieder da am {{ $oooView['back_at'] }}).</span>
                @else
                    <span class="font-medium text-gray-700">HR in Abwesenheit</span>
                    <span class="text-gray-500">— Abwesenheitsnotiz fuer eingehende Nachrichten aktivieren.</span>
                @endif
            </div>
            <div class="flex-shrink-0">
                @if($oooState === 'off')
                    <button wire:click="openOooForm"
                            class="px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 text-gray-700 bg-white hover:bg-gray-50">
                        Aktivieren…
                    </button>
                @else
                    <button wire:click="deactivateOoo"
                            class="px-3 py-1.5 text-sm font-medium rounded-md border border-red-200 text-red-700 bg-white hover:bg-red-50">
                        Deaktivieren
                    </button>
                @endif
            </div>
        </div>

        @if($showOooForm && $oooState === 'off')
            <div class="mt-3 pt-3 border-t border-gray-200 flex flex-wrap items-end gap-4">
                <label class="text-xs text-gray-600">Abwesend von
                    <input type="date" wire:model="oooForm.from"
                           class="mt-1 block rounded-md border-gray-300 text-sm">
                </label>
                <label class="text-xs text-gray-600">Bis (letzter Tag)
                    <input type="date" wire:model.live="oooForm.until"
                           class="mt-1 block rounded-md border-gray-300 text-sm">
                </label>
                <label class="text-xs text-gray-600">Wieder da ab
                    <input type="date" wire:model="oooForm.back_at"
                           class="mt-1 block rounded-md border-gray-300 text-sm">
                </label>
                <button wire:click="activateOoo"
                        class="px-3 py-1.5 text-sm font-medium rounded-md border border-emerald-200 text-emerald-700 bg-white hover:bg-emerald-50">
                    Speichern &amp; aktivieren
                </button>
                <button wire:click="$set('showOooForm', false)"
                        class="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700">
                    Abbrechen
                </button>
            </div>
        @endif
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

    {{-- Bulk-Aktionen: markieren → Eingangsbestätigung an alle Markierten senden --}}
    @php $selectedCount = count($selected); @endphp
    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3">
        <span class="text-sm font-medium text-gray-700">{{ $selectedCount }} markiert</span>
        <button wire:click="selectAllVisible" class="text-sm text-[var(--ui-primary)] hover:underline">Alle (gefiltert) markieren</button>
        @if ($selectedCount > 0)
            <button wire:click="clearSelection" class="text-sm text-gray-500 hover:underline">Auswahl löschen</button>
        @endif
        <div class="ml-auto flex items-center gap-3">
            @if ($this->holdingTemplateName)
                <span class="text-xs text-gray-400">Template: <span class="font-mono">{{ $this->holdingTemplateName }}</span></span>
                <button wire:click="sendTemplateToSelected"
                        @disabled($selectedCount === 0)
                        class="rounded bg-[var(--ui-primary)] px-3 py-2 text-sm font-medium text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40">
                    „Wir melden uns schnellstmöglich" an Markierte senden
                </button>
            @else
                <span class="text-xs text-amber-600">Kein Bestätigungs-Template hinterlegt — in Einstellungen → Kommunikation wählen.</span>
            @endif
        </div>
    </div>

    {{-- Tisch-Ansicht --}}
    <div class="rounded-lg border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium w-8"></th>
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
                            <input type="checkbox" wire:model.live="selected" value="{{ $row->threadId }}"
                                   class="h-4 w-4 rounded border-gray-300 text-[var(--ui-primary)] focus:ring-[var(--ui-primary)]">
                        </td>
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
                            <div class="flex items-center gap-2 text-xs text-gray-400">
                                <span class="inline-flex rounded px-1.5 py-0.5 {{ $row->subjectType === 'employee' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700' }}">
                                    {{ $row->subjectType === 'employee' ? 'Mitarbeiter' : 'Bewerber' }}
                                </span>
                                <span>{{ $row->phone }}</span>
                            </div>
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
                                @if ($row->url)
                                    <a href="{{ $row->url }}" wire:navigate
                                       class="rounded bg-[var(--ui-primary)] px-2.5 py-1 text-xs font-medium text-white hover:opacity-90">Öffnen</a>
                                @endif
                                @if ($row->isUnread)
                                    <button wire:click="markRead({{ $row->threadId }})"
                                            class="rounded bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">Gelesen</button>
                                @else
                                    <button wire:click="markUnread({{ $row->threadId }})"
                                            class="rounded bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">Ungelesen</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-gray-400">
                            Keine Konversationen im aktuellen Filter. 🎉
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-400">
        Konversationen markieren und „Wir melden uns schnellstmöglich" an Markierte senden — verschickt das in
        den Einstellungen hinterlegte Template mit Vornamen-Anrede an alle Markierten, unabhängig vom 24h-Fenster. Die Ampel zeigt die Restzeit im 24h-Fenster (grün → gelb → rot), „Verpasst"
        = Fenster zu (dann sind freie Antworten erst nach einer Reaktion der Person wieder möglich).
    </p>
</div>
