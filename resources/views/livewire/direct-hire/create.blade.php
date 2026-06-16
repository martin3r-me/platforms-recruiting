@php
    use Platform\Recruiting\Services\DirectHireSetupService;
    use Platform\Recruiting\Livewire\DirectHire\Create;

    $standardFields = DirectHireSetupService::STANDARD_FIELDS;
    $lockedFields = Create::LOCKED_FIELDS;

    // Owner-Dropdown: Team-Mitglieder als value/label (x-ui-input-select-Idiom).
    $ownerOptions = $this->teamUsers
        ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])
        ->values()
        ->all();

    // Domain-Suffix für das Mail-Präfix-Feld statisch ableiten (aus der Intake-Adresse).
    $intakeAddress = $this->teamIntakeAddress;
    $mailDomain = $intakeAddress && str_contains($intakeAddress, '@')
        ? substr($intakeAddress, strrpos($intakeAddress, '@') + 1)
        : null;

    $isDone = $createdPositionId !== null;

    // Success-View: Code ODER Mail prominent + fertiger mailto-Link.
    $successCode = $createdRefCode;
    $successMail = $createdMailAddress;

    $mailtoHref = null;
    if ($isDone && $intakeAddress) {
        $subject = $successCode ? ('Bewerbung ' . $successCode) : 'Bewerbung';
        $mailtoHref = 'mailto:' . $intakeAddress . '?subject=' . rawurlencode($subject);
    }
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Direkteinstellung anlegen" icon="heroicon-o-bolt" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Stellen', 'href' => route('recruiting.positions.index')],
            ['label' => 'Direkteinstellung'],
        ]">
            <x-ui-button variant="secondary-outline" size="sm" href="{{ route('recruiting.positions.index') }}" wire:navigate>
                @svg('heroicon-o-arrow-left', 'w-4 h-4')
                <span>Zurück zu Stellen</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        @if ($isDone)
            {{-- ===================== Success-View ===================== --}}
            <x-ui-panel title="Direkteinstellung angelegt" subtitle="Die Stelle ist veröffentlicht und der Eingang ist eingerichtet.">
                <div class="space-y-6">
                    <div class="flex items-center gap-3 text-[var(--ui-success)]">
                        @svg('heroicon-o-check-circle', 'w-8 h-8')
                        <span class="text-lg font-semibold text-[var(--ui-secondary)]">Fertig eingerichtet</span>
                    </div>

                    @if ($successCode)
                        <div>
                            <div class="text-sm text-[var(--ui-muted)] mb-1">Referenz-Code für Bewerbungen</div>
                            <div class="text-2xl font-bold tracking-wider text-[var(--ui-secondary)] font-mono">{{ $successCode }}</div>
                            <p class="text-sm text-[var(--ui-muted)] mt-2">
                                Bewerber:innen senden ihre Bewerbung an die Eingangsadresse des Teams und geben diesen Code im Betreff an
                                (z.&nbsp;B. <span class="font-mono">Bewerbung {{ $successCode }}</span>).
                            </p>
                        </div>
                    @endif

                    @if ($successMail)
                        <div>
                            <div class="text-sm text-[var(--ui-muted)] mb-1">Eigene Bewerbungs-Adresse</div>
                            <div class="text-2xl font-bold text-[var(--ui-secondary)] font-mono">{{ $successMail }}</div>
                            <p class="text-sm text-[var(--ui-muted)] mt-2">
                                Bewerbungen an diese Adresse landen automatisch in dieser Direkteinstellung.
                            </p>
                        </div>
                    @endif

                    @if ($mailtoHref)
                        <div>
                            <div class="text-sm text-[var(--ui-muted)] mb-2">Zum Teilen mit Bewerber:innen</div>
                            <a href="{{ $mailtoHref }}"
                               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-[var(--ui-primary)] text-white text-sm font-medium hover:opacity-90 transition">
                                @svg('heroicon-o-envelope', 'w-4 h-4')
                                <span>Bewerbungs-Mail vorbereiten</span>
                            </a>
                            <p class="text-xs text-[var(--ui-muted)] mt-2">
                                Öffnet eine vorausgefüllte E-Mail an <span class="font-mono">{{ $intakeAddress }}</span>.
                            </p>
                        </div>
                    @elseif (! $successMail)
                        <div class="rounded-lg border border-[var(--ui-warning)]/40 bg-[var(--ui-warning)]/5 p-3 text-sm text-[var(--ui-muted)]">
                            Es ist keine aktive E-Mail-Eingangsadresse für dieses Team hinterlegt. Richte einen Intake-Kanal ein,
                            damit Bewerbungen mit dem Code automatisch zugeordnet werden.
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2 pt-2 border-t border-[var(--ui-border)]/60">
                        <x-ui-button variant="primary" size="sm" href="{{ route('recruiting.positions.show', $createdPositionId) }}" wire:navigate>
                            @svg('heroicon-o-eye', 'w-4 h-4')
                            <span>Stelle öffnen</span>
                        </x-ui-button>
                        <x-ui-button variant="secondary-outline" size="sm" href="{{ route('recruiting.direct-hire.create') }}" wire:navigate>
                            @svg('heroicon-o-plus', 'w-4 h-4')
                            <span>Weitere anlegen</span>
                        </x-ui-button>
                    </div>
                </div>
            </x-ui-panel>
        @else
            {{-- ===================== Formular ===================== --}}
            <x-ui-panel title="Neue Direkteinstellung" subtitle="Stelle, Ausschreibung und Eingang in einem Schritt einrichten.">
                <form wire:submit="create" class="space-y-6">
                    <x-ui-input-text
                        name="title"
                        label="Stellentitel"
                        wire:model="title"
                        required
                        placeholder="z. B. Servicekraft Aushilfe" />

                    <x-ui-input-select
                        name="ownerUserId"
                        label="Verantwortlich"
                        :options="$ownerOptions"
                        optionValue="value"
                        optionLabel="label"
                        wire:model="ownerUserId" />

                    {{-- Eingangsweg: EIN optionales Mail-Präfix-Feld. Leer → Code. --}}
                    <div>
                        <label for="mailPrefix" class="block text-sm font-medium text-[var(--ui-secondary)] mb-1">
                            Eigene Bewerbungs-Mail (optional)
                        </label>
                        <div class="flex items-stretch">
                            <input
                                id="mailPrefix"
                                type="text"
                                wire:model="mailPrefix"
                                placeholder="z. B. service-job"
                                class="flex-1 min-w-0 rounded-l-lg border border-[var(--ui-border)] bg-[var(--ui-bg)] px-3 py-2 text-sm text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-[var(--ui-primary)]/40" />
                            <span class="inline-flex items-center px-3 rounded-r-lg border border-l-0 border-[var(--ui-border)] bg-[var(--ui-muted-5)] text-sm text-[var(--ui-muted)] font-mono whitespace-nowrap">
                                @{{ $mailDomain ?? 'team-domain' }}
                            </span>
                        </div>
                        @error('mailPrefix')
                            <div class="text-sm text-[var(--ui-danger)] mt-1">{{ $message }}</div>
                        @enderror
                        <p class="text-xs text-[var(--ui-muted)] mt-1">
                            Leer lassen → wir erzeugen einen Code, den Bewerber:innen im Betreff angeben.
                        </p>
                    </div>

                    {{-- Standard-Datenfelder --}}
                    <div>
                        <div class="text-sm font-medium text-[var(--ui-secondary)] mb-2">Abgefragte Daten im Bewerber-Portal</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($standardFields as $field)
                                @php
                                    $fieldName = $field['name'];
                                    $isLocked = in_array($fieldName, $lockedFields, true);
                                @endphp
                                <label class="flex items-center gap-2 rounded-lg border border-[var(--ui-border)]/60 px-3 py-2 text-sm {{ $isLocked ? 'bg-[var(--ui-muted-5)] cursor-not-allowed' : 'cursor-pointer hover:bg-[var(--ui-muted-5)]' }}">
                                    @if ($isLocked)
                                        <input type="checkbox" checked disabled class="rounded border-[var(--ui-border)]" />
                                    @else
                                        <input type="checkbox" value="{{ $fieldName }}" wire:model="fields" class="rounded border-[var(--ui-border)]" />
                                    @endif
                                    <span class="text-[var(--ui-secondary)]">{{ $field['label'] }}</span>
                                    @if ($isLocked)
                                        <span class="ml-auto text-xs text-[var(--ui-muted)] inline-flex items-center gap-1">
                                            @svg('heroicon-o-lock-closed', 'w-3 h-3') Pflicht
                                        </span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        <p class="text-xs text-[var(--ui-muted)] mt-2">
                            Geburtsdatum und Ausweisnummer sind für den Portal-Login zwingend und immer aktiv.
                        </p>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-[var(--ui-border)]/60">
                        <x-ui-button type="button" variant="secondary-outline" href="{{ route('recruiting.positions.index') }}" wire:navigate>
                            Abbrechen
                        </x-ui-button>
                        <x-ui-button type="submit" variant="primary">
                            @svg('heroicon-o-bolt', 'w-4 h-4')
                            <span>Direkteinstellung anlegen</span>
                        </x-ui-button>
                    </div>
                </form>
            </x-ui-panel>
        @endif
    </x-ui-page-container>
</x-ui-page>
