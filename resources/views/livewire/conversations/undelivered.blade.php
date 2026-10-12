{{-- Reiter "Nicht zugestellt": unsere letzte WhatsApp kam nicht an, seitdem keine Antwort.
     Blade-Regeln: PHP-Bloecke nur in Blockform, keine an Woerter geklebten
     Direktiven, keine x-ui-Komponenten. --}}
<div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[360px_1fr]" wire:poll.60s>
    @php
        $rows = $this->rows;
        $sel = $this->selected;
        $whoChip = [
            'person' => ['Person nicht erreichbar', 'bg-red-50 text-red-700'],
            'setup' => ['Vorlage/Konto', 'bg-amber-50 text-amber-800'],
            'other' => ['Zustellfehler', 'bg-gray-100 text-gray-600'],
        ];
    @endphp

    {{-- Liste --}}
    <div class="{{ $sel ? 'hidden lg:flex' : 'flex' }} min-h-0 flex-col border-r border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-4 py-2 text-xs text-gray-500">
            Unsere letzte Nachricht kam nicht an, und die Person hat seitdem nicht geschrieben (letzte {{ \Platform\Recruiting\Services\Comms\UndeliveredQuery::DAYS }} Tage).
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
            @forelse ($rows as $row)
                @php
                    $chip = $whoChip[$row['reason']->who] ?? $whoChip['other'];
                @endphp
                <button type="button" wire:click="select({{ $row['thread_id'] }})" wire:key="undelivered-{{ $row['thread_id'] }}"
                        class="block w-full border-b border-gray-100 border-l-[3px] px-4 py-3 text-left hover:bg-gray-50 {{ ($sel['thread_id'] ?? null) === $row['thread_id'] ? 'border-l-red-600 bg-red-50/40' : 'border-l-transparent' }}">
                    <span class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-semibold text-gray-900">{{ $row['name'] }}</span>
                        <span class="shrink-0 text-[11px] text-gray-400 tabular-nums">{{ $row['failed_label'] }}</span>
                    </span>
                    <span class="mt-0.5 block truncate text-xs text-gray-500">{{ $row['what'] }}</span>
                    <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10.5px] font-semibold">
                        <span class="rounded px-1.5 py-0.5 {{ $chip[1] }}">✕ {{ $chip[0] }}</span>
                        @if ($row['owner_name'])
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600">{{ $row['owner_name'] }}</span>
                        @endif
                        @if ($row['subject_type'] === 'employee')
                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700">MA</span>
                        @endif
                        @if ($row['subject_type'] === 'unassigned')
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-500">nicht zugeordnet</span>
                        @endif
                    </span>
                </button>
            @empty
                <div class="p-8 text-center text-sm text-gray-500">Alles zugestellt — keine offenen Zustellprobleme.</div>
            @endforelse
        </div>
    </div>

    {{-- Detail --}}
    <div class="{{ $sel ? 'flex' : 'hidden lg:flex' }} min-h-0 flex-col bg-gray-50">
        @if ($sel === null)
            <div class="m-auto text-sm text-gray-500">Eintrag auswählen.</div>
        @else
            <div class="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
                <button type="button" wire:click="back" class="text-sm text-blue-700 lg:hidden">‹ zurück</button>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="truncate text-sm font-semibold text-gray-900">{{ $sel['name'] }}</span>
                        @if ($sel['url'])
                            <a href="{{ $sel['url'] }}" class="rounded bg-blue-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-blue-700 hover:bg-blue-100">{{ $sel['url_label'] }} ↗</a>
                        @endif
                    </div>
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $sel['phone']) }}" class="text-xs text-gray-500 tabular-nums hover:underline">{{ $sel['phone'] }}</a>
                </div>
                <button type="button" wire:click="acknowledge({{ $sel['thread_id'] }})"
                        title="Verschwindet aus der Liste, bis wieder eine Nachricht an diese Person scheitert"
                        class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">Erledigt</button>
            </div>

            <div class="border-b border-red-200 bg-red-50 px-4 py-3 text-sm">
                <div class="font-semibold text-red-800">
                    {{ $sel['reason']->text }}
                    @if ($sel['reason']->code !== null)
                        <span class="font-normal text-red-600">(Meta-Code {{ $sel['reason']->code }})</span>
                    @endif
                </div>
                <div class="mt-0.5 text-red-700">{{ $sel['what'] }} vom {{ $sel['failed_label'] }}. {{ $sel['reason']->hint() }}</div>
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-4 py-4"
                 wire:key="undelivered-msgs-{{ $sel['thread_id'] }}-{{ count($this->messages) }}"
                 x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })">
                @include('recruiting::livewire.dispo._messages', ['messages' => $this->messages, 'portalUrl' => null])
            </div>
        @endif
    </div>
</div>
