@php
    $kategorien = \Platform\Recruiting\Support\DokumentKategorie::labels();
    $aktionen = \Platform\Recruiting\Support\DokumentKategorie::aktionen();
    $kandidaten = $this->kandidaten;
    $abgeschnitten = $this->abgeschnitten;
    $gewaehlt = count($kandidaten) - count(array_intersect($abgewaehlt, array_column($kandidaten, 'id')));
    $ohnePortal = count(array_filter($kandidaten, fn ($k) => !$k['hat_portal'] && !in_array($k['id'], $abgewaehlt, true)));
    $dokumente = $this->dokumente;
    $versandLaeuft = count(array_filter($dokumente, fn ($d) => $d['ausstehend'] > 0)) > 0;
@endphp
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Dokumente" icon="heroicon-o-document-text" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Mitarbeiter', 'href' => route('recruiting.employees.index')],
            ['label' => 'Dokumente'],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="full">
        @if ($flash)
            <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-md">{{ $flash }}</div>
        @endif
        @if ($flashError)
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 text-sm rounded-md">{{ $flashError }}</div>
        @endif

        {{-- 1. Dokument --}}
        <div class="bg-white border border-[var(--ui-border)] rounded-lg p-4">
            <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">1 · Dokument</h3>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                <div class="md:col-span-4">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">PDF</label>
                    <input type="file" accept=".pdf" wire:model="datei" class="block w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100">
                    <div wire:loading wire:target="datei" class="text-xs text-[var(--ui-muted)] mt-1">Wird hochgeladen …</div>
                </div>
                <div class="md:col-span-4">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Titel</label>
                    <input type="text" wire:model="titel" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Kategorie</label>
                    <select wire:model.live="kategorie" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        @foreach ($kategorien as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Der Mitarbeiter soll</label>
                    <select wire:model="aktion" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        @foreach ($aktionen as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- 2. Empfaenger --}}
        <div class="mt-4 bg-white border border-[var(--ui-border)] rounded-lg p-4">
            <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">2 · Empfänger</h3>
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Suche</label>
                    <input type="text" wire:model.live.debounce.300ms="suche" placeholder="Name oder Personalnummer" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Firma</label>
                    <select wire:model.live="firma" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        <option value="">Alle</option>
                        <option value="RG">RG</option>
                        <option value="MA">MA</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Status</label>
                    <select wire:model.live="aktiv" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        <option value="active">Aktiv</option>
                        <option value="inactive">Inaktiv</option>
                        <option value="all">Alle</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Tätigkeit (ZAS, nur RG)</label>
                    <select wire:model.live="taetigkeit" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        <option value="">Alle</option>
                        @foreach ($this->taetigkeitOptionen as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Veranstaltung</label>
                    <select wire:model.live="eventId" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        <option value="">Keine</option>
                        @foreach ($this->eventOptionen as $ev)
                            <option value="{{ $ev['id'] }}">{{ $ev['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-between">
                <div class="text-sm">
                    <span class="font-medium">{{ $gewaehlt }}</span> von {{ count($kandidaten) }} ausgewählt
                    @if ($abgeschnitten)
                        <span class="text-xs font-medium text-red-700">· Mehr als {{ \Platform\Recruiting\Services\DokumentEmpfaengerSuche::LIMIT }} Treffer — bitte Filter eingrenzen (z. B. Firma, Tätigkeit oder Veranstaltung).</span>
                    @endif
                    @if ($ohnePortal > 0)
                        <span class="text-xs text-amber-700">· {{ $ohnePortal }} noch im alten Portal, bekommen keine WhatsApp</span>
                    @endif
                </div>
                <button type="button" wire:click="$toggle('waehlerOffen')" class="text-xs text-blue-600 hover:underline">{{ $waehlerOffen ? 'Liste einklappen' : 'Liste anzeigen und einzelne abwählen' }}</button>
            </div>

            @if ($waehlerOffen)
                <div class="mt-2 max-h-80 overflow-y-auto border border-[var(--ui-border)] rounded-md divide-y divide-[var(--ui-border)]/60">
                    @forelse ($kandidaten as $k)
                        @php $kAn = !in_array($k['id'], $abgewaehlt, true); @endphp
                        <label class="flex items-center gap-3 px-3 py-1.5 text-sm cursor-pointer hover:bg-[var(--ui-muted-5)]">
                            <input type="checkbox" wire:click="toggle({{ $k['id'] }})" @checked($kAn)>
                            <span class="flex-1">{{ $k['name'] }}</span>
                            <span class="text-xs text-[var(--ui-muted)]">{{ $k['personnel_number'] ?? '—' }} · {{ $k['company'] ?? '—' }}</span>
                            @if (!$k['hat_portal'])
                                <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200">altes Portal</span>
                            @endif
                        </label>
                    @empty
                        <div class="px-3 py-4 text-sm text-[var(--ui-muted)]">Keine Treffer.</div>
                    @endforelse
                </div>
            @endif

            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="bereitstellen" wire:loading.attr="disabled" wire:target="bereitstellen,datei" @disabled($abgeschnitten)
                        class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 disabled:opacity-60">
                    An {{ $gewaehlt }} Personen bereitstellen
                </button>
            </div>
        </div>

        {{-- 3. Ueberblick --}}
        <div class="mt-6">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold text-[var(--ui-secondary)]">Unterwegs und erledigt</h3>
                <select wire:model.live="zeige" class="border border-[var(--ui-border)] rounded-md px-2 py-1 text-xs bg-white">
                    <option value="offen">Nur offene</option>
                    <option value="alle">Alle</option>
                </select>
            </div>
            <div class="bg-white border border-[var(--ui-border)] rounded-lg divide-y divide-[var(--ui-border)]/60" @if ($versandLaeuft) wire:poll.5s @endif>
                @forelse ($dokumente as $dok)
                    @php
                        $dokOffen = $aufgeklappt === $dok['id'];
                        $dokBalken = $dok['fortschritt']['gesamt'] > 0 ? (int) round(100 * $dok['fortschritt']['erledigt'] / $dok['fortschritt']['gesamt']) : 0;
                    @endphp
                    <div class="p-3">
                        <div class="flex items-center gap-3">
                            <button type="button" wire:click="aufklappen({{ $dok['id'] }})" class="flex-1 text-left">
                                <div class="text-sm font-medium">{{ $dok['title'] }} @if ($dok['geloescht'])<span class="text-xs text-[var(--ui-muted)]">(zurückgezogen)</span>@endif</div>
                                <div class="text-xs text-[var(--ui-muted)]">{{ $dok['category_label'] }} · {{ $dok['action_label'] }} · {{ $dok['erstellt'] }}@if ($dok['event']) · {{ $dok['event'] }}@endif</div>
                            </button>
                            <div class="w-44">
                                <div class="text-xs text-right mb-1">{{ $dok['fortschritt']['text'] }}</div>
                                @if ($dok['ausstehend'] > 0)
                                    <div class="text-[10px] text-right text-amber-700">{{ $dok['benachrichtigt'] }} von {{ $dok['benachrichtigt'] + $dok['ausstehend'] }} benachrichtigt, läuft …</div>
                                @elseif ($dok['action'] !== 'none')
                                    <div class="text-[10px] text-right text-[var(--ui-muted)]">{{ $dok['benachrichtigt'] }} benachrichtigt</div>
                                @endif
                                <div class="h-1.5 bg-[var(--ui-muted-5)] rounded-full overflow-hidden"><div class="h-full bg-emerald-500" style="width: {{ $dokBalken }}%"></div></div>
                            </div>
                            <a href="{{ route('recruiting.employees.dokument.datei', ['uuid' => $dok['uuid']]) }}" target="_blank" class="px-3 py-1.5 border border-[var(--ui-border)] text-xs rounded-md bg-white hover:bg-[var(--ui-muted-5)]">PDF</a>
                            @if (!$dok['geloescht'] && !$dok['erledigt'])
                                <button type="button" wire:click="dokumentZurueckziehen({{ $dok['id'] }})" wire:confirm="„{{ $dok['title'] }}“ für alle Offenen zurückziehen?" class="px-3 py-1.5 border border-[var(--ui-border)] text-xs rounded-md text-[var(--ui-muted)] hover:bg-red-50 hover:text-red-700">Zurückziehen</button>
                            @endif
                        </div>
                        @if ($dokOffen)
                            <div class="mt-3 border-t border-[var(--ui-border)]/60 pt-2 space-y-1">
                                @foreach ($dok['empfaenger'] as $em)
                                    @php
                                        $emVersand = $em['versand_text'];
                                        $emZeigtErneut = $em['action'] !== 'none' && $em['status'] !== 'zurueckgezogen' && !$em['benachrichtigt'] && !$em['versand_laeuft'];
                                    @endphp
                                    <div class="flex items-center gap-3 text-sm">
                                        <a href="{{ route('recruiting.employees.show', $em['employee_id']) }}" wire:navigate class="flex-1 hover:underline">{{ $em['name'] }}</a>
                                        <span class="text-xs">{{ $em['status_label'] }}</span>
                                        <span class="text-xs text-[var(--ui-muted)] w-44">{{ $emVersand }}</span>
                                        @if ($em['hat_nachweis'])
                                            <a href="{{ route('recruiting.employees.dokument.nachweis', ['uuid' => $em['recipient_uuid']]) }}" target="_blank" class="text-xs text-emerald-700 hover:underline">Nachweis</a>
                                        @endif
                                        @if ($emZeigtErneut)
                                            <button type="button" wire:click="erneutSenden({{ $em['recipient_id'] }})" class="text-xs text-blue-700 hover:underline">Erneut senden</button>
                                        @endif
                                        @if ($em['kann_zurueckziehen'])
                                            <button type="button" wire:click="zurueckziehen({{ $em['recipient_id'] }})" class="text-xs text-[var(--ui-muted)] hover:text-red-700">Zurückziehen</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-6 text-center text-sm text-[var(--ui-muted)]">Nichts offen.</div>
                @endforelse
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
