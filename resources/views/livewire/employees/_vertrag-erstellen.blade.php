{{-- Rumpf des Fensters "Vertrag erstellen" (Spec 2026-10-09 §2.1). Ohne $this: erwartet $vertragVorlagen, $vertragFirma, $fehler; optional $zuschlagHinweis. --}}
<div class="p-4 space-y-4">
    @include('recruiting::livewire.employees._modal-fehler', ['fehler' => $fehler])
    @if(empty($vertragVorlagen))
        <p class="text-sm text-amber-700">
            Für die Gesellschaft {{ $vertragFirma !== '' ? $vertragFirma : '—' }} ist keine Arbeitsvertrags-Vorlage angelegt, die den eingetragenen Zuschlag übernimmt.
        </p>
    @else
        <div>
            <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Vertragsart</label>
            <select wire:model="vertragVorlageId" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                <option value="">– Vertragsart wählen –</option>
                @foreach($vertragVorlagen as $vv)
                    <option value="{{ $vv['id'] }}">{{ $vv['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Beginn</label>
            <input type="date" wire:model="vertragBeginn"
                   class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm" />
        </div>
        <div>
            <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Ende</label>
            <input type="date" wire:model="vertragEnde"
                   class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm" />
            <p class="text-xs text-[var(--ui-muted)] mt-1">
                Leer = ein Jahr ab Beginn, zum Monatsende. Für MA-Monatsverträge den Monatsletzten eintragen.
            </p>
        </div>
        <div>
            <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Zuschlag (€/Std)</label>
            <input type="text" wire:model="vertragZuschlag" placeholder="0,60"
                   class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm" />
            <p class="text-xs text-[var(--ui-muted)] mt-1">0 ist erlaubt.</p>
            @if(!empty($zuschlagHinweis))
                <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">{{ $zuschlagHinweis }}</p>
            @endif
        </div>
    @endif
</div>
