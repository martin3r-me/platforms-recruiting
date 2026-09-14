{{-- ------------------------------------------------------------------ --}}
{{-- SAMMELVERSAND „ohne Einsatz“ (Clara, 14.09.2026)                     --}}
{{--                                                                      --}}
{{-- Sichtbar nur unter dem Chip „ohne Einsatz“: die Liste darueber IST   --}}
{{-- die Empfaengerliste. Der Block traegt nur die Steuerung — Auswahl-   --}}
{{-- zaehler, Template, Start, Fortschritt; die Haekchen und Badges       --}}
{{-- stehen an den Personen selbst, damit niemand zwei Listen vergleichen --}}
{{-- muss.                                                                --}}
{{--                                                                      --}}
{{-- $this-> statt uebergebener Variablen: die Vertragspruefung           --}}
{{-- (SharedPartialContractTest) liest genau diese Aufrufe.               --}}
{{-- ------------------------------------------------------------------ --}}
@php
    $versandZeilen = $this->noAssignmentRows;
    $versandGewaehlt = count($this->noAssignmentSelectedIds());
    $versandFortschritt = $this->noAssignmentProgress;
    $versandLaeuft = $versandFortschritt !== null && !($versandFortschritt['done'] ?? false);
    $versandPoll = $versandLaeuft ? 'wire:poll.3s' : '';
@endphp
<div class="mt-3 rounded-lg border border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)] p-3" {!! $versandPoll !!}>
    @if ($versandFortschritt !== null)
        <div class="text-sm">
            <strong>{{ $versandFortschritt['sent'] }}</strong> / {{ $versandFortschritt['total'] }} gesendet
            · {{ $versandFortschritt['failed'] }} Fehler · {{ $versandFortschritt['skipped'] }} übersprungen
            {!! ($versandFortschritt['done'] ?? false) ? ' · <span class="text-green-700">abgeschlossen</span>' : ' · läuft …' !!}
        </div>
        @if (!empty($versandFortschritt['errors']))
            <ul class="mt-1 list-disc pl-4 text-xs text-red-700">
                @foreach ($versandFortschritt['errors'] as $zeile)
                    <li>{{ $zeile }}</li>
                @endforeach
            </ul>
        @endif
    @else
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-[color:var(--ui-muted)]">
            <span><strong>{{ $versandGewaehlt }}</strong> von {{ count($versandZeilen) }} ausgewählt — sie bekommen die Nachfrage per WhatsApp, eine Nachricht nacheinander.</span>
            <span class="flex gap-2">
                <button type="button" class="underline" wire:click="noAssignmentSelectAll(true)">alle</button>
                <button type="button" class="underline" wire:click="noAssignmentSelectAll(false)">keine</button>
            </span>
        </div>
        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
            <x-ui-input-select
                :value="$this->noAssignmentTemplate"
                name="noAssignmentTemplate"
                label="WhatsApp-Template"
                :options="$this->campaignTemplates"
                optionValue="id"
                optionLabel="label"
                :nullable="true"
                nullLabel="– Template wählen –"
                displayMode="dropdown"
                wire:model.live="noAssignmentTemplate"
            />
            <div class="flex items-end justify-end">
                <x-ui-button variant="primary" wire:click="startNoAssignmentCampaign"
                             wire:loading.attr="disabled" wire:target="startNoAssignmentCampaign"
                             :disabled="$versandGewaehlt === 0">
                    An {{ $versandGewaehlt }} {{ $versandGewaehlt === 1 ? 'Person' : 'Personen' }} senden
                </x-ui-button>
            </div>
        </div>
        @if ($this->noAssignmentError !== '')
            <div class="mt-2 text-xs text-red-700">{{ $this->noAssignmentError }}</div>
        @endif
    @endif
</div>
