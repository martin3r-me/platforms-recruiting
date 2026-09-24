<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Nachweise" icon="heroicon-o-inbox-stack" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Mitarbeiter', 'href' => route('recruiting.employees.index')],
            ['label' => 'Nachweise'],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="full">
        @if($flash)
            <div class="mb-4 p-2 bg-green-50 border border-green-200 rounded text-xs text-green-800 inline-flex items-center gap-2">
                @svg('heroicon-o-check-circle', 'w-4 h-4')
                {{ $flash }}
            </div>
        @endif

        {{-- Wartet auf Bestaetigung: die EINE Stelle, an der ein Mensch bestaetigt.
             Nur Aufenthaltstitel und Arbeitsgenehmigung — daran haengt die harte
             Einsatzsperre. Alles andere lief bereits ohne Freigabe durch. --}}
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-2">Wartet auf Bestätigung</h3>
            <p class="text-xs text-[var(--ui-muted)] mb-3">
                Nur Aufenthaltstitel und Arbeitsgenehmigung — daran hängt die harte Einsatzsperre.
                Alle anderen Nachweise gelten mit dem Upload sofort als erledigt.
            </p>

            @if(empty($this->wartetAufBestaetigung))
                <div class="bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg p-6 text-center text-sm text-[var(--ui-muted)]">
                    Nichts offen.
                </div>
            @else
                <div class="bg-white border border-amber-200 rounded-lg overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-amber-50 border-b border-amber-200">
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Mitarbeiter</th>
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Nachweis</th>
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Gültig bis</th>
                                <th class="text-right px-4 py-2.5 font-medium text-[var(--ui-muted)]"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]">
                            @foreach($this->wartetAufBestaetigung as $row)
                                <tr class="hover:bg-[var(--ui-muted-5)] transition">
                                    <td class="px-4 py-2.5">
                                        <a href="{{ route('recruiting.employees.show', $row['employee_id']) }}"
                                           class="text-[var(--ui-secondary)] hover:underline font-medium"
                                           wire:navigate>
                                            {{ $row['name'] }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2.5">{{ $row['label'] }}</td>
                                    <td class="px-4 py-2.5 text-[var(--ui-muted)]">
                                        {{ $row['valid_until'] ? \Carbon\Carbon::parse($row['valid_until'])->format('d.m.Y') : '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <button type="button" wire:click="bestaetige({{ $row['id'] }})"
                                                wire:confirm="Datum geprüft — bestätigen?"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-md transition">
                                            @svg('heroicon-o-check', 'w-3.5 h-3.5')
                                            Bestätigen
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Neu eingegangen: reine Anzeige, kein Handlungsbedarf. --}}
        <div>
            <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-2">Neu eingegangen</h3>

            @if(empty($this->neuEingegangen))
                <div class="bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg p-8 text-center text-sm text-[var(--ui-muted)]">
                    Noch nichts eingegangen.
                </div>
            @else
                <div class="bg-white border border-[var(--ui-border)] rounded-lg overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-[var(--ui-muted-5)] border-b border-[var(--ui-border)]">
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Mitarbeiter</th>
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Nachweis</th>
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Eingegangen am</th>
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]">
                            @foreach($this->neuEingegangen as $row)
                                <tr class="hover:bg-[var(--ui-muted-5)] transition">
                                    <td class="px-4 py-2.5">
                                        <a href="{{ route('recruiting.employees.show', $row['employee_id']) }}"
                                           class="text-[var(--ui-secondary)] hover:underline font-medium"
                                           wire:navigate>
                                            {{ $row['name'] }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2.5">{{ $row['label'] }}</td>
                                    <td class="px-4 py-2.5 text-[var(--ui-muted)] text-xs">
                                        {{ \Carbon\Carbon::parse($row['created_at'])->format('d.m.Y H:i') }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @if(!$row['needs_confirmation'])
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Erledigt
                                            </span>
                                        @elseif($row['confirmed'])
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Bestätigt
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200">
                                                Wartet auf Bestätigung
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-2 text-xs text-[var(--ui-muted)] text-right">
                    {{ count($this->neuEingegangen) }} {{ count($this->neuEingegangen) === 1 ? 'Nachweis' : 'Nachweise' }}
                </div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
