{{-- Kommunikation (Vorschau) → Postfach-Layout (Liste | Chat), mobil Master-Detail.
     Grundmenge kommt aus InboxQuery::snapshot() — EIN Aufruf pro Render.
     Blade-Regeln: PHP-Bloecke nur in Blockform, keine an Woerter geklebten
     Direktiven, keine x-ui-Komponenten. --}}
@php
    $counts = $this->counts;
    $pills = [
        ['key' => 'unread', 'label' => 'Ungelesen', 'value' => $counts['unread'], 'class' => 'text-orange-600'],
        ['key' => 'green',  'label' => 'Grün',      'value' => $counts['green'],  'class' => 'text-emerald-600'],
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
    // Fix (Abschluss-Durchsicht, Befund 6): laut Entwurf fehlten in der
    // Listenzeile das Kuerzel der zustaendigen Person, die Uhrzeit der letzten
    // Nachricht und ein Initialen-Avatar. $ownerNamesById einmal pro Render
    // gebaut (nicht pro Zeile aus $this->teamUsers gefiltert) — dieselbe
    // Ueberlegung wie beim Kopfzeilen-$selOwnerName weiter unten.
    $ownerNamesById = [];
    foreach ($this->teamUsers as $u) {
        $ownerNamesById[$u['id']] = $u['name'];
    }
    $initialenVon = function (?string $name): ?string {
        if (!$name || trim($name) === '') {
            return null;
        }
        $teile = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        if ($teile === []) {
            return null;
        }
        $buchstaben = array_map(fn ($teil) => mb_strtoupper(mb_substr($teil, 0, 1)), $teile);

        return implode('', array_slice($buchstaben, 0, 2));
    };
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
        // Fix (Abschluss-Durchsicht, Befund 6): deutsches Dezimaltrennzeichen —
        // "noch 2.4 h" war ein Punkt statt Komma, str_replace() nur auf die
        // Nachkommastelle, damit ganze Stunden ("noch 3 h") unveraendert bleiben.
        return $h >= 1 ? 'noch ' . str_replace('.', ',', (string) round($h, 1)) . ' h' : 'noch ' . max(1, round($h * 60)) . ' min';
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

                {{-- Abwesenheitsmodus: Zustand IMMER aus OooMode (3 Zustaende), nie aus dem
                     rohen Flag. Auf der alten Seite ein Vollbreiten-Banner, hier ein Panel
                     hinter dem Mond-Knopf — gleiche Einstellungen, gleicher Zustand. --}}
                @php
                    $oooState = $this->oooState;
                    $oooView = $this->oooView;
                    $mondKlasse = match ($oooState) {
                        'active' => 'border-amber-300 bg-amber-50 text-amber-700',
                        'pending' => 'border-sky-200 bg-sky-50 text-sky-700',
                        default => 'border-gray-200 bg-white text-gray-500',
                    };
                @endphp
                <div class="relative">
                    <button type="button" wire:click="$toggle('showOooPanel')" title="Abwesenheitsmodus"
                            class="grid h-8 w-8 place-items-center rounded-lg border {{ $mondKlasse }}">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></svg>
                    </button>
                    @if ($showOooPanel)
                        <div class="absolute right-0 top-10 z-20 w-80 rounded-xl border border-gray-200 bg-white p-3 text-sm shadow-lg">
                            <div>
                                @if ($oooState === 'active')
                                    <span class="font-medium text-amber-900">Abwesenheitsmodus aktiv</span>
                                    <p class="mt-1 text-amber-800">wieder da am {{ $oooView['back_at'] }}. Eingehende Nachrichten erhalten automatisch die Abwesenheitsnotiz (1×/24h je Konversation).</p>
                                @elseif ($oooState === 'pending')
                                    <span class="font-medium text-sky-900">Abwesenheitsmodus geplant</span>
                                    <p class="mt-1 text-sky-800">ab {{ $oooView['from'] }} (wieder da am {{ $oooView['back_at'] }}).</p>
                                @else
                                    <span class="font-medium text-gray-700">HR in Abwesenheit</span>
                                    <p class="mt-1 text-gray-500">Abwesenheitsnotiz fuer eingehende Nachrichten aktivieren.</p>
                                @endif
                            </div>

                            <div class="mt-3">
                                @if ($oooState === 'off')
                                    <button type="button" wire:click="openOooForm"
                                            class="w-full rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                        Aktivieren…
                                    </button>
                                @else
                                    <button type="button" wire:click="deactivateOoo"
                                            class="w-full rounded-md border border-red-200 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                                        Deaktivieren
                                    </button>
                                @endif
                            </div>

                            @if ($showOooForm && $oooState === 'off')
                                <div class="mt-3 flex flex-col gap-2 border-t border-gray-200 pt-3">
                                    <label class="text-xs text-gray-600">Abwesend von
                                        <input type="date" wire:model="oooForm.from"
                                               class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    </label>
                                    <label class="text-xs text-gray-600">Bis (letzter Tag)
                                        <input type="date" wire:model.live="oooForm.until"
                                               class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    </label>
                                    <label class="text-xs text-gray-600">Wieder da ab
                                        <input type="date" wire:model="oooForm.back_at"
                                               class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    </label>
                                    <button type="button" wire:click="activateOoo"
                                            class="rounded-md border border-emerald-200 bg-white px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                                        Speichern &amp; aktivieren
                                    </button>
                                    <button type="button" wire:click="$set('showOooForm', false)"
                                            class="text-xs text-gray-500 hover:text-gray-700">
                                        Abbrechen
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Flash: nur sendHoldingToSelected() (Sammelversand) setzt diese Keys —
         ohne dieses Duo bliebe ein session()->flash('error', …) unsichtbar. --}}
    @if (session('message'))
        <div class="border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[360px_1fr]">

        {{-- ===== Liste ===== --}}
        <div class="{{ $selectedThreadId !== null ? 'hidden lg:flex' : 'flex' }} min-h-0 flex-col border-r border-gray-200 bg-white">
            {{-- Suche + Zustaendig-Filter: schmale Zeile, kein eigenes Panel. Beide
                 Felder gehen ueber updated() (search/owner -> Auswahl leeren +
                 Seite zuruecksetzen) direkt in InboxFilter/InboxQuery. --}}
            <div class="space-y-2 border-b border-gray-200 px-3 py-2">
                {{-- Suche auf eigener Zeile und voller Breite: gequetscht neben
                     Auswahlfeld und Knopf brach der Platzhalter nach "Name oder Nu"
                     ab und niemand erkannte das Feld als Suche. --}}
                <label class="flex items-center gap-2 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-500 focus-within:bg-gray-50 focus-within:ring-1 focus-within:ring-gray-300">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Name oder Nummer suchen"
                           class="w-full border-0 bg-transparent p-0 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-0">
                    @if (trim($search) !== '')
                        <button type="button" wire:click="$set('search', '')" class="shrink-0 text-xs font-semibold text-gray-400 hover:text-gray-700" aria-label="Suche zurücksetzen">✕</button>
                    @endif
                </label>
                <div class="flex items-center gap-2">
                    <select wire:model.live="owner" class="min-w-0 flex-1 rounded-lg border-gray-300 text-xs">
                        <option value="all">Zuständig: alle</option>
                        <option value="mine">Mir zugewiesen</option>
                        @foreach ($this->teamUsers as $u)
                            <option value="{{ $u['id'] }}">{{ $u['name'] }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="toggleSelectMode"
                            class="shrink-0 rounded-lg border px-2.5 py-1 text-xs font-semibold {{ $selectMode ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-500 hover:bg-gray-50' }}">
                        {{ $selectMode ? 'Fertig' : 'Auswählen' }}
                    </button>
                </div>
                @if (trim($search) !== '')
                    <p class="text-[11px] text-gray-400">{{ $this->total }} Treffer für „{{ trim($search) }}"</p>
                @endif
            </div>
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
                        $rowInitialen = $initialenVon($row->title) ?? '?';
                        $rowOwnerName = $row->ownerUserId !== null ? ($ownerNamesById[$row->ownerUserId] ?? null) : null;
                        $rowOwnerKuerzel = $initialenVon($rowOwnerName);
                        // Re-Review-Fix: Zeitzone explizit mitgeben — Carbon 3
                        // liefert bei createFromTimestamp() sonst immer UTC
                        // (siehe Inbox::commsTimezone()-Docblock).
                        // Nur die Uhrzeit zu zeigen, war irrefuehrend: an einer
                        // 175 Tage alten Zeile las sich "16:10" wie heute.
                        // Heute -> Uhrzeit, gestern -> "Gestern", sonst Datum.
                        $rowZeit = null;
                        if ($row->lastMessageAt) {
                            $rowZeitpunkt = \Carbon\Carbon::createFromTimestamp($row->lastMessageAt, $this->commsTimezone);
                            if ($rowZeitpunkt->isToday()) {
                                $rowZeit = $rowZeitpunkt->format('H:i');
                            } elseif ($rowZeitpunkt->isYesterday()) {
                                $rowZeit = 'Gestern';
                            } else {
                                $rowZeit = $rowZeitpunkt->format('d.m.y');
                            }
                        }
                    @endphp
                    <div wire:key="row-{{ $row->threadId }}"
                         class="flex items-stretch border-b border-gray-100 border-l-[3px] {{ $istGewaehlt ? 'border-l-gray-900 bg-gray-50' : 'border-l-transparent' }}">
                        @if ($selectMode)
                            {{-- Eigenes Element NEBEN dem Zeilen-Knopf, nicht darin — sonst
                                 verschluckt der Knopf (wire:click="select") den Klick auf das
                                 Kaestchen. --}}
                            <label class="flex shrink-0 items-center pl-3">
                                <input type="checkbox" wire:model.live="selected" value="{{ $row->threadId }}"
                                       wire:key="chk-{{ $row->threadId }}"
                                       class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500">
                            </label>
                        @endif
                        <button type="button" wire:click="select({{ $row->threadId }})"
                                class="flex min-w-0 flex-1 items-start gap-3 px-3 py-3 text-left hover:bg-gray-50">
                            <span class="mt-1 inline-block h-8 w-1 shrink-0 rounded {{ $balken }}"></span>
                            {{-- Initialen-Avatar (Befund 6: fehlte laut Entwurf). --}}
                            <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-200 text-[11px] font-bold text-gray-600">{{ $rowInitialen }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center justify-between gap-1.5 text-sm {{ $row->isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-700' }}">
                                    <span class="flex min-w-0 items-center gap-1.5">
                                        <span class="truncate">{{ $row->title }}</span>
                                        @if ($row->isUnread)
                                            <span class="h-2 w-2 shrink-0 rounded-full bg-orange-500"></span>
                                        @endif
                                    </span>
                                    {{-- Uhrzeit der letzten Nachricht, rechts (Befund 6: fehlte laut Entwurf). --}}
                                    @if ($rowZeit !== null)
                                        <span class="shrink-0 pl-1 text-[10.5px] font-normal text-gray-400">{{ $rowZeit }}</span>
                                    @endif
                                </span>
                                <span class="mt-0.5 block truncate text-xs text-gray-500">{{ $row->preview ?: '—' }}</span>
                                <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10.5px] font-semibold">
                                    @if ($fenster !== '')
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600">{{ $fenster }}</span>
                                    @endif
                                    {{-- Kuerzel der zustaendigen Person (Befund 6: stand bisher nur im Chat-Kopf). --}}
                                    @if ($rowOwnerKuerzel !== null)
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600" title="{{ $rowOwnerName }}">{{ $rowOwnerKuerzel }}</span>
                                    @endif
                                    @if ($row->subjectType === 'employee')
                                        <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700">MA</span>
                                    @endif
                                    @if ($row->subjectType === 'unassigned')
                                        <span class="rounded bg-red-50 px-1.5 py-0.5 text-red-700">nicht zugeordnet</span>
                                    @endif
                                    @if ($row->contextLabel)
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500">{{ $row->contextLabel }}</span>
                                    @endif
                                    @if ($row->siblingCount > 0)
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500">+{{ $row->siblingCount }} weiterer Chat</span>
                                    @endif
                                </span>
                            </span>
                        </button>
                    </div>
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
            @if ($selectMode)
                <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 bg-white px-3 py-2 text-xs">
                    <span class="font-semibold text-gray-700">{{ count($selected) }} markiert</span>
                    <button type="button" wire:click="selectAllVisible" class="text-gray-500 hover:underline">alle sichtbaren</button>
                    <button type="button" wire:click="clearSelection" class="text-gray-500 hover:underline">Auswahl löschen</button>
                    <button type="button" wire:click="markSelectedHandled" class="ml-auto rounded-lg border border-gray-200 px-2.5 py-1 font-semibold text-gray-700 hover:bg-gray-50">als erledigt</button>
                    <button type="button" wire:click="sendHoldingToSelected" class="rounded-lg bg-gray-900 px-2.5 py-1 font-semibold text-white hover:bg-gray-800">„Wir melden uns"</button>
                </div>
            @endif
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
                <div class="relative flex items-center gap-3 border-b border-gray-200 bg-white px-3 py-2.5 lg:px-5">
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
                                {{-- Beschriftung sagt, WOHIN es geht: Mitarbeiter fuehren in
                                     die MA-Akte, Bewerber in die Bewerberakte. --}}
                                <a href="{{ $selRow->url }}" class="rounded bg-blue-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-blue-700 hover:bg-blue-100" title="{{ $selRow->urlLabel ?? 'Akte' }} öffnen">{{ $selRow->urlLabel ?? 'Akte' }} ↗</a>
                            @endif
                            @if ($selRow->secondaryUrl)
                                <a href="{{ $selRow->secondaryUrl }}" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-gray-600 hover:bg-gray-200" title="Ursprüngliche Bewerbung dieser Person öffnen">{{ $selRow->secondaryLabel }} ↗</a>
                            @endif
                        </div>
                        <div class="truncate text-xs text-gray-500 tabular-nums">{{ $selRow->phone }}</div>
                    </div>
                    <button type="button" wire:click="markUnreadAndClose"
                            title="Chat schließen und wieder als ungelesen markieren — z. B. um später zu antworten"
                            class="shrink-0 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-orange-700 hover:bg-orange-50">
                        ungelesen schließen
                    </button>
                    @if ($showHandled)
                        <button type="button" wire:click="unmarkHandled({{ $selectedThreadId }})"
                                class="shrink-0 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                            zurückholen
                        </button>
                    @else
                        <button type="button" wire:click="markHandled({{ $selectedThreadId }})"
                                class="shrink-0 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">
                            Erledigt
                        </button>
                    @endif
                    @if ($this->selectedRow && $this->selectedRow->subjectType === 'unassigned' && $this->selectedRow->contextLabel === null)
                        <button type="button" wire:click="openLinkPanel({{ $selectedThreadId }})"
                                class="shrink-0 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                            Bewerber zuordnen…
                        </button>
                    @endif
                    @if ($linkingThreadId === $selectedThreadId && $linkingThreadId !== null)
                        <div class="absolute right-4 top-16 z-10 w-80 rounded-xl border border-gray-200 bg-white p-3 shadow-lg">
                            <input type="search" wire:model.live.debounce.300ms="linkSearch" placeholder="Name oder Bewerber-Nummer"
                                   class="w-full rounded-lg border-gray-200 text-sm">
                            <div class="mt-2 max-h-64 overflow-y-auto">
                                @forelse ($this->linkCandidates as $candidate)
                                    <button type="button" wire:click="linkToApplicant({{ $candidate['id'] }})"
                                            class="block w-full rounded px-2 py-1.5 text-left text-sm hover:bg-gray-50">{{ $candidate['label'] }}</button>
                                @empty
                                    <p class="px-2 py-1.5 text-xs text-gray-400">Mindestens zwei Zeichen eingeben.</p>
                                @endforelse
                            </div>
                            <button type="button" wire:click="closeLinkPanel" class="mt-2 text-xs text-gray-500 hover:underline">schließen</button>
                        </div>
                    @endif
                </div>

                {{-- Kontextzeile --}}
                @if ($this->contextChips !== [])
                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-white px-3 py-2 text-xs lg:px-5">
                        @foreach ($this->contextChips as $chip)
                            @if (!empty($chip['url']))
                                {{-- Termin-Chip: fuehrt in die Teilnehmerliste der Schulung. --}}
                                <a href="{{ $chip['url'] }}" wire:navigate
                                   title="Teilnehmerliste dieses Termins öffnen"
                                   class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2 py-1 hover:border-gray-400 hover:bg-white">
                                    <span class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">{{ $chip['label'] }}</span>
                                    <span class="font-semibold text-gray-700">{{ $chip['value'] }}</span>
                                    <span class="text-gray-400">↗</span>
                                </a>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2 py-1">
                                    <span class="text-[10.5px] font-bold uppercase tracking-wider text-gray-400">{{ $chip['label'] }}</span>
                                    <span class="font-semibold text-gray-700">{{ $chip['value'] }}</span>
                                </span>
                            @endif
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

                {{-- Antwortleiste: Freitext nur solange das 24h-Fenster offen ist. Schliesst
                     sich das Fenster waehrend jemand tippt, verschwindet der Entwurf beim
                     naechsten Poll NICHT lautlos: er bleibt lesbar/kopierbar stehen, nur der
                     Sendeweg ist dann gesperrt (siehe @elseif unten). --}}
                <div class="border-t border-gray-200 bg-white px-3 py-2.5 lg:px-5">
                    @if ($this->windowOpen)
                        <div class="flex items-end gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2 pl-3 focus-within:border-gray-400"
                             x-data="{ fit(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 200) + 'px'; } }">
                            <textarea wire:model="replyText" rows="1" placeholder="Antwort schreiben …"
                                      x-init="fit($el)" x-on:input="fit($el)"
                                      x-on:reply-sent.window="$nextTick(() => fit($el))"
                                      class="max-h-[200px] min-h-[36px] w-full resize-none overflow-y-auto border-0 bg-transparent p-1.5 text-sm leading-snug text-gray-900 placeholder:text-gray-400 focus:ring-0"></textarea>
                            <button type="button" wire:click="sendReply" wire:loading.attr="disabled" wire:target="sendReply"
                                    class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-gray-900 text-white hover:bg-gray-800 disabled:opacity-50" aria-label="Senden">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 12 20 4l-4 16-4-7z"/></svg>
                            </button>
                        </div>
                    @else
                        @if (trim($replyText) !== '')
                            <div class="mb-2 rounded-xl border border-amber-200 bg-amber-50 p-3">
                                <p class="whitespace-pre-wrap text-sm text-gray-800">{{ $replyText }}</p>
                                <p class="mt-2 text-xs text-amber-700">Das 24-Stunden-Fenster ist inzwischen zu — der Entwurf bleibt hier stehen. Freitext ist erst wieder möglich, sobald die Person schreibt.</p>
                            </div>
                        @endif
                        {{-- Fenster zu: Meta erlaubt nur Vorlagen. Nur Vorlagen OHNE
                             Body-Platzhalter (chatTemplates() filtert), plus die
                             Eingangsbestaetigung ueber HoldingTemplateSender. --}}
                        <div class="mb-2 flex flex-wrap gap-2">
                            <button type="button" wire:click="sendHoldingTemplate" wire:loading.attr="disabled" wire:target="sendHoldingTemplate"
                                    class="rounded-lg border border-gray-900 bg-gray-900 px-3 py-1.5 text-[13px] font-semibold text-white hover:bg-gray-800 disabled:opacity-60">
                                „Wir melden uns"
                            </button>
                            @foreach ($this->chatTemplates as $template)
                                <button type="button" wire:click="sendTemplate({{ $template['id'] }})" wire:loading.attr="disabled" wire:target="sendTemplate({{ $template['id'] }})"
                                        class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[13px] font-semibold text-gray-600 hover:border-gray-400 disabled:opacity-60">
                                    {{ $template['label'] }}
                                </button>
                            @endforeach
                        </div>
                        <div class="rounded-xl bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
                            Das 24-Stunden-Fenster ist zu — freier Text geht erst wieder, wenn die Person schreibt.
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
