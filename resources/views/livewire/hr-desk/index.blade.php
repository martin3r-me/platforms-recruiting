<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="HR-Schreibtisch" icon="heroicon-o-shield-check" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'HR-Schreibtisch'],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="full">
        @if(session()->has('message'))
            <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                {{ session('message') }}
            </div>
        @endif

        @php $counts = $this->reasonCounts; @endphp

        {{-- Filter-Buttons --}}
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="text-sm text-[var(--ui-muted)] mr-1">Filter:</span>
            <button
                wire:click="$set('reasonFilter', 'all')"
                class="px-3 py-1.5 text-sm font-medium rounded-lg border transition-colors
                    {{ $reasonFilter === 'all'
                        ? 'bg-[var(--ui-primary)] text-white border-[var(--ui-primary)]'
                        : 'bg-white text-[var(--ui-secondary)] border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]' }}"
            >
                Alle <span class="ml-1 opacity-70">({{ $counts['all'] ?? 0 }})</span>
            </button>
            @foreach(\Platform\Recruiting\Models\RecHrDeskCase::REASON_LABELS as $reason => $label)
                @php $count = $counts[$reason] ?? 0; @endphp
                <button
                    wire:click="$set('reasonFilter', '{{ $reason }}')"
                    @disabled($count === 0 && $reasonFilter !== $reason)
                    class="px-3 py-1.5 text-sm font-medium rounded-lg border transition-colors
                        {{ $reasonFilter === $reason
                            ? 'bg-[var(--ui-primary)] text-white border-[var(--ui-primary)]'
                            : 'bg-white text-[var(--ui-secondary)] border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]' }}
                        {{ $count === 0 && $reasonFilter !== $reason ? 'opacity-50 cursor-not-allowed' : '' }}"
                >
                    {{ $label }} <span class="ml-1 opacity-70">({{ $count }})</span>
                </button>
            @endforeach
        </div>

        @php $cases = $this->cases; @endphp

        @if($cases->isEmpty())
            <div class="text-center py-16 text-[var(--ui-muted)] text-sm border border-dashed border-[var(--ui-border)]/40 rounded-lg">
                @if($reasonFilter === 'all')
                    Keine offenen Cases auf dem HR-Schreibtisch. 🎉
                @else
                    Keine offenen Cases mit diesem Reason.
                @endif
            </div>
        @else
            <div class="space-y-3">
                @foreach($cases as $case)
                    @php
                        $applicant = $case->applicant;
                        $contact = $applicant?->crmContactLinks->first()?->contact;
                        $name = trim(($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? ''));
                        $email = $contact?->emailAddresses->first()?->email_address;
                        $phone = $contact?->phoneNumbers->first()?->raw_input ?? $contact?->phoneNumbers->first()?->e164;
                        $position = $applicant?->postings->first()?->position;
                        $phase = $applicant?->phase;
                        $openedAtRel = $case->opened_at?->diffForHumans();
                    @endphp

                    <div class="border border-[var(--ui-border)]/60 rounded-lg p-5 bg-white hover:bg-[var(--ui-muted-5)]/40 transition-colors">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1.5">
                                    <h3 class="text-base font-semibold text-[var(--ui-secondary)]">
                                        @if($applicant)
                                            <a href="{{ route('recruiting.applicants.show', $applicant->id) }}" wire:navigate class="hover:text-[var(--ui-primary)]">
                                                {{ $name !== '' ? $name : 'Bewerber #' . $applicant->id }}
                                            </a>
                                        @else
                                            <span class="text-[var(--ui-muted)]">— Gelöschter Bewerber —</span>
                                        @endif
                                    </h3>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full
                                        @if($case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_NON_EU_CITIZEN)
                                            bg-orange-100 text-orange-700
                                        @elseif($case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE)
                                            bg-purple-100 text-purple-700
                                        @elseif($case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_APPLICANT_CANCELLED_TRAINING)
                                            bg-red-100 text-red-700
                                        @else
                                            bg-gray-100 text-gray-700
                                        @endif
                                    ">
                                        {{ $case->reasonLabel() }}
                                    </span>
                                </div>

                                <div class="text-sm text-[var(--ui-muted)] flex flex-wrap items-center gap-x-3 gap-y-1">
                                    @if($phase)
                                        <span>{{ $phase->name }}</span>
                                        <span class="text-[var(--ui-muted)]/40">·</span>
                                    @endif
                                    @if($position)
                                        <span>{{ $position->title }}</span>
                                        <span class="text-[var(--ui-muted)]/40">·</span>
                                    @endif
                                    <span>auf HR-Schreibtisch seit {{ $openedAtRel }}</span>
                                </div>

                                @if($email || $phone)
                                    <div class="text-sm text-[var(--ui-muted)] mt-2 flex flex-wrap gap-x-3">
                                        @if($email)
                                            <span>{{ $email }}</span>
                                        @endif
                                        @if($phone)
                                            <span>{{ $phone }}</span>
                                        @endif
                                    </div>
                                @endif

                                @if($case->notes)
                                    <div class="text-xs text-[var(--ui-muted)] mt-2 italic">
                                        Notiz: {{ $case->notes }}
                                    </div>
                                @endif

                                {{-- Rechtsstatus-Pruefung: nur fuer nicht-EU oder unklar --}}
                                @php
                                    $legalStatus = $applicant?->legalStatus;
                                    $showLegalSection = $applicant && (
                                        $case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_NON_EU_CITIZEN
                                        || ($legalStatus && $legalStatus->is_eu_citizen === false)
                                    );
                                    $approveBlocked = $applicant
                                        && \Platform\Recruiting\Services\HrDeskApprovalGate::blocksApproval(
                                            $case->reason,
                                            $applicant->isLegalStatusUnchecked()
                                        );
                                @endphp
                                @if($showLegalSection && $legalStatus)
                                    <div class="mt-4 p-3 rounded-md border border-amber-200 bg-amber-50/60">
                                        <div class="text-xs font-semibold text-amber-900 uppercase tracking-wide mb-2">
                                            Rechtsstatus-Prüfung
                                        </div>

                                        <div class="flex flex-wrap items-center gap-3">
                                            {{-- Geprueft-Toggle --}}
                                            <button
                                                type="button"
                                                wire:click="toggleLegalStatusChecked({{ $applicant->id }})"
                                                wire:loading.attr="disabled"
                                                class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-md border transition-colors
                                                    {{ $legalStatus->legal_status_checked_at
                                                        ? 'bg-emerald-100 text-emerald-800 border-emerald-300 hover:bg-emerald-200'
                                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}"
                                            >
                                                @if($legalStatus->legal_status_checked_at)
                                                    @svg('heroicon-o-check-circle', 'w-4 h-4')
                                                    <span>Geprüft am {{ $legalStatus->legal_status_checked_at->format('d.m.Y') }}</span>
                                                @else
                                                    @svg('heroicon-o-clock', 'w-4 h-4')
                                                    <span>Als geprüft markieren</span>
                                                @endif
                                            </button>

                                            {{-- Zusatzvertrag-Dropdown --}}
                                            <div class="flex items-center gap-2">
                                                <label class="text-xs font-medium text-gray-700">Zusatzvertrag:</label>
                                                <select
                                                    wire:change="setAdditionalContractTemplate({{ $applicant->id }}, $event.target.value)"
                                                    class="text-xs border border-gray-300 rounded-md px-2 py-1 bg-white"
                                                >
                                                    <option value="0" @selected(!$legalStatus->additional_contract_template_id)>
                                                        — kein Zusatzvertrag —
                                                    </option>
                                                    @foreach($this->availableAdditionalContractTemplates as $tpl)
                                                        <option value="{{ $tpl->id }}" @selected($legalStatus->additional_contract_template_id === $tpl->id)>
                                                            {{ $tpl->code }} — {{ $tpl->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>

                                        @if(!$legalStatus->legal_status_checked_at)
                                            <p class="text-[11px] text-amber-800 mt-2">
                                                Solange der Rechtsstatus nicht geprüft ist, kann diesem Bewerber im Schulungs-Index keine Vertragsvorlage zugewiesen werden — und der Bulk-Send überspringt ihn.
                                            </p>
                                        @endif
                                    </div>
                                @endif

                                {{-- Vertrags-Versand vom Schreibtisch (Nachbereitung-Semantik) --}}
                                @php
                                    $isNonEuCase = $case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_NON_EU_CITIZEN;
                                    $hasAttended = $applicant && isset($this->attendedApplicantIds[$applicant->id]);
                                    $showSendSection = $isNonEuCase && $hasAttended && $legalStatus;
                                    $deskFields = $applicant ? ($deskContractDates[$applicant->id] ?? []) : [];
                                    $deskBeginn = $deskFields['vertragsbeginn'] ?? '';
                                    $deskEnde = $deskFields['vertragsende'] ?? '';
                                    // Aus der eager-geladenen Relation statt hasAnyContractSent()
                                    // (das würde pro Karte einen frischen EXISTS-Query feuern).
                                    $deskHasSent = $applicant
                                        ? $applicant->contracts->first(fn ($c) => $c->status !== 'cancelled' && $c->sent_at !== null) !== null
                                        : false;
                                    $sendState = $showSendSection
                                        ? \Platform\Recruiting\Services\ContractSendEligibility::state(
                                            $deskHasSent,
                                            (bool) $applicant->isLegalStatusUnchecked(),
                                            !empty($deskBeginn),
                                            $applicant->zuschlag !== null,
                                        )
                                        : null;
                                    $sendReady = $sendState === 'ready' || $sendState === 'already_sent';
                                    $shownTpl = $applicant?->contractTemplate ?? $this->defaultContractTemplate;
                                @endphp
                                @if($showSendSection)
                                    <div class="mt-3 p-3 rounded-md border border-blue-200 bg-blue-50/60">
                                        <div class="text-xs font-semibold text-blue-900 uppercase tracking-wide mb-2">
                                            Verträge &amp; Portallink (nach Schulung)
                                        </div>
                                        <div class="flex flex-wrap items-end gap-3">
                                            <div>
                                                <label class="block text-[11px] font-medium text-gray-700 mb-0.5">AV-Vorlage</label>
                                                @if($shownTpl)
                                                    <div class="text-xs px-2 py-1 rounded bg-white border border-gray-300 text-gray-700">
                                                        {{ $shownTpl->code ? $shownTpl->code . ' — ' : '' }}{{ $shownTpl->name }}
                                                    </div>
                                                @else
                                                    <div class="text-xs text-red-700">AV-default-Vorlage fehlt oder ist inaktiv.</div>
                                                @endif
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-medium text-gray-700 mb-0.5">Zuschlag €/Std</label>
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    value="{{ $applicant->zuschlag !== null ? number_format((float) $applicant->zuschlag, 2, ',', '.') : '' }}"
                                                    wire:change="setDeskZuschlag({{ $applicant->id }}, $event.target.value)"
                                                    placeholder="z.B. 0,60"
                                                    class="text-xs border border-gray-300 rounded px-2 py-1 w-[110px]"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-medium text-gray-700 mb-0.5">Vertragsbeginn</label>
                                                <input
                                                    type="date"
                                                    value="{{ $deskBeginn }}"
                                                    wire:change="setDeskContractDate({{ $applicant->id }}, 'vertragsbeginn', $event.target.value)"
                                                    class="text-xs border border-gray-300 rounded px-2 py-1"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-medium text-gray-700 mb-0.5">Vertragsende</label>
                                                <input
                                                    type="date"
                                                    value="{{ $deskEnde }}"
                                                    wire:change="setDeskContractDate({{ $applicant->id }}, 'vertragsende', $event.target.value)"
                                                    class="text-xs border border-gray-300 rounded px-2 py-1"
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                wire:click="sendContractsFromDesk({{ $case->id }})"
                                                wire:loading.attr="disabled"
                                                @disabled(!$sendReady)
                                                @class([
                                                    'px-3 py-1.5 text-xs font-semibold rounded-md border',
                                                    'border-blue-300 text-white bg-blue-600 hover:bg-blue-700' => $sendReady,
                                                    'border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed' => !$sendReady,
                                                ])
                                            >
                                                Portallink &amp; Verträge versenden
                                            </button>
                                        </div>
                                        @if($sendState === 'legal_blocked')
                                            <p class="text-[11px] text-amber-800 mt-2">Erst Rechtsstatus prüfen — dann wird der Versand aktiv.</p>
                                        @elseif($sendState === 'missing_beginn')
                                            <p class="text-[11px] text-gray-600 mt-2">Vertragsbeginn setzen (Ende leer = Auto: +1 Jahr, Anfang Monat, −1 Tag).</p>
                                        @elseif($sendState === 'missing_zuschlag')
                                            <p class="text-[11px] text-gray-600 mt-2">Zuschlag setzen.</p>
                                        @elseif($sendState === 'already_sent')
                                            <p class="text-[11px] text-emerald-700 mt-2">Verträge bereits versendet — Klick schließt nur noch den Fall.</p>
                                        @endif
                                        <p class="text-[11px] text-gray-500 mt-1">Zusatzvertrag (oben) wird automatisch mitversendet. Alternativ: "Freigeben" ohne Versand — dann sendet der Schulungsleiter.</p>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-col gap-2 flex-shrink-0">
                                <button
                                    wire:click="openResolveModal({{ $case->id }}, 'approve')"
                                    @disabled($approveBlocked)
                                    @class([
                                        'px-3 py-1.5 text-sm font-medium rounded-md border',
                                        'border-emerald-200 text-emerald-700 bg-white hover:bg-emerald-50' => ! $approveBlocked,
                                        'border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed' => $approveBlocked,
                                    ])
                                >
                                    @svg('heroicon-o-check', 'w-4 h-4 inline-block -mt-0.5')
                                    Freigeben
                                </button>
                                @if($approveBlocked)
                                    <span class="text-xs text-amber-700 text-center">Erst Rechtsstatus prüfen</span>
                                @endif
                                <button
                                    wire:click="openResolveModal({{ $case->id }}, 'reject')"
                                    class="px-3 py-1.5 text-sm font-medium rounded-md border border-red-200 text-red-700 bg-white hover:bg-red-50"
                                >
                                    @svg('heroicon-o-x-mark', 'w-4 h-4 inline-block -mt-0.5')
                                    Ablehnen
                                </button>
                                @if($applicant)
                                    <a href="{{ route('recruiting.applicants.show', $applicant->id) }}"
                                       wire:navigate
                                       class="px-3 py-1.5 text-xs text-center text-[var(--ui-muted)] hover:text-[var(--ui-primary)] underline-offset-2 hover:underline">
                                        Detail →
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Resolve-Modal --}}
        <x-ui-modal size="md" model="resolveModalShow">
            <x-slot name="header">
                @if($resolvingAction === 'approve')
                    Case freigeben
                @else
                    Bewerber ablehnen
                @endif
            </x-slot>
            <div class="space-y-4 p-4">
                <div class="text-sm text-[var(--ui-secondary)]">
                    @if($resolvingAction === 'approve')
                        Der Case wird geschlossen, der Bewerber kehrt in den normalen Flow zurück. Wenn keine weiteren offenen Cases bestehen, wird er vom HR-Schreibtisch entfernt.
                    @else
                        Der Bewerber wird abgelehnt — <code>rejected_at</code> wird gesetzt, <code>is_active</code> auf false. <strong>Nicht rückgängig machbar via UI</strong>.
                    @endif
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium text-[var(--ui-secondary)]">Notiz {{ $resolvingAction === 'reject' ? '(empfohlen)' : '(optional)' }}</label>
                    <textarea wire:model="resolveNotes"
                              rows="3"
                              placeholder="z.B. Bewerber telefonisch geprüft, sprechen ausreichend Deutsch."
                              class="w-full px-3 py-2 text-sm border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/20 focus:border-[var(--ui-primary)]"></textarea>
                </div>
            </div>
            <x-slot name="footer">
                <x-ui-button variant="secondary-outline" wire:click="closeResolveModal">Abbrechen</x-ui-button>
                @if($resolvingAction === 'approve')
                    <x-ui-button variant="success" wire:click="confirmResolve">Freigeben</x-ui-button>
                @else
                    <x-ui-button variant="danger" wire:click="confirmResolve">Ablehnen</x-ui-button>
                @endif
            </x-slot>
        </x-ui-modal>
    </x-ui-page-container>
</x-ui-page>
