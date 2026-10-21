{{-- Lohn an ZAS (Spec Lohn MA 2026-10-19 §11) — nur MA-Akten, nur lesen. Vertrag: $lohn (Array|null). --}}
@if ($lohn !== null)
    @php
        $zuschlagText = $lohn['zuschlag'] ?? null;
        // Pre-Merge-Review I2: unbekannt/steuerklasse_fehlt — Grundlohn geht wie der Zuschlag leer raus.
        $grundlohnText = $lohn['grundlohn'] ?? null;
        $istUnbekannt = $lohn['fall'] === 'unbekannt';
        $steuerklasseFehlt = $lohn['fall'] === 'steuerklasse_fehlt';
        // Hotfix 21.10.2026: ZAS fuehrt schon einen MA-Lohn und behaelt ihn — kein Fehler, kein Rot.
        $zasBehaelt = $lohn['fall'] === 'zas_bestand';
        $leerText = $zasBehaelt ? 'bleibt bei ZAS' : 'leer';
        $leerFarbe = $zasBehaelt ? 'text-gray-700' : 'text-red-700';
        $rgUnklar = (bool) ($lohn['rg_unklar'] ?? false);
        $rgLink = $lohn['rg_employee_id'] !== null ? route('recruiting.employees.show', ['employee' => $lohn['rg_employee_id']]) : null;
    @endphp
    <div id="lohn-zas" class="mt-4 rounded-lg border border-[var(--ui-border)] bg-white p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)]">Lohn an ZAS</h3>
            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">EG 1 gilt seit {{ $lohn['eg1_ab'] }}</span>
        </div>
        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
            <dt class="text-[var(--ui-muted)]">Grundlohn</dt>
            @if ($grundlohnText === null)
                <dd class="font-semibold {{ $leerFarbe }}">{{ $leerText }}</dd>
            @else
                <dd class="font-semibold text-gray-900">{{ $grundlohnText }} €</dd>
            @endif
            <dt class="text-[var(--ui-muted)]">Zuschlag</dt>
            @if ($zuschlagText === null)
                <dd class="font-semibold {{ $leerFarbe }}">{{ $leerText }}</dd>
            @else
                <dd class="font-semibold text-gray-900">{{ $zuschlagText }} €</dd>
            @endif
        </dl>
        <p class="mt-2 text-xs text-[var(--ui-muted)]">{{ $lohn['begruendung'] }}</p>
        @if ($steuerklasseFehlt)
            <p class="mt-1 text-xs font-medium text-amber-700">Steuerklasse in der Akte bzw. im Portal pflegen.</p>
        @endif
        @if ($istUnbekannt && $rgLink !== null && $rgUnklar)
            <a href="{{ $rgLink }}" class="mt-2 inline-block text-xs font-medium text-blue-700 hover:underline">RG-Anstellung mit gleicher Kennung prüfen (Personen-Zuordnung) →</a>
        @elseif ($istUnbekannt && $rgLink !== null)
            <a href="{{ $rgLink }}" class="mt-2 inline-block text-xs font-medium text-blue-700 hover:underline">RG-Zuschlag in der RG-Akte eintragen →</a>
        @endif
    </div>
@endif
