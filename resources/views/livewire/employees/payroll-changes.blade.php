<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Lohnrelevante Aenderungen" icon="heroicon-o-banknotes" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Mitarbeiter', 'href' => route('recruiting.employees.index')],
            ['label' => 'Lohnrelevante Aenderungen'],
        ]">
            @if($this->hasChanges)
                <a href="{{ route('recruiting.employees.payroll-changes.csv', ['dry_run' => 1]) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-[var(--ui-secondary)] bg-white border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] rounded-md transition">
                    @svg('heroicon-o-eye', 'w-4 h-4')
                    Vorschau (nicht quittieren)
                </a>
                <a href="{{ route('recruiting.employees.payroll-changes.csv') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-md transition"
                   onclick="setTimeout(() => { $wire.$refresh() }, 1500)">
                    @svg('heroicon-o-arrow-down-tray', 'w-4 h-4')
                    CSV exportieren & quittieren
                </a>
            @endif
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="full">
        @if(!$this->hasChanges)
            <div class="bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg p-8 text-center text-sm text-[var(--ui-muted)]">
                Keine offenen lohnrelevanten Aenderungen.
            </div>
        @else
            <div class="bg-white border border-[var(--ui-border)] rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-[var(--ui-muted-5)] border-b border-[var(--ui-border)]">
                            <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Mitarbeiter</th>
                            <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">PersNr</th>
                            <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Feld</th>
                            <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Alter Wert</th>
                            <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Neuer Wert</th>
                            <th class="text-left px-4 py-2.5 font-medium text-[var(--ui-muted)]">Geaendert am</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]">
                        @foreach($this->rows as $row)
                            <tr class="hover:bg-[var(--ui-muted-5)] transition">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('recruiting.employees.show', $row['employee_id']) }}"
                                       class="text-[var(--ui-secondary)] hover:underline font-medium"
                                       wire:navigate>
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-2.5 text-[var(--ui-muted)]">{{ $row['personnel_number'] }}</td>
                                <td class="px-4 py-2.5">{{ $row['label'] }}</td>
                                <td class="px-4 py-2.5 text-[var(--ui-muted)]">{{ $row['old'] }}</td>
                                <td class="px-4 py-2.5 font-medium">{{ $row['new'] }}</td>
                                <td class="px-4 py-2.5 text-[var(--ui-muted)] text-xs">
                                    @if($row['at'])
                                        {{ \Carbon\Carbon::parse($row['at'])->format('d.m.Y H:i') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-2 text-xs text-[var(--ui-muted)] text-right">
                {{ count($this->rows) }} {{ count($this->rows) === 1 ? 'Aenderung' : 'Aenderungen' }}
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
