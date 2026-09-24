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
        {{--
            Aufenthalt und Arbeitsgenehmigung — zur Kenntnis. Reine Anzeige,
            kein Bestaetigen-Knopf: ein Upload spiegelt sein Datum sofort in
            die Akte und den ZAS-Export, eine Bestaetigung danach haette
            nichts mehr zu verhindern (es gibt fuer Mitarbeiter keine
            Einsatzsperre, die an diesem Datum haengt). Diese Liste sagt HR
            nur, dass etwas Neues eingegangen ist.
        --}}
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-2">Aufenthalt und Arbeitsgenehmigung — zur Kenntnis</h3>
            <p class="text-xs text-[var(--ui-muted)] mb-3">
                Zur Information, wenn ein neuer Aufenthaltstitel oder eine neue Arbeitsgenehmigung eingegangen ist.
                Hier ist nichts zu tun — der Nachweis gilt mit dem Upload bereits als erledigt.
            </p>

            @if($this->wartetAufBestaetigung->isEmpty())
                <div class="bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg p-6 text-center text-sm text-[var(--ui-muted)]">
                    Nichts Neues.
                </div>
            @else
                <div class="mb-2 text-xs text-[var(--ui-muted)]">
                    {{ $this->wartetAufBestaetigung->total() }}
                    {{ $this->wartetAufBestaetigung->total() === 1 ? 'Nachweis' : 'Nachweise' }}.
                </div>
                <div class="bg-white border border-[var(--ui-border)] rounded-lg overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-[var(--ui-muted-5)] border-b border-[var(--ui-border)]">
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Mitarbeiter</th>
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Nachweis</th>
                                <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Gültig bis</th>
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
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">
                    {{ $this->wartetAufBestaetigung->links() }}
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
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200"
                                                  title="{{ $row['confirmed_by'] ? 'bestätigt von ' . $row['confirmed_by'] . ' am ' . $row['confirmed_at_human'] : '' }}">
                                                Bestätigt{{ $row['confirmed_by'] ? ' von ' . $row['confirmed_by'] : '' }}
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
