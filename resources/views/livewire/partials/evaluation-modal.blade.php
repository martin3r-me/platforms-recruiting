{{-- Bewertungs-Modal der Schulungsnachbereitung — acht Felder in einem
     Vorgang (Spec §3).

     Geteilt zwischen der grossen Buchungsliste und der schlanken
     Teamleiter-Ansicht (14.09.2026). Der Zustand kommt aus dem Trait
     HandlesEvaluationModal; die nutzende Komponente muss `bookings`,
     `evaluationValues` und `contactCandidatesFor` anbieten. --}}
    {{-- Bewertungs-Modal: acht Felder in einem Vorgang (Spec §3) --}}
    <x-ui-modal wire:model="showEvaluationModal" size="lg">
        <x-slot name="header">Bewertung</x-slot>
        @php
            $evalBooking = $evaluateBookingId ? $this->bookings->firstWhere('id', $evaluateBookingId) : null;
            $evalName = \Platform\Recruiting\Support\ApplicantContactName::display(
                $this->contactCandidatesFor($evalBooking?->applicant),
            );
            $evalCriteria = \Platform\Recruiting\Support\RatingCriteria::CRITERIA;
        @endphp
        {{-- wire:key am Modal-Inhalt, gekoppelt an die Buchung: beim Personenwechsel
             verwirft Livewire den Teilbaum statt ihn zu morphen. Ohne das muesste
             das Morphing bei fuenf Radios das checked-Attribut ENTFERNEN, und
             genau dort laufen Attribut und DOM-Property auseinander — die Sterne
             der zuvor bewerteten Person koennten stehen bleiben. Seit das
             Highlight aus peer-checked kommt (nicht mehr aus einer serverseitig
             berechneten Klasse), waere das nicht als Widerspruch sichtbar,
             sondern saehe stimmig aus: Speichern schriebe die Bewertung der
             falschen Person.

             ZUSAMMENSPIEL, nicht Redundanz: wire:key verhindert das Morphing
             des Teilbaums, @checked() an den Radios liefert den Initialzustand
             des NEU gerenderten Teilbaums. Beides zusammen traegt, einzeln
             nicht — wer @checked() "aufraeumt", verliert die Vorbelegung beim
             Oeffnen; wer wire:key entfernt, holt die Fremdwerte-Anzeige
             zurueck. --}}
        <div class="space-y-5" wire:key="eval-modal-{{ $evaluateBookingId }}">
            @if($evalBooking)
                <div class="text-sm">
                    <strong class="text-[var(--ui-secondary)]">{{ $evalName }}</strong>
                </div>

                {{-- Fünf Kriterien, je 1-5 Sterne --}}
                @foreach($evalCriteria as $critKey => $crit)
                    <div>
                        <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">
                            {{ $crit['label'] }}
                            @if($crit['help'] !== '')
                                <span class="ml-1 text-[var(--ui-muted)] cursor-help" title="{{ $crit['help'] }}">
                                    @svg('heroicon-o-information-circle', 'w-3.5 h-3.5 inline-block -mt-0.5')
                                </span>
                            @endif
                        </label>
                        {{-- Highlight per peer-checked (reines CSS) statt serverseitig
                             berechneter Border-Klasse. Mit wire:model.live waere jeder
                             einzelne Sternklick ein Roundtrip mit vollem Component-Render
                             (bookings, bookingsSortedByName, bulkSendState, selfies) —
                             bei fuenf Kriterien x 20 Teilnehmern 100 Roundtrips pro
                             Schulung. Deferred wire:model schickt die Werte erst beim
                             Speichern; sichtbares Verhalten identisch, null Requests
                             pro Klick.
                             Struktur beachten: 'peer' wirkt nur auf GESCHWISTER, also
                             Input und <span> als Kinder des Labels — nicht das Label
                             selbst stylen. Gleiches Muster wie
                             styles/platforms-ui-tailwind/.../status-toggle.blade.php:44-48. --}}
                        <div class="flex gap-2">
                            @foreach(['1','2','3','4','5'] as $star)
                                <label class="flex-1 cursor-pointer">
                                    <input
                                        type="radio"
                                        wire:model="evaluation.{{ $critKey }}"
                                        value="{{ $star }}"
                                        @checked(($evaluation[$critKey] ?? null) === $star)
                                        class="sr-only peer"
                                    >
                                    <span class="inline-flex w-full items-center justify-center gap-1.5 px-3 py-2 text-sm rounded-md border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)] peer-checked:border-amber-500 peer-checked:bg-amber-50 peer-checked:text-amber-700">
                                        @svg('heroicon-m-star', 'w-4 h-4')
                                        {{ $star }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- Waeschepaket --}}
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Wäschepaket erhalten</label>
                    <div class="border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm bg-white flex flex-wrap gap-x-4 gap-y-1.5">
                        @forelse($this->lookupOptionsFor('waeschepaket') as $optValue => $optLabel)
                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="checkbox" wire:model="evaluation.linen_package_items" value="{{ $optValue }}" class="rounded border-[var(--ui-border)]">
                                <span>{{ $optLabel }}</span>
                            </label>
                        @empty
                            <span class="text-xs text-[var(--ui-muted)]">Keine Lookup-Werte konfiguriert.</span>
                        @endforelse
                    </div>
                </div>

                {{-- Qualifikation --}}
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Qualifikation</label>
                    <div class="border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm bg-white flex flex-wrap gap-x-4 gap-y-1.5">
                        @forelse($this->lookupOptionsFor('qualifikation') as $optValue => $optLabel)
                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="checkbox" wire:model="evaluation.qualifications" value="{{ $optValue }}" class="rounded border-[var(--ui-border)]">
                                <span>{{ $optLabel }}</span>
                            </label>
                        @empty
                            <span class="text-xs text-[var(--ui-muted)]">Keine Lookup-Werte konfiguriert.</span>
                        @endforelse
                    </div>
                </div>

                {{-- Bewertungstext (NICHT die Buchungsnotiz, Spec F4) --}}
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Bewertungstext</label>
                    <textarea
                        wire:model="evaluation.evaluation_note"
                        rows="4"
                        placeholder="Individuelle Einschätzung zum Abschluss…"
                        class="w-full border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm"
                    ></textarea>
                </div>
            @else
                <div class="text-sm text-[var(--ui-muted)]">Buchung nicht gefunden.</div>
            @endif
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary" wire:click="closeEvaluationModal">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="saveEvaluation">Speichern</x-ui-button>
        </x-slot>
    </x-ui-modal>
