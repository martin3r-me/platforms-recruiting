<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Interview-Buchungen" icon="heroicon-o-clipboard-document-list" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Interview-Termine', 'href' => route('recruiting.interview-schedule.index')],
            ['label' => 'Buchungen'],
        ]">
            <x-ui-button variant="primary" size="sm" wire:click="openBookModal">
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Kandidat buchen</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        <div class="px-4 sm:px-6 lg:px-8">
            {{-- Termin-Info --}}
            <x-ui-panel title="Termin-Details" subtitle="{{ $this->interview->interviewType?->name ?? 'Interview' }}">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-[var(--ui-muted)]">Datum</div>
                        <div class="font-medium">
                            {{ $this->interview->starts_at->format('d.m.Y H:i') }}
                            @if($this->interview->ends_at)
                                — {{ $this->interview->ends_at->format('H:i') }}
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Stelle</div>
                        <div class="font-medium">{{ $this->interview->position?->title ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Ort</div>
                        <div class="font-medium">{{ $this->interview->location ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[var(--ui-muted)]">Interviewer</div>
                        <div class="font-medium">
                            @if($this->interview->interviewers->isNotEmpty())
                                {{ $this->interview->interviewers->pluck('name')->join(', ') }}
                            @else
                                —
                            @endif
                        </div>
                    </div>
                </div>
            </x-ui-panel>

            {{-- Modus-Switch --}}
            <div class="mt-6 flex gap-2">
                <button
                    wire:click="$set('mode', 'overview')"
                    class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors {{ $mode === 'overview' ? 'bg-[var(--ui-primary)] text-white border-[var(--ui-primary)]' : 'bg-white text-[var(--ui-secondary)] border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]' }}">
                    @svg('heroicon-o-clipboard-document-list', 'w-4 h-4 inline-block mr-1.5 -mt-0.5')
                    Übersicht
                </button>
                <button
                    wire:click="$set('mode', 'nachbereitung')"
                    class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors {{ $mode === 'nachbereitung' ? 'bg-[var(--ui-primary)] text-white border-[var(--ui-primary)]' : 'bg-white text-[var(--ui-secondary)] border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]' }}">
                    @svg('heroicon-o-pencil-square', 'w-4 h-4 inline-block mr-1.5 -mt-0.5')
                    Nach der Schulung
                </button>
            </div>

            {{-- Buchungen --}}
            <div class="mt-4">
                <x-ui-panel
                    title="{{ $mode === 'nachbereitung' ? 'Schulungsnachbereitung' : 'Buchungen' }}"
                    subtitle="{{ $mode === 'nachbereitung' ? 'Anwesenheit markieren, Vertragsvorlage wählen, Verträge versenden.' : 'Gebuchte Kandidaten für diesen Termin' }}"
                >
                    @if($mode === 'overview')
                    <div class="flex gap-2 mb-4">
                        <x-ui-input-select
                            name="filterStatus"
                            wire:model.live="filterStatus"
                            :options="[
                                ['value' => 'all', 'label' => 'Alle Status'],
                                ['value' => 'booked', 'label' => 'Gebucht'],
                                ['value' => 'registered', 'label' => 'Registriert'],
                                ['value' => 'confirmed', 'label' => 'Bestätigt'],
                                ['value' => 'attended', 'label' => 'Teilgenommen'],
                                ['value' => 'cancelled', 'label' => 'Abgesagt'],
                                ['value' => 'rebooked', 'label' => 'Umgebucht'],
                                ['value' => 'no_show', 'label' => 'Nicht erschienen'],
                            ]"
                            optionValue="value"
                            optionLabel="label"
                        />
                        <x-ui-input-text name="search" placeholder="Suchen…" wire:model.live.debounce.300ms="search" class="flex-1 max-w-xs" />
                    </div>
                    @endif

                    @if($this->interview->max_participants)
                        @php
                            $activeCount = $this->bookings->whereNotIn('status', ['cancelled'])->count();
                            $isFull = $activeCount >= $this->interview->max_participants;
                        @endphp
                        <div class="mb-4 p-3 rounded-lg {{ $isFull ? 'bg-red-50 border border-red-200' : 'bg-blue-50 border border-blue-200' }}">
                            <span class="text-sm font-medium {{ $isFull ? 'text-red-700' : 'text-blue-700' }}">
                                {{ $activeCount }} / {{ $this->interview->max_participants }} Plätze belegt
                                @if($isFull)
                                    — Termin voll
                                @endif
                            </span>
                        </div>
                    @endif

                    @if($mode === 'overview')
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto border-collapse text-sm">
                            <thead>
                                <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                    <th class="px-4 py-3">Kandidat</th>
                                    <th class="px-4 py-3">Stelle</th>
                                    <th class="px-4 py-3">Gebucht am</th>
                                    <th class="px-4 py-3">Notizen</th>
                                    <th class="px-4 py-3">Erinnerung</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--ui-border)]/60">
                                @forelse($this->bookings as $booking)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            @if($booking->applicant)
                                                <a href="{{ route('recruiting.applicants.show', $booking->applicant->id) }}" wire:navigate class="text-blue-600 hover:underline">
                                                    {{ $booking->applicant->crmContactLinks->first()?->contact?->full_name ?? 'Unbekannt' }}
                                                </a>
                                            @else
                                                <span class="text-[var(--ui-muted)]">Gelöscht</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @php $positions = $booking->applicant?->postings?->map(fn ($p) => $p->position?->title)->filter()->unique(); @endphp
                                            {{ $positions && $positions->isNotEmpty() ? $positions->implode(', ') : '—' }}
                                        </td>
                                        <td class="px-4 py-3">{{ $booking->booked_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            <textarea
                                                class="w-full text-xs border border-transparent hover:border-[var(--ui-border)] focus:border-blue-400 rounded px-2 py-1 bg-transparent resize-none focus:bg-white transition-colors"
                                                rows="1"
                                                placeholder="Notiz…"
                                                wire:blur="updateNotes({{ $booking->id }}, $event.target.value)"
                                            >{{ $booking->notes }}</textarea>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($booking->reminder_sent_at)
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs text-green-600 flex items-center gap-1">
                                                        @svg('heroicon-o-check-circle', 'w-3.5 h-3.5')
                                                        {{ $booking->reminder_sent_at->format('d.m. H:i') }}
                                                    </span>
                                                    @if($this->interview->reminder_wa_template_id && $booking->status !== 'cancelled')
                                                        <x-ui-button variant="secondary-outline" size="xs" wire:click="sendReminder({{ $booking->id }})" wire:confirm="Erneut senden?">
                                                            @svg('heroicon-o-arrow-path', 'w-3 h-3')
                                                        </x-ui-button>
                                                    @endif
                                                </div>
                                            @elseif($this->interview->reminder_wa_template_id && $booking->status !== 'cancelled')
                                                <x-ui-button variant="secondary-outline" size="xs" wire:click="sendReminder({{ $booking->id }})" wire:confirm="Erinnerung jetzt senden?">
                                                    @svg('heroicon-o-paper-airplane', 'w-3 h-3') Senden
                                                </x-ui-button>
                                            @else
                                                <span class="text-[var(--ui-muted)]">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <select wire:change="updateStatus({{ $booking->id }}, $event.target.value)" class="text-xs border border-[var(--ui-border)] rounded px-2 py-1">
                                                    <option value="booked" @selected($booking->status === 'booked')>Gebucht</option>
                                                    <option value="registered" @selected($booking->status === 'registered')>Registriert</option>
                                                    <option value="confirmed" @selected($booking->status === 'confirmed')>Bestätigt</option>
                                                    <option value="attended" @selected($booking->status === 'attended')>Teilgenommen</option>
                                                    <option value="cancelled" @selected($booking->status === 'cancelled')>Abgesagt</option>
                                                    <option value="no_show" @selected($booking->status === 'no_show')>Nicht erschienen</option>
                                                </select>
                                                @if($booking->is_rebooked)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200" title="Bewerber hat danach eine andere Schulung gebucht">
                                                        Umgebucht
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <x-ui-button variant="danger-outline" size="xs" wire:click="deleteBooking({{ $booking->id }})">
                                                Löschen
                                            </x-ui-button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                            @svg('heroicon-o-clipboard-document-list', 'w-10 h-10 text-[var(--ui-muted)] mx-auto mb-2')
                                            <div class="text-sm">Keine Buchungen vorhanden</div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @else
                    {{-- Nachbereitungs-Modus --}}
                    @php
                        $relevantBookings = $this->bookings->whereNotIn('status', ['cancelled'])->values();
                        $bulkState = $this->bulkSendState;
                        $defaultTpl = $this->defaultContractTemplate;
                    @endphp

                    <div class="overflow-x-auto">
                        <table class="w-full table-auto border-collapse text-sm">
                            <thead>
                                <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                    <th class="px-4 py-3">Bewerber</th>
                                    <th class="px-4 py-3">Anwesenheit</th>
                                    <th class="px-4 py-3">Vertragsvorlage</th>
                                    <th class="px-4 py-3">Vertragslaufzeit</th>
                                    <th class="px-4 py-3">Vertragsstatus</th>
                                    <th class="px-4 py-3">Bewertung</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--ui-border)]/60">
                                @forelse($relevantBookings as $booking)
                                    @php
                                        $applicant = $booking->applicant;
                                        $hasSent = $applicant && $applicant->hasAnyContractSent();
                                        $rowDimmed = $hasSent;

                                        // Rechtsstatus-Pruefung Block B:
                                        //  - EU (is_eu_citizen=true) → neutral, kein Marker
                                        //  - nicht-EU geprueft → gruener Background
                                        //  - nicht-EU ungeprueft → roter Background + Disable
                                        //  - is_eu_citizen=null (unbeantwortet, legalStatus existiert) → wie ungeprueft
                                        //  - kein legalStatus → neutral (Bestandsbewerber ohne Phase-3-Antwort)
                                        $legalStatus = $applicant?->legalStatus;
                                        $isNonEuChecked = $legalStatus
                                            && $legalStatus->is_eu_citizen === false
                                            && $legalStatus->legal_status_checked_at !== null;
                                        $isLegalCheckPending = $legalStatus
                                            && $legalStatus->is_eu_citizen !== true
                                            && $legalStatus->legal_status_checked_at === null;

                                        $hasOpenNonEuCase = $applicant
                                            && isset($this->openNonEuCaseApplicantIds[$applicant->id]);

                                        $rowBgClass = '';
                                        if ($hasOpenNonEuCase) {
                                            // Liegt bewusst bei HR — neutral, kein Handlungsbedarf hier.
                                            $rowBgClass = 'bg-blue-50/60';
                                        } elseif ($isLegalCheckPending) {
                                            // Verteidigungslinie: ungeprueft ohne offenen Fall (Randfall).
                                            $rowBgClass = 'bg-red-50';
                                        } elseif ($isNonEuChecked) {
                                            $rowBgClass = 'bg-emerald-50';
                                        }

                                        $blockContracts = $hasSent || $isLegalCheckPending || $hasOpenNonEuCase;

                                        $lockTitle = $hasOpenNonEuCase
                                            ? 'Liegt beim HR-Schreibtisch'
                                            : ($isLegalCheckPending ? 'Bewerber muss zuerst auf HR-Schreibtisch geprüft werden' : '');
                                    @endphp
                                    <tr class="hover:bg-gray-50 {{ $rowDimmed ? 'opacity-60' : '' }} {{ $rowBgClass }}">
                                        <td class="px-4 py-3">
                                            @if($applicant)
                                                <a href="{{ route('recruiting.applicants.show', $applicant->id) }}" wire:navigate class="text-blue-600 hover:underline">
                                                    {{ $applicant->crmContactLinks->first()?->contact?->full_name ?? 'Unbekannt' }}
                                                </a>
                                                @if($hasOpenNonEuCase)
                                                    <div class="text-[10px] text-blue-700 mt-0.5 font-medium">Liegt beim HR-Schreibtisch</div>
                                                @elseif($isLegalCheckPending)
                                                    <div class="text-[10px] text-red-700 mt-0.5 font-medium">Rechtsstatus ungeprüft</div>
                                                @elseif($isNonEuChecked)
                                                    <div class="text-[10px] text-emerald-700 mt-0.5">Rechtsstatus geprüft</div>
                                                @endif
                                            @else
                                                <span class="text-[var(--ui-muted)]">Gelöscht</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <select wire:change="updateStatus({{ $booking->id }}, $event.target.value)" class="text-xs border border-[var(--ui-border)] rounded px-2 py-1">
                                                <option value="booked" @selected($booking->status === 'booked')>Gebucht</option>
                                                <option value="registered" @selected($booking->status === 'registered')>Registriert</option>
                                                <option value="confirmed" @selected($booking->status === 'confirmed')>Bestätigt</option>
                                                <option value="attended" @selected($booking->status === 'attended')>Teilgenommen</option>
                                                <option value="no_show" @selected($booking->status === 'no_show')>Nicht erschienen</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($applicant)
                                                @php $shownTpl = $applicant->contractTemplate ?? $defaultTpl; @endphp
                                                @if($shownTpl)
                                                    <div class="text-xs px-2 py-1 min-w-[180px] inline-block rounded bg-[var(--ui-muted-5)] border border-[var(--ui-border)] text-[var(--ui-secondary)]">
                                                        {{ $shownTpl->code ? $shownTpl->code . ' — ' : '' }}{{ $shownTpl->name }}
                                                    </div>
                                                @else
                                                    <div class="text-xs text-red-700">AV-default-Vorlage fehlt oder ist inaktiv.</div>
                                                @endif
                                                <div class="mt-1.5">
                                                    <input
                                                        type="text"
                                                        inputmode="decimal"
                                                        list="zuschlag-suggestions"
                                                        value="{{ $applicant->zuschlag !== null ? number_format((float) $applicant->zuschlag, 2, ',', '.') : '' }}"
                                                        @disabled($blockContracts)
                                                        wire:change="setApplicantZuschlag({{ $booking->id }}, $event.target.value)"
                                                        placeholder="Zuschlag €/Std (z.B. 0,60)"
                                                        class="text-xs border border-[var(--ui-border)] rounded px-2 py-1 min-w-[180px] {{ $blockContracts ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                                                    />
                                                </div>
                                                @if($hasOpenNonEuCase)
                                                    <div class="text-[10px] text-blue-700 mt-1 leading-snug">Versand macht HR vom Schreibtisch.</div>
                                                @elseif($isLegalCheckPending)
                                                    <div class="text-[10px] text-red-700 mt-1 leading-snug">Erst auf HR-Schreibtisch prüfen.</div>
                                                @elseif($booking->status !== 'attended')
                                                    <div class="text-[10px] text-[var(--ui-muted)] mt-1">Wirksam ab Status „Teilgenommen"</div>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            @if($applicant)
                                                @php
                                                    $beginnVal = $contractDates[$applicant->id]['vertragsbeginn'] ?? '';
                                                    $endeVal = $contractDates[$applicant->id]['vertragsende'] ?? '';
                                                @endphp
                                                <div class="flex flex-col gap-1.5">
                                                    <input
                                                        type="date"
                                                        value="{{ $beginnVal }}"
                                                        @disabled($blockContracts)
                                                        title="{{ $lockTitle }}"
                                                        wire:change="setContractDate({{ $applicant->id }}, 'vertragsbeginn', $event.target.value)"
                                                        class="text-xs border border-[var(--ui-border)] rounded px-2 py-1 min-w-[140px] {{ $blockContracts ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                                                        placeholder="Beginn"
                                                    />
                                                    <input
                                                        type="date"
                                                        value="{{ $endeVal }}"
                                                        @disabled($blockContracts)
                                                        title="{{ $lockTitle }}"
                                                        wire:change="setContractDate({{ $applicant->id }}, 'vertragsende', $event.target.value)"
                                                        class="text-xs border border-[var(--ui-border)] rounded px-2 py-1 min-w-[140px] {{ $blockContracts ? 'bg-gray-100 cursor-not-allowed' : '' }}"
                                                        placeholder="Ende"
                                                    />
                                                </div>
                                                @if(!$hasSent && !$isLegalCheckPending)
                                                    <div class="text-[10px] text-[var(--ui-muted)] mt-1 max-w-[200px] leading-snug">
                                                        Ende leer lassen für Auto-Berechnung (+1 Jahr, Anfang Monat, −1 Tag).
                                                    </div>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($hasSent)
                                                <span class="inline-flex items-center gap-1 text-xs text-emerald-600">
                                                    @svg('heroicon-o-check-circle', 'w-3.5 h-3.5')
                                                    Verträge versendet
                                                </span>
                                            @elseif($hasOpenNonEuCase)
                                                <span class="text-[10px] text-blue-700 font-medium">Liegt beim HR-Schreibtisch — Versand macht HR.</span>
                                            @elseif($isLegalCheckPending)
                                                <span class="inline-flex items-center gap-1 text-xs text-red-600 font-medium" title="Bewerber muss zuerst auf HR-Schreibtisch geprüft werden">
                                                    @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5')
                                                    Rechtsstatus offen
                                                </span>
                                            @elseif($booking->status === 'attended' && $applicant?->contract_template_id)
                                                <span class="text-xs text-[var(--ui-muted)]">bereit zum Versand</span>
                                            @else
                                                <span class="text-xs text-[var(--ui-muted)]">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @php $employee = $applicant?->employee; @endphp
                                            @if($employee)
                                                @php
                                                    $hr = $employee->hrData;
                                                    $hasRating = $hr && ($hr->star_rating !== null || !empty($hr->linen_package_items) || !empty($hr->qualifications));
                                                @endphp
                                                <button
                                                    wire:click="openEvaluationModal({{ $booking->id }})"
                                                    class="px-2.5 py-1 text-xs font-medium rounded-md border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]"
                                                >
                                                    @svg('heroicon-o-star', 'w-3.5 h-3.5 inline-block -mt-0.5 mr-1')
                                                    {{ $hasRating ? 'Bewertung bearbeiten' : 'Bewerten' }}
                                                </button>
                                            @else
                                                <span class="text-xs text-[var(--ui-muted)]" title="MA noch nicht angelegt – Verträge zuerst versenden">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                            @svg('heroicon-o-clipboard-document-list', 'w-10 h-10 text-[var(--ui-muted)] mx-auto mb-2')
                                            <div class="text-sm">Keine Buchungen vorhanden</div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 pt-4 border-t border-[var(--ui-border)]/40 flex items-center justify-between gap-4">
                        <div class="text-sm text-[var(--ui-muted)]">
                            @php
                                $attendedCount = $relevantBookings->where('status', 'attended')->count();
                                $withTemplate = $relevantBookings->where('status', 'attended')->filter(fn ($b) => $b->applicant?->contract_template_id)->count();
                                $alreadySent = $relevantBookings->where('status', 'attended')->filter(fn ($b) => $b->applicant?->hasAnyContractSent())->count();
                            @endphp
                            Anwesend: <strong>{{ $attendedCount }}</strong> · mit Vorlage: <strong>{{ $withTemplate }}</strong> · bereits versendet: <strong>{{ $alreadySent }}</strong>
                        </div>
                        <div>
                            @if($bulkState === 'no_attended')
                                <button disabled class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed" title="Markiere mind. einen Bewerber als „Teilgenommen"">
                                    Portallink & Verträge versenden
                                </button>
                            @elseif($bulkState === 'no_default_template')
                                <button disabled class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed" title="AV-default-Vorlage fehlt oder ist inaktiv">
                                    Portallink & Verträge versenden — AV-default fehlt
                                </button>
                            @elseif($bulkState === 'missing_dates')
                                <button disabled class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed" title="Allen anwesenden Bewerbern einen Vertragsbeginn setzen">
                                    Portallink & Verträge versenden — Vertragsbeginn fehlt
                                </button>
                            @elseif($bulkState === 'missing_zuschlag')
                                <button disabled class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-400 cursor-not-allowed" title="Allen anwesenden Bewerbern einen Zuschlag eintragen">
                                    Portallink & Verträge versenden — Zuschlag fehlt
                                </button>
                            @elseif($bulkState === 'all_already_sent')
                                <span class="px-4 py-2 text-sm text-emerald-600 inline-flex items-center gap-2">
                                    @svg('heroicon-o-check-circle', 'w-4 h-4')
                                    Alle Verträge versendet
                                </span>
                            @else
                                <button
                                    wire:click="sendPortalLinkBulk"
                                    wire:confirm="Verträge erzeugen und Portal-Link an alle anwesenden Bewerber senden?"
                                    class="px-4 py-2 text-sm font-medium rounded-lg bg-[var(--ui-primary)] text-white hover:bg-[var(--ui-primary)]/90"
                                >
                                    @svg('heroicon-o-paper-airplane', 'w-4 h-4 inline-block mr-1.5 -mt-0.5')
                                    Portallink & Verträge versenden
                                </button>
                            @endif
                        </div>
                    </div>
                    @endif
                </x-ui-panel>
            </div>
        </div>
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" width="w-80" :defaultOpen="true">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-[var(--ui-secondary)] uppercase tracking-wider mb-4">Statistiken</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Buchungen</span>
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->bookings->count() }}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Bestätigt</span>
                            <span class="font-semibold text-[var(--ui-secondary)]">{{ $this->bookings->where('status', 'confirmed')->count() }}</span>
                        </div>
                        <div class="flex justify-between items-center p-3 bg-[var(--ui-muted-5)] rounded-lg">
                            <span class="text-sm text-[var(--ui-muted)]">Teilgenommen</span>
                            <span class="font-semibold text-green-600">{{ $this->bookings->where('status', 'attended')->count() }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-4">
                <div class="text-sm text-[var(--ui-muted)]">Letzte Aktivitäten</div>
                <div class="space-y-3 text-sm">
                    <div class="p-2 rounded border border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                        <div class="font-medium text-[var(--ui-secondary)] truncate">Buchungen geladen</div>
                        <div class="text-[var(--ui-muted)]">{{ now()->format('d.m.Y H:i') }}</div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Buchungs-Modal --}}
    <x-ui-modal wire:model="showBookModal">
        <x-slot name="header">Kandidat buchen</x-slot>
        <div class="space-y-4">
            @if($this->interview->rec_position_id)
                <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700">
                    Es werden abgeschlossene Bewerber für die Stelle <strong>{{ $this->interview->position?->title }}</strong> angezeigt — sowie alle importierten Bewerber (Altbestand, ohne Stellen-Bindung).
                </div>
            @endif
            <x-ui-input-select
                name="selectedApplicantId"
                wire:model="selectedApplicantId"
                label="Kandidat *"
                :options="$this->availableApplicants->map(fn($a) => ['value' => $a->id, 'label' => $a->crmContactLinks->first()?->contact?->full_name ?? 'Unbekannt'])->toArray()"
                optionValue="value"
                optionLabel="label"
                :nullable="true"
                nullLabel="— Bitte wählen —"
            />
            <x-ui-input-textarea name="bookingNotes" label="Notizen" wire:model="bookingNotes" />
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary" wire:click="$set('showBookModal', false)">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="book">Buchen</x-ui-button>
        </x-slot>
    </x-ui-modal>

    {{-- Bewertungs-Modal (Schulungsnachbereitung pro MA) --}}
    <x-ui-modal wire:model="showEvaluationModal" size="lg">
        <x-slot name="header">Schulungs-Bewertung</x-slot>
        @php
            $evalBooking = $evaluateBookingId ? $this->bookings->firstWhere('id', $evaluateBookingId) : null;
            $evalEmployee = $evalBooking?->applicant?->employee;
            $evalContactName = $evalBooking?->applicant?->crmContactLinks?->first()?->contact?->full_name ?? 'Unbekannt';
        @endphp
        <div class="space-y-5">
            @if($evalEmployee)
                <div class="text-sm text-[var(--ui-muted)]">
                    <strong class="text-[var(--ui-secondary)]">{{ $evalContactName }}</strong>
                    <span class="ml-2 text-xs">MA #{{ $evalEmployee->id }}</span>
                </div>

                {{-- Sternebewertung --}}
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Sternebewertung</label>
                    <div class="flex gap-2">
                        @foreach(['1','2','3','4','5'] as $star)
                            <label class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm rounded-md border cursor-pointer {{ ($evaluation['star_rating'] ?? null) === $star ? 'border-amber-500 bg-amber-50 text-amber-700' : 'border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]' }}">
                                <input type="radio" wire:model.live="evaluation.star_rating" value="{{ $star }}" class="sr-only">
                                @svg('heroicon-m-star', 'w-4 h-4')
                                {{ $star }}
                            </label>
                        @endforeach
                    </div>
                </div>

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
            @else
                <div class="text-sm text-[var(--ui-muted)]">Mitarbeiter nicht gefunden.</div>
            @endif
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary" wire:click="closeEvaluationModal">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="saveEvaluation">Speichern</x-ui-button>
        </x-slot>
    </x-ui-modal>

    <datalist id="zuschlag-suggestions">
        <option value="0,10"></option>
        <option value="0,60"></option>
        <option value="1,10"></option>
        <option value="1,60"></option>
        <option value="2,10"></option>
        <option value="2,60"></option>
    </datalist>
</x-ui-page>
