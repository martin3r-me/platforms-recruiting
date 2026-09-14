{{-- Schlanke Nachbereitung fuer Teamleiter-Konten (14.09.2026).

     BEWUSST NICHT HIER: Lohn, Zuschlag, Empfehlung, Vertragslaufzeit,
     Vertragsvorlage, Vertragsversand, Portallink, Links in die Bewerber-
     oder Mitarbeiterakte. Die Komponente hat dafuer keine Methoden — siehe
     TrainingReviewHasNoDispatchTest. Wer hier etwas ergaenzt, pruefe erst,
     ob es ein Konto sehen darf, das weder Loehne noch Vertraege kennt. --}}
<div class="p-4 md:p-6">
    @php
        $interview = $this->interview;
        $start = $interview->starts_at;
    @endphp

    <div class="mb-4 flex items-start justify-between gap-4 flex-wrap">
        <div>
            <a href="{{ route('recruiting.training-review.index') }}" wire:navigate
               class="text-xs text-[var(--ui-muted)] hover:underline">&larr; Alle Schulungen</a>
            <h1 class="text-xl font-semibold text-[var(--ui-secondary)] mt-1">
                {{ $interview->interviewType?->name ?? 'Schulung' }}
            </h1>
            <p class="text-sm text-[var(--ui-muted)]">
                @if ($start)
                    {{ $start->format('d.m.Y') }}, {{ $start->format('H:i') }} Uhr
                @endif
                @if ($interview->location)
                    · {{ $interview->location }}
                @endif
            </p>
        </div>
        <div class="text-sm text-[var(--ui-muted)]">{{ $this->bookings->count() }} Teilnehmer</div>
    </div>

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

    <div class="rounded-lg border border-[var(--ui-border)] bg-white overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-[var(--ui-muted-5)] text-xs uppercase tracking-wide text-[var(--ui-muted)]">
                <tr>
                    <th class="px-4 py-2 text-left">Teilnehmer</th>
                    <th class="px-4 py-2 text-left">Anwesenheit</th>
                    <th class="px-4 py-2 text-left">Bewertung</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--ui-border)]">
                @forelse ($this->bookings as $booking)
                    @php
                        $applicant = $booking->applicant;
                        // Name als TEXT, nicht als Link: die Bewerberakte ist fuer
                        // dieses Konto gesperrt.
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
                    @endphp
                    <tr class="hover:bg-gray-50" wire:key="booking-{{ $booking->id }}">
                        <td class="px-4 py-3 font-medium text-[var(--ui-secondary)]">
                            {{ $name }}
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
                            @if ($schnitt !== null)
                                <span class="text-amber-600">&#9733;</span>
                                <span class="font-medium">{{ number_format($schnitt, 1, ',', '.') }}</span>
                                <span class="text-[var(--ui-muted)] text-xs">({{ $sterne->count() }}/5 Kriterien)</span>
                            @else
                                <span class="text-[var(--ui-muted)] text-xs">noch nicht bewertet</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if ($bewertbar)
                                <x-ui-button variant="primary" size="xs" wire:click="openEvaluationModal({{ $booking->id }})">
                                    Bewerten
                                </x-ui-button>
                            @else
                                <span class="text-[10px] text-[var(--ui-muted)] mr-2">Bewertung ab „Teilgenommen"</span>
                            @endif
                            <x-ui-button variant="secondary-outline" size="xs" wire:click="openClarifyModal({{ $booking->id }})">
                                Klärung an HR
                            </x-ui-button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-[var(--ui-muted)]">
                            Keine Teilnehmer gebucht.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

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
</div>
