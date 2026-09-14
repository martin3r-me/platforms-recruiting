{{-- Schlanke Nachbereitung fuer Teamleiter-Konten (14.09.2026).

     Bewusst DERSELBE Aufbau wie die Schulungsnachbereitung: Termin-Panel oben,
     darunter die Teilnehmertabelle mit Foto und Anwesenheits-Select an
     denselben Stellen. Wer beide Ansichten kennt, soll sich nicht umgewoehnen.

     BEWUSST NICHT HIER: Lohn, Zuschlag, Empfehlung, Vertragslaufzeit,
     Vertragsvorlage, Vertragsstatus, Vertragsversand, Portallink, Links in die
     Bewerber- oder Mitarbeiterakte. Die Komponente hat dafuer keine Methoden —
     siehe TrainingReviewHasNoDispatchTest. Wer hier etwas ergaenzt, pruefe
     erst, ob es ein Konto sehen darf, das weder Loehne noch Vertraege kennt. --}}
<x-ui-page>
    @php
        $interview = $this->interview;
        $start = $interview->starts_at;
    @endphp

    <x-slot name="navbar">
        <x-ui-page-navbar title="Schulung bewerten" icon="heroicon-o-star" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Schulungen bewerten', 'href' => route('recruiting.training-review.index')],
            ['label' => $interview->interviewType?->name ?? 'Schulung'],
        ]" />
    </x-slot>

    <x-ui-page-container width="full">
        <div class="px-4 sm:px-6 lg:px-8">
            <x-ui-panel title="Termin-Details" subtitle="{{ $interview->interviewType?->name ?? 'Schulung' }}">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-[var(--ui-muted)]">Datum</div>
                        <div class="font-medium">
                            @if($start)
                                {{ $start->format('d.m.Y H:i') }}
                                @if($interview->ends_at)
                                    — {{ $interview->ends_at->format('H:i') }}
                                @endif
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Ort</div>
                        <div class="font-medium">{{ $interview->location ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Schulungsleiter</div>
                        <div class="font-medium">
                            @if($interview->interviewers->isNotEmpty())
                                {{ $interview->interviewers->pluck('name')->join(', ') }}
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Teilnehmer</div>
                        <div class="font-medium">{{ $this->bookings->count() }}</div>
                    </div>
                </div>
            </x-ui-panel>

            <div class="mt-6">
                <x-ui-panel
                    title="Bewertung"
                    subtitle="Anwesenheit markieren und bewerten. Bei zwei Gruppen sieht jeder Leiter die ganze Liste."
                >
                    @if (session('success'))
                        <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">
                            {{ session('success') }}
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="mb-3 rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-800">
                            {{ session('error') }}
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full table-auto border-collapse text-sm">
                            <thead>
                                <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                    <th class="px-4 py-3">Teilnehmer</th>
                                    <th class="px-4 py-3">Foto</th>
                                    <th class="px-4 py-3">Anwesenheit</th>
                                    <th class="px-4 py-3">Bewertung</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--ui-border)]/60">
                                @forelse ($this->bookings as $booking)
                                    @php
                                        $applicant = $booking->applicant;
                                        // Name als TEXT, nicht als Link: die Bewerberakte ist
                                        // fuer dieses Konto gesperrt.
                                        $name = \Platform\Recruiting\Support\ApplicantContactName::display(
                                            $this->contactCandidatesFor($applicant),
                                        );
                                        $werte = $applicant ? ($this->evaluationValues[$applicant->id] ?? []) : [];
                                        $sterne = collect(\Platform\Recruiting\Support\RatingCriteria::columns())
                                            ->map(fn ($c) => $werte[$c] ?? null)
                                            ->filter()
                                            ->values();
                                        $schnitt = $sterne->isNotEmpty() ? round($sterne->avg(), 1) : null;
                                        $bewertbar = \Platform\Recruiting\Support\EvaluationAvailability::isOpen($booking->status);
                                        $rowBgClass = $booking->status === 'attended' ? '' : 'opacity-70';
                                    @endphp
                                    <tr class="hover:bg-gray-50 {{ $rowBgClass }}" wire:key="booking-{{ $booking->id }}">
                                        <td class="px-4 py-3 font-medium text-[var(--ui-secondary)]">
                                            {{ $name }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @include('recruiting::livewire.partials.selfie', ['applicantId' => $applicant?->id])
                                        </td>
                                        <td class="px-4 py-3">
                                            <select
                                                wire:change="setAttendance({{ $booking->id }}, $event.target.value)"
                                                class="text-xs border border-[var(--ui-border)] rounded px-2 py-1"
                                            >
                                                <option value="confirmed" @selected($booking->status === 'confirmed')>Bestätigt</option>
                                                <option value="attended" @selected($booking->status === 'attended')>Teilgenommen</option>
                                                <option value="no_show" @selected($booking->status === 'no_show')>Nicht erschienen</option>
                                                <option value="rejected_on_site" @selected($booking->status === 'rejected_on_site')>Vor Ort aussortiert</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($schnitt !== null)
                                                <span class="text-amber-500">&#9733;</span>
                                                <span class="font-medium">{{ number_format($schnitt, 1, ',', '.') }}</span>
                                                <span class="text-[var(--ui-muted)] text-xs">({{ $sterne->count() }}/5)</span>
                                            @else
                                                <span class="text-[var(--ui-muted)] text-xs">noch nicht bewertet</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            @if($bewertbar)
                                                <x-ui-button variant="primary" size="xs" wire:click="openEvaluationModal({{ $booking->id }})">
                                                    Bewerten
                                                </x-ui-button>
                                            @else
                                                <span class="text-[10px] text-[var(--ui-muted)] mr-2">Bewertung ab „Teilgenommen“</span>
                                            @endif
                                            <x-ui-button variant="secondary-outline" size="xs" wire:click="openClarifyModal({{ $booking->id }})">
                                                Klärung an HR
                                            </x-ui-button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                            <div class="text-sm">Keine Teilnehmer gebucht</div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui-panel>
            </div>
        </div>
    </x-ui-page-container>

    @include('recruiting::livewire.partials.evaluation-modal')

    {{-- Klaerung an HR. Der Name ist Pflicht, WEIL das Konto ein Sammelkonto
         mehrerer Teamleiter ist — ohne ihn weiss HR nicht, wen sie anrufen soll. --}}
    <x-ui-modal wire:model="showClarifyModal" size="md">
        <x-slot name="header">Klärung an HR</x-slot>

        <div class="space-y-3">
            <div class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-900">
                Nur wenn HR wirklich handeln muss — der Vertrag dieser Person wird
                bis zur Klärung angehalten.
            </div>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-[var(--ui-secondary)]">Dein Name</span>
                <input type="text" wire:model="clarifyReporter" placeholder="z.B. Kevin Berger"
                       class="w-full rounded-lg border border-[var(--ui-border)] px-3 py-2 text-sm" />
                @error('clarifyReporter')
                    <span class="text-xs text-red-600">{{ $message }}</span>
                @enderror
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-[var(--ui-secondary)]">Was soll HR klären?</span>
                <textarea wire:model="clarifyNotes" rows="3" placeholder="z.B. möchte 15,50 €/Std"
                          class="w-full rounded-lg border border-[var(--ui-border)] px-3 py-2 text-sm"></textarea>
                @error('clarifyNotes')
                    <span class="text-xs text-red-600">{{ $message }}</span>
                @enderror
            </label>
        </div>

        <x-slot name="footer">
            <x-ui-button variant="secondary-outline" wire:click="closeClarifyModal">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="submitClarification">An HR übergeben</x-ui-button>
        </x-slot>
    </x-ui-modal>
</x-ui-page>
