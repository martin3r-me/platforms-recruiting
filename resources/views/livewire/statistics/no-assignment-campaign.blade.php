{{-- ------------------------------------------------------------------ --}}
{{-- SAMMELVERSAND „ohne Einsatz“ (Clara, 14.09.2026)                     --}}
{{--                                                                      --}}
{{-- Sichtbar nur unter dem Chip „ohne Einsatz“: die Liste darueber IST   --}}
{{-- die Empfaengerliste (Haken + Chip „bekommt Nachfrage“ an der Person). --}}
{{-- Der Block traegt Erklaerung, Nachricht, Start und Fortschritt — im    --}}
{{-- Muster des Kampagnen-Modals der Statistik (UX-Paket 06.10./12.10.):   --}}
{{-- Vorlagen-TEXT statt technischem Namen, Auswahl hinter „Vorlage        --}}
{{-- ändern“, Bestaetigung vor dem Versand.                               --}}
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
    // Wieviele der Gewaehlten haben die Nachfrage schon einmal bekommen? Der
    // Haken dafuer wird von Hand gesetzt („alle“ ueberspringt sie) — dann soll
    // aber auch dastehen, was man da gerade tut.
    $versandWiederholung = 0;
    foreach ($this->noAssignmentSelectedIds() as $versandId) {
        if (($versandZeilen[$versandId]['checked'] ?? true) === false) {
            $versandWiederholung++;
        }
    }
    $versandPersonen = $versandGewaehlt === 1 ? 'Person' : 'Personen';
@endphp
<div class="mt-3 space-y-3 rounded-lg border border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)] p-3" {!! $versandPoll !!}>
    @if ($versandFortschritt !== null)
        <div class="text-sm">
            <strong>{{ $versandFortschritt['sent'] }}</strong> von {{ $versandFortschritt['total'] }} Nachfragen gesendet
            @if ($versandFortschritt['failed'] > 0)
                · <span class="text-red-700">{{ $versandFortschritt['failed'] }} fehlgeschlagen</span>
            @endif
            @if ($versandFortschritt['skipped'] > 0)
                · {{ $versandFortschritt['skipped'] }} übersprungen
            @endif
            {!! ($versandFortschritt['done'] ?? false) ? ' · <span class="text-green-700">fertig</span>' : ' · läuft …' !!}
        </div>
        @if (!empty($versandFortschritt['errors']))
            <ul class="list-disc pl-4 text-xs text-red-700">
                @foreach ($versandFortschritt['errors'] as $zeile)
                    <li>{{ $zeile }}</li>
                @endforeach
            </ul>
        @endif
        @if ($versandFortschritt['done'] ?? false)
            <div class="text-xs text-[color:var(--ui-muted)]">
                Antworten landen in der Kommunikation.
                <button type="button" class="ml-2 underline" wire:click="resetNoAssignmentCampaign">Weitere Personen anschreiben</button>
            </div>
        @endif
    @else
        @php
            $vorschau = $this->noAssignmentPreview;
            $problem = $this->noAssignmentTemplateProblem;
            $kartenOffen = $problem !== null ? 'true' : 'false';
            $bestaetigung = \Platform\Recruiting\Livewire\Statistics\Index::noAssignmentConfirmText($versandGewaehlt, $versandWiederholung);
        @endphp
        <p class="text-xs text-[color:var(--ui-secondary)]">
            <strong>Nachfrage an Teilnehmer ohne Einsatz:</strong> Die angehakten Personen bekommen eine WhatsApp, ob sie bei uns starten möchten.
            Antworten landen in der Kommunikation. Es folgen keine automatischen Erinnerungen.
        </p>

        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[color:var(--ui-muted)]">
            <span>
                <strong class="text-[color:var(--ui-secondary)]">{{ $versandGewaehlt }} {{ $versandPersonen }}</strong> {{ $versandGewaehlt === 1 ? 'bekommt' : 'bekommen' }} die Nachfrage
                (von {{ count($versandZeilen) }} ohne Einsatz)
                @if ($versandWiederholung > 0)
                    · <span class="font-medium text-amber-700">davon {{ $versandWiederholung }} schon einmal angeschrieben</span>
                @endif
            </span>
            <span class="flex gap-2">
                <button type="button" class="underline" wire:click="noAssignmentSelectAll(true)">alle</button>
                <button type="button" class="underline" wire:click="noAssignmentSelectAll(false)">keine</button>
            </span>
        </div>

        {{-- wire:key: Alpine-Zustand „Vorlage ändern“ gehoert zu genau dieser Karte. --}}
        <div wire:key="sammelversand-karte" class="rounded-lg border border-[var(--ui-border)]/60 p-3 text-xs" x-data="{ aendern: {{ $kartenOffen }} }">
            <div class="mb-1 flex items-center justify-between gap-2">
                <span class="inline-block rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-medium text-emerald-900">Nachricht „Nachfrage“</span>
                <span class="text-[color:var(--ui-muted)]">an {{ $versandGewaehlt }} {{ $versandPersonen }}</span>
            </div>
            @if ($problem === null && $vorschau !== null)
                {{-- Sprechblase: der Text mit Beispielnamen, dann statische Buttons --}}
                <div class="rounded-lg bg-[var(--ui-muted-5)] px-3 py-2 text-[color:var(--ui-secondary)] whitespace-pre-line">{{ $vorschau['text'] }}</div>
                @foreach ($vorschau['buttons'] as $buttonText)
                    <div class="mt-1 rounded-lg border border-[var(--ui-border)]/60 px-3 py-1 text-center font-medium text-[color:var(--ui-primary)]">{{ $buttonText }}</div>
                @endforeach
                <div class="mt-1 flex items-center justify-between text-[11px] text-[color:var(--ui-muted)]">
                    <span title="Beispielname — beim Versand steht der Vorname der Person. Bild- oder Dokument-Kopfzeilen der Vorlage sind hier nicht abgebildet. Technischer Name der Vorlage bei Meta: {{ $vorschau['label'] }}">Beispiel mit „{{ \Platform\Recruiting\Support\CampaignTemplatePreview::BEISPIEL_VORNAME }}“</span>
                    <button type="button" class="underline" x-on:click="aendern = !aendern" x-text="aendern ? 'Auswahl schließen' : 'Vorlage ändern'">Vorlage ändern</button>
                </div>
            @else
                {{-- nicht gewaehlt (rot) / nicht verfuegbar oder vom Versand abgelehnt (amber) — Start gesperrt --}}
                <div class="rounded-lg border {{ $this->noAssignmentTemplate ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-red-200 bg-red-50 text-red-800' }} px-3 py-2">
                    @if ($vorschau !== null)
                        <span class="font-medium">Vorlage „{{ $vorschau['label'] }}“:</span>
                    @endif
                    {{ $problem }}
                </div>
            @endif
            <div x-show="aendern" style="display: none;" class="mt-2">
                <x-ui-input-select
                    :value="$this->noAssignmentTemplate"
                    name="noAssignmentTemplate"
                    label="Vorlage (Meta-Name)"
                    :options="$this->campaignTemplates"
                    optionValue="id"
                    optionLabel="label"
                    :nullable="true"
                    nullLabel="– Vorlage wählen –"
                    displayMode="dropdown"
                    wire:model.live="noAssignmentTemplate"
                />
            </div>
        </div>

        @if ($this->noAssignmentError !== '')
            <div class="text-xs text-red-700">{{ $this->noAssignmentError }}</div>
        @endif
        <div class="flex justify-end">
            {{-- Bestaetigung VOR dem Versand: 21 Nachrichten sind kein Klick, den man zurueckholt. --}}
            <x-ui-button variant="primary" wire:click="startNoAssignmentCampaign" wire:confirm="{{ $bestaetigung }}"
                         wire:loading.attr="disabled" wire:target="startNoAssignmentCampaign"
                         :disabled="$versandGewaehlt === 0 || $problem !== null">
                WhatsApp an {{ $versandGewaehlt }} {{ $versandPersonen }} senden
            </x-ui-button>
        </div>
    @endif
</div>
