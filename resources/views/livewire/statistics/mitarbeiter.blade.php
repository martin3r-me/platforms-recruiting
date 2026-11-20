{{--
    Statistik → Reiter „Mitarbeiter“ (Markus 18./19.11.2026). Gerechnet wird im
    MitarbeiterStatistikRechner; hier wird nur gezeigt. Jede Zahl ist anklickbar und öffnet die
    Personen dahinter.
--}}
@php
    $a = $this->auswertung;
    $zeilen = $this->zeilen;
    $summe = $this->summe;
    $stufen = \Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikRechner::STUFEN_LABEL;
    $ksFilter = (is_string($this->kostenstelleFilter) || is_int($this->kostenstelleFilter)) ? (string) $this->kostenstelleFilter : 'alle';
    $fmtAbw = fn ($n) => $n === null ? '–' : ($n > 0 ? '+' . $n : ($n < 0 ? '−' . abs($n) : '0'));
    $abwKlasse = fn ($n) => $n === null ? '' : ($n < 0 ? 'text-red-700' : ($n > 0 ? 'text-green-700' : ''));
    $fmtDatum = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y') : '–';
    $zeitraum = $fmtDatum($this->von) . ' – ' . $fmtDatum($this->bis);
    $stichtag = $fmtDatum($a['stichtag']);
    $regel = 'Aktiv = aktive Mitarbeiter der gewählten Gesellschaft, jede Person einmal (auch wenn sie bei RG und MA geführt wird). '
        . 'Aktiv heißt Status GO oder MA in ZAS (über „Status“ einzeln wählbar) – der Status „MA“ hat nichts mit der Firma MA zu tun. Es zählen nur Einsätze der gewählten Gesellschaft. '
        . 'Produktiv = aktiv und mindestens ein disponierter Einsatz (Auftrag oder beendet, nicht abgesagt/verschwunden) '
        . 'in den 60 Tagen bis ' . $stichtag . '; ist das heute, zählen auch schon disponierte kommende Einsätze. '
        . 'Termine = disponierte Einsätze im gewählten Zeitraum bis heute. '
        . 'Rückmeldung = mindestens eine eingehende WhatsApp-Nachricht im Zeitraum.';
    $zahlKnopf = 'underline decoration-dotted underline-offset-2 hover:text-[var(--ui-primary)] cursor-pointer';
@endphp

<div class="space-y-6 p-6">

    @include('recruiting::livewire.statistics.tabs', ['statAktivRoute' => 'recruiting.statistics.employees'])

    <x-ui-panel title="Mitarbeiter" subtitle="Aktive und produktive Mitarbeiter je Kostenstelle — jede Zahl ist anklickbar" class="relative z-30">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            <div class="grid grid-cols-2 gap-2">
                <x-ui-input-select
                    name="gesellschaft"
                    label="Gesellschaft"
                    size="sm"
                    :options="\Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikRechner::GESELLSCHAFTEN"
                    wire:model.live="gesellschaft"
                />
                <x-ui-input-select
                    name="statusFilter"
                    label="Status (ZAS)"
                    size="sm"
                    :options="\Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikRechner::STATUSFILTER"
                    wire:model.live="statusFilter"
                />
            </div>
            <x-ui-input-select
                name="kostenstelleFilter"
                label="Kostenstelle"
                size="sm"
                :options="$this->kostenstellenOptionen"
                wire:model.live="kostenstelleFilter"
            />
            <div class="grid grid-cols-2 gap-2">
                <x-ui-input-date name="von" label="Termine von" size="sm" wire:model.live="von" />
                <x-ui-input-date name="bis" label="bis" size="sm" wire:model.live="bis" />
            </div>
            <div class="flex justify-end">
                <x-ui-button variant="secondary-outline" size="sm" wire:click="oeffneEinstellungen">
                    Soll &amp; Report einstellen
                </x-ui-button>
            </div>
        </div>
        <div class="mt-3 text-xs text-[color:var(--ui-muted)]">
            Zeitraum für Termine: {{ $zeitraum }} · Produktiv: 60 Tage bis {{ $stichtag }}
            <span class="ml-1 cursor-help" title="{{ $regel }}">ⓘ</span>
        </div>
    </x-ui-panel>

    {{-- Kacheln --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach ([
            ['label' => 'Aktive', 'wert' => $summe['aktiv'], 'merkmal' => 'aktiv'],
            ['label' => 'Produktive', 'wert' => $summe['produktiv'], 'merkmal' => 'produktiv'],
            ['label' => '0 Termine im Zeitraum', 'wert' => $summe['stufen']['0'], 'merkmal' => 'stufe:0'],
        ] as $kachel)
            <button type="button" wire:click="drill('{{ $ksFilter }}', '{{ $kachel['merkmal'] }}')"
                class="text-left p-4 rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)] hover:border-[var(--ui-primary)]">
                <div class="text-xs uppercase tracking-wider text-[color:var(--ui-muted)]">{{ $kachel['label'] }}</div>
                <div class="mt-1 text-3xl font-semibold text-[var(--ui-secondary)]">{{ $kachel['wert'] }}</div>
            </button>
        @endforeach
    </div>

    {{-- Tabelle je Kostenstelle --}}
    <x-ui-panel title="Je Kostenstelle" subtitle="Soll, Aktiv, Produktiv und Termine im Zeitraum">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-[color:var(--ui-muted)] border-b border-[var(--ui-border)]">
                        <th class="py-2 pr-4">Kostenstelle</th>
                        <th class="py-2 px-3 text-right">Soll</th>
                        <th class="py-2 px-3 text-right">Aktiv</th>
                        <th class="py-2 px-3 text-right">Produktiv</th>
                        <th class="py-2 px-3 text-right">Abweichung</th>
                        @foreach ($stufen as $stufeLabel)
                            <th class="py-2 px-3 text-right">{{ $stufeLabel }}</th>
                        @endforeach
                        <th class="py-2 px-3 text-right" title="Mitarbeiter mit 0 Terminen: mit / ohne eingehende Nachricht im Zeitraum">0 Termine: Rückmeldung ja / nein</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $tabellenZeilen = $zeilen;
                        if (count($zeilen) > 1) {
                            $tabellenZeilen[] = ['gesamt' => true, 'kostenstelle' => null] + $summe;
                        }
                    @endphp
                    @forelse ($tabellenZeilen as $z)
                        @php
                            $istGesamt = !empty($z['gesamt']);
                            $ks = $istGesamt ? $ksFilter : ($z['kostenstelle'] === null ? 'ohne' : (string) $z['kostenstelle']);
                            $name = $istGesamt ? 'Gesamt' : \Platform\Recruiting\Livewire\Statistics\Mitarbeiter::kostenstellenName($z['kostenstelle']);
                        @endphp
                        <tr wire:key="ks-{{ $istGesamt ? 'gesamt' : $ks }}" class="border-b border-[var(--ui-border)]/50 {{ $istGesamt ? 'font-semibold' : '' }}">
                            <td class="py-2 pr-4">{{ $name }}</td>
                            <td class="py-2 px-3 text-right">{{ $z['soll'] ?? '–' }}</td>
                            <td class="py-2 px-3 text-right"><button type="button" class="{{ $zahlKnopf }}" wire:click="drill('{{ $ks }}', 'aktiv')">{{ $z['aktiv'] }}</button></td>
                            <td class="py-2 px-3 text-right"><button type="button" class="{{ $zahlKnopf }}" wire:click="drill('{{ $ks }}', 'produktiv')">{{ $z['produktiv'] }}</button></td>
                            <td class="py-2 px-3 text-right {{ $abwKlasse($z['abweichung']) }}">
                                @if ($z['abweichung'] !== null && $z['abweichung'] < 0)
                                    <button type="button" class="{{ $zahlKnopf }}" wire:click="drill('{{ $ks }}', 'nicht_produktiv')" title="Aktiv, aber nicht produktiv">{{ $fmtAbw($z['abweichung']) }}</button>
                                @else
                                    {{ $fmtAbw($z['abweichung']) }}
                                @endif
                            </td>
                            @foreach ($stufen as $stufeKey => $stufeLabel)
                                <td class="py-2 px-3 text-right"><button type="button" class="{{ $zahlKnopf }}" wire:click="drill('{{ $ks }}', 'stufe:{{ $stufeKey }}')">{{ $z['stufen'][$stufeKey] }}</button></td>
                            @endforeach
                            <td class="py-2 px-3 text-right whitespace-nowrap">
                                <button type="button" class="{{ $zahlKnopf }}" wire:click="drill('{{ $ks }}', 'rueckmeldung:ja')">{{ $z['rueckmeldung_ja'] }}</button>
                                /
                                <button type="button" class="{{ $zahlKnopf }}" wire:click="drill('{{ $ks }}', 'rueckmeldung:nein')">{{ $z['rueckmeldung_nein'] }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="py-6 text-center text-[color:var(--ui-muted)]">Keine aktiven Mitarbeiter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-[color:var(--ui-muted)]">
            Abweichung = Produktiv minus Soll; ohne eingetragenes Soll steht „–“. Ein Klick auf eine negative Abweichung zeigt, wer aktiv, aber nicht produktiv ist.
        </p>
    </x-ui-panel>

    {{-- Personen hinter einer Zahl --}}
    <x-ui-modal wire:model="showDrill" size="lg" :hideFooter="true">
        <x-slot name="header">{{ $this->drillTitel }} ({{ count($this->drillPersonen) }})</x-slot>
        @if (count($this->drillPersonen) === 0)
            <div class="py-6 text-center text-sm text-[color:var(--ui-muted)]">Keine Personen in dieser Auswahl.</div>
        @else
            <div class="overflow-x-auto max-h-[60vh]">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-[color:var(--ui-muted)] border-b border-[var(--ui-border)]">
                            <th class="py-2 pr-3">Name</th>
                            <th class="py-2 px-3">PNr</th>
                            <th class="py-2 px-3">Kostenstelle</th>
                            <th class="py-2 px-3 text-right">Termine</th>
                            <th class="py-2 px-3">Letzter Einsatz</th>
                            <th class="py-2 px-3">Nächster Einsatz</th>
                            <th class="py-2 px-3">Rückmeldung</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->drillPersonen as $p)
                            <tr wire:key="p-{{ $p['person'] }}" class="border-b border-[var(--ui-border)]/50">
                                <td class="py-1.5 pr-3">
                                    <a href="{{ route('recruiting.employees.show', $p['employee_id']) }}" class="hover:underline" target="_blank">{{ $p['name'] }}</a>
                                </td>
                                <td class="py-1.5 px-3">{{ $p['pnr'] !== '' ? $p['pnr'] : '–' }}</td>
                                <td class="py-1.5 px-3">{{ \Platform\Recruiting\Livewire\Statistics\Mitarbeiter::kostenstellenName($p['kostenstelle']) }}</td>
                                <td class="py-1.5 px-3 text-right">{{ $p['termine'] }}</td>
                                <td class="py-1.5 px-3">{{ $fmtDatum($p['letzter_einsatz']) }}</td>
                                <td class="py-1.5 px-3">{{ $fmtDatum($p['naechster_einsatz']) }}</td>
                                <td class="py-1.5 px-3">{{ $p['rueckmeldung'] ? 'ja' : 'nein' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui-modal>

    {{-- Soll je Kostenstelle und Empfänger des Reports. Bewusst einfache Eingabefelder statt
         x-ui-input-select im Fenster (bekannte Falle: Auswahl geht beim Speichern verloren). --}}
    <x-ui-modal wire:model="showEinstellungen" size="lg" :hideFooter="true">
        <x-slot name="header">Soll &amp; täglicher Report</x-slot>
        <div class="space-y-6 text-sm">
            <div>
                <div class="font-semibold mb-2">Soll-Mitarbeiter je Kostenstelle</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach ($this->sollKostenstellen() as $ks)
                        <label class="flex items-center justify-between gap-3" wire:key="soll-{{ $ks }}">
                            <span>{{ \Platform\Recruiting\Livewire\Statistics\Mitarbeiter::kostenstellenName($ks) }}</span>
                            <input type="text" inputmode="numeric" wire:model="sollWerte.{{ $ks }}" placeholder="–"
                                class="w-24 rounded border border-[var(--ui-border)] bg-white px-2 py-1 text-right text-black">
                        </label>
                    @endforeach
                </div>
                <label class="mt-3 flex items-center gap-3">
                    <span>gültig ab</span>
                    <input type="date" wire:model="sollGueltigAb" class="rounded border border-[var(--ui-border)] bg-white px-2 py-1 text-black">
                </label>
                <p class="mt-1 text-xs text-[color:var(--ui-muted)]">Gespeichert werden nur Felder, die du änderst; ältere Sollzahlen bleiben als Verlauf erhalten. Feld leeren = ab diesem Tag kein Soll.</p>
            </div>

            <div>
                <div class="font-semibold mb-2">Täglicher Report (Montag bis Freitag, 8 Uhr)</div>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="reportAktiv">
                    <span>Report per E-Mail senden</span>
                </label>
                <div class="mt-3">
                    <div class="mb-1">Empfänger</div>
                    @if (count($empfaengerListe) === 0)
                        <div class="text-xs text-[color:var(--ui-muted)]">Noch keine Empfänger.</div>
                    @else
                        <ul class="divide-y divide-[var(--ui-border)]/50 rounded border border-[var(--ui-border)]">
                            @foreach ($empfaengerListe as $mail)
                                <li wire:key="empf-{{ md5($mail) }}" class="flex items-center justify-between gap-3 px-3 py-1.5">
                                    <span>
                                        @if (isset($this->teamBenutzer[$mail]))
                                            {{ $this->teamBenutzer[$mail] }} <span class="text-xs text-[color:var(--ui-muted)]">{{ $mail }}</span>
                                        @else
                                            {{ $mail }}
                                        @endif
                                    </span>
                                    <button type="button" wire:click="entferneEmpfaenger(@js($mail))" class="text-[color:var(--ui-muted)] hover:text-red-700" title="Entfernen">✕</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-xs text-[color:var(--ui-muted)]">HCM-Benutzer hinzufügen</span>
                            <select wire:model.live="neuerBenutzer" class="mt-1 w-full rounded border border-[var(--ui-border)] bg-white px-2 py-1 text-black">
                                <option value="">– auswählen –</option>
                                @foreach ($this->teamBenutzer as $mail => $benutzerName)
                                    @if (!in_array($mail, $empfaengerListe, true))
                                        <option value="{{ $mail }}">{{ $benutzerName }} ({{ $mail }})</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                        <div>
                            <span class="text-xs text-[color:var(--ui-muted)]">Andere Adresse (ohne HCM-Zugang)</span>
                            <div class="mt-1 flex gap-2">
                                <input type="email" wire:model="neueAdresse" wire:keydown.enter.prevent="fuegeAdresseHinzu" placeholder="name@firma.de"
                                    class="w-full rounded border border-[var(--ui-border)] bg-white px-2 py-1 text-black">
                                <x-ui-button variant="secondary-outline" size="sm" wire:click="fuegeAdresseHinzu">Hinzufügen</x-ui-button>
                            </div>
                            @if ($adressMeldung !== '')
                                <div class="mt-1 text-xs text-red-700">{{ $adressMeldung }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if ($einstellungenMeldung !== '')
                <div class="rounded border border-red-300 bg-red-50 px-3 py-2 text-red-800">{{ $einstellungenMeldung }}</div>
            @endif

            <div class="flex justify-end gap-2">
                <x-ui-button variant="secondary-outline" size="sm" wire:click="$set('showEinstellungen', false)">Abbrechen</x-ui-button>
                <x-ui-button variant="primary" size="sm" wire:click="speichereEinstellungen">Speichern</x-ui-button>
            </div>

            @if (count($this->sollVerlauf) > 0)
                <div>
                    <div class="font-semibold mb-2">Verlauf</div>
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="text-left text-[color:var(--ui-muted)]">
                                <th class="py-1 pr-3">gültig ab</th>
                                <th class="py-1 pr-3">Kostenstelle</th>
                                <th class="py-1 pr-3 text-right">Soll</th>
                                <th class="py-1 pr-3">Herkunft</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->sollVerlauf as $v)
                                <tr>
                                    <td class="py-1 pr-3">{{ $v['gueltig_ab'] }}</td>
                                    <td class="py-1 pr-3">{{ $v['kostenstelle'] }}</td>
                                    <td class="py-1 pr-3 text-right">{{ $v['soll'] ?? 'kein Soll' }}</td>
                                    <td class="py-1 pr-3">{{ $v['herkunft'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-ui-modal>
</div>
