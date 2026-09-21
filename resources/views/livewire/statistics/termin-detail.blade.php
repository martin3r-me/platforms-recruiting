{{--
    SCHULUNG IM DETAIL — der Rumpf des Modals (Rahmen und Kopf stehen in
    index.blade.php).

    Die Chips SIND die Filter der Personenliste (09.09.2026): Tiefe per Klick
    statt per Spalte. Seit 21.09.2026 traegt die Liste ausserdem den
    KLAERUNGS-HAKEN — wer „ohne Einsatz“ dasteht, aber einen guten Grund hat
    („faengt erst naechsten Monat an“), wird hier abgehakt und verlaesst damit
    die Arbeitsliste und den Sammelversand.

    $this-> statt extrahierter View-Variablen: die Render-Probe der Tests
    bindet nur die Komponente, nicht Livewires Property-Export.
--}}
    @php
        // $this-> statt extrahierter View-Variablen: die Render-Probe der
        // Tests bindet nur die Komponente, nicht Livewires Property-Export.
        // Eine Berechnung je Request (terminDetailData merkt sie sich) —
        // der Sammelversand unten liest dieselbe Personenliste.
        $terminDetail = $this->terminDetailData();
    @endphp
    @if ($terminDetail === null)
        <div class="py-6 text-center text-sm text-[color:var(--ui-muted)]">Termin nicht gefunden.</div>
    @else
        @php $tk = $terminDetail['kennzahlen']; @endphp
        <div class="mb-3 space-y-1 text-sm text-[color:var(--ui-secondary)]">
            <div class="font-semibold">
                {{ $terminDetail['interview']->starts_at?->format('d.m.Y H:i') }}
                · {{ $terminDetail['interview']->position?->title ?? 'ohne Stelle' }}
            </div>
            @if (($terminDetail['interview']->location ?? '') !== '')
                <div class="text-xs text-[color:var(--ui-muted)]">{{ $terminDetail['interview']->location }}</div>
            @endif
            <div class="text-xs text-[color:var(--ui-muted)]">
                Schulungsleiter:
                {{ $terminDetail['leiter'] === [] ? 'am Termin nicht gepflegt' : implode(', ', $terminDetail['leiter']) }}
            </div>
        </div>
        @php
            // Die Chips SIND die Filter der Liste (Kundenwunsch 09.09.):
            // Default nur Teilgenommene — Nicht-Erschienene & Co. sieht man
            // erst ueber „“. Unbekannter Filterwert (gecraftetes
            // $set) faellt auf den Default zurueck.
            $aktiverFilter = in_array($this->terminDetailFilter, ['teilgenommen', 'im_einsatz', 'geklaert', 'ohne_einsatz', 'einsatz_unpruefbar', 'alle'], true)
                ? $this->terminDetailFilter : 'teilgenommen';
            $filterChips = [
                ['key' => 'teilgenommen', 'label' => $tk['teilgenommen'] . ' teilgenommen', 'chip' => 'bg-sky-100 text-sky-900',
                 'title' => 'Alle, die die Schulung bestanden haben — die Bezugsgröße des Dispo-Abgleichs.'],
                ['key' => 'im_einsatz', 'label' => $tk['im_einsatz'] . ' im Einsatz', 'chip' => 'bg-indigo-100 text-indigo-900',
                 'title' => 'Teilgenommene mit mindestens einer Dispo-Zuweisung.'],
                ['key' => 'geklaert', 'label' => $tk['geklaert'] . ' geklärt', 'chip' => 'bg-teal-100 text-teal-900',
                 'title' => 'Von Hand abgehakt: kein Einsatz, aber ein bekannter Grund („fängt später an“, „mit der Dispo besprochen“). Sie stehen nicht mehr auf der Arbeitsliste und bekommen keine Nachfass-Nachricht.'],
                ['key' => 'ohne_einsatz', 'label' => $tk['ohne_einsatz'] . ' ohne Einsatz', 'chip' => 'bg-orange-100 text-orange-900',
                 'title' => 'Mitarbeiter mit ZAS-Personalnummer, aber ohne Zuweisung und ohne Klärung — die Nachverfolgungs-Liste.'],
                ['key' => 'einsatz_unpruefbar', 'label' => $tk['einsatz_unpruefbar'] . ' nicht prüfbar', 'chip' => 'bg-gray-200 text-gray-700',
                 'title' => 'Ohne Mitarbeiter oder ohne ZAS-Personalnummer ist keine Dispo-Aussage möglich — der Import ordnet nur über die Personalnummer zu.'],
                ['key' => 'alle', 'label' => $tk['ids'] . ' alle Buchungen', 'chip' => 'bg-[var(--ui-muted-5)] text-[color:var(--ui-secondary)]',
                 'title' => 'Inklusive Nicht erschienen, Vor Ort aussortiert und Keine Reaktion.'],
            ];
        @endphp
        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            @foreach ($filterChips as $chip)
                <button type="button" wire:click="$set('terminDetailFilter', @js($chip['key']))"
                        title="{{ $chip['title'] }}"
                        @class([
                            'rounded-full px-2 py-0.5 font-medium transition-all cursor-pointer',
                            $chip['chip'],
                            'ring-2 ring-[var(--ui-primary)]' => $aktiverFilter === $chip['key'],
                            'ring-1 ring-[var(--ui-border)]/60 hover:ring-2 hover:ring-[var(--ui-border)]' => $aktiverFilter !== $chip['key'],
                        ])>
                    {{ $chip['label'] }}
                </button>
            @endforeach
            <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-900">{{ $tk['vertrag_verschickt'] }} Verträge · {{ $tk['unterschrieben'] }} unterschrieben</span>
        </div>
        @php
            // Sammelversand „ohne Einsatz“ (14.09.2026): Haekchen und Badges
            // stehen an den Personen, die Steuerung im Partial unter der
            // Tabelle. Beides nur unter DIESEM Chip — die Liste ist dann
            // genau die Empfaengerliste.
            $versandAktiv = $this->noAssignmentEnabled();
            $versandZeilen = $versandAktiv ? $this->noAssignmentRows : [];
            $versandFortschritt = $versandAktiv ? $this->noAssignmentProgress : null;
            $versandLaeuft = $versandFortschritt !== null && !($versandFortschritt['done'] ?? false);
            // Person, Status, Best., Vertrag, Einsätze, Klärung (+ Senden)
            $versandSpalten = $versandAktiv ? 7 : 6;
        @endphp
        <div class="max-h-[55vh] overflow-auto rounded-lg border border-[var(--ui-border)]/60">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)] text-left text-xs uppercase tracking-wide text-[var(--ui-muted)]">
                        @if ($versandAktiv)
                            <th class="w-8 px-3 py-2" title="Wer die Nachfrage bekommen soll"><span class="sr-only">Senden</span></th>
                        @endif
                        <th class="px-3 py-2">Person</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2 text-center" title="Hat die Schulung bestätigt (WhatsApp oder HR)">Best.</th>
                        <th class="px-3 py-2">Vertrag</th>
                        <th class="px-3 py-2">Einsätze</th>
                        <th class="px-3 py-2" title="Kein Einsatz, aber ein bekannter Grund: abhaken nimmt den Fall von der Arbeitsliste und aus dem Sammelversand. Mit Wiedervorlage-Datum steht er ab diesem Tag wieder da.">Klärung</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--ui-border)]/60">
                    @php
                        $gefiltertePersonen = array_values(array_filter($terminDetail['personen'], fn ($p) => match ($aktiverFilter) {
                            'teilgenommen' => $p['status'] === 'Teilgenommen',
                            'im_einsatz', 'geklaert', 'ohne_einsatz', 'einsatz_unpruefbar' => $p['topf'] === $aktiverFilter,
                            default => true,
                        }));
                    @endphp
                    @if ($gefiltertePersonen === [])
                        <tr><td colspan="{{ $versandSpalten }}" class="px-3 py-4 text-center text-xs text-[color:var(--ui-muted)]">Niemand in dieser Auswahl.</td></tr>
                    @endif
                    @foreach ($gefiltertePersonen as $person)
                        @php $versandZeile = $versandZeilen[$person['id']] ?? null; @endphp
                        <tr @class([
                                'bg-orange-50/60' => $person['topf'] === 'ohne_einsatz',
                                'bg-teal-50/40' => $person['topf'] === 'geklaert',
                            ])>
                            @if ($versandAktiv)
                                <td class="px-3 py-2">
                                    <input type="checkbox" class="h-4 w-4 rounded border-[var(--ui-border)]"
                                           wire:model.live="noAssignmentSelection.{{ $person['id'] }}"
                                           @disabled($versandZeile === null || !$versandZeile['selectable'] || $versandLaeuft) />
                                </td>
                            @endif
                            <td class="px-3 py-2">
                                <a href="{{ $person['employee']
                                        ? route('recruiting.employees.show', $person['employee'])
                                        : ($person['applicant'] ? route('recruiting.applicants.show', $person['applicant']) : '#') }}"
                                   class="text-[color:var(--ui-primary)] hover:underline">{{ $person['name'] }}</a>
                                @if ($versandZeile !== null)
                                    @foreach ($versandZeile['badges'] as $badge)
                                        <span class="ml-1 inline-block rounded bg-[var(--ui-muted-5)] px-1.5 py-0.5 text-[11px] text-[color:var(--ui-muted)]">{{ $badge }}</span>
                                    @endforeach
                                @elseif ($versandAktiv)
                                    {{-- Der Loader laesst ausgeschiedene, geparkte und abgesagte
                                         Bewerbungen weg. Ohne diesen Hinweis staende hier ein
                                         totes Kaestchen ohne Begruendung. --}}
                                    <span class="ml-1 inline-block rounded bg-[var(--ui-muted-5)] px-1.5 py-0.5 text-[11px] text-[color:var(--ui-muted)]"
                                          title="Bewerbung ist nicht mehr aktiv, geparkt oder abgesagt — sie wird nicht angeschrieben.">nicht anschreibbar</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs">{{ $person['status'] }}</td>
                            <td class="px-3 py-2 text-center text-xs">{{ $person['bestaetigt'] ? '✓' : '–' }}</td>
                            <td class="px-3 py-2 text-xs">
                                {{ $person['vertrag'] === 'unterschrieben' ? 'unterschrieben' : ($person['vertrag'] === 'verschickt' ? 'verschickt' : '–') }}
                            </td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap tabular-nums">
                                @if ($person['topf'] === 'im_einsatz')
                                    {{ $person['einsaetze'] }} {{ $person['einsaetze'] === 1 ? 'Einsatz' : 'Einsätze' }}
                                    · erster {{ \Illuminate\Support\Carbon::parse($person['erster_einsatz'])->format('d.m.Y') }}
                                @elseif ($person['topf'] === 'ohne_einsatz')
                                    <span class="font-medium text-orange-700">keine Einsätze</span>
                                @elseif ($person['grund'] === 'kein_ma')
                                    <span class="text-[color:var(--ui-muted)]">kein Mitarbeiter angelegt</span>
                                @elseif ($person['grund'] === 'keine_pnr')
                                    <span class="text-[color:var(--ui-muted)]">keine ZAS-Personalnummer</span>
                                @elseif ($person['topf'] === 'geklaert')
                                    <span class="text-[color:var(--ui-muted)]">keine Einsätze</span>
                                @else
                                    <span class="text-[color:var(--ui-muted)]"
                                          title="Nicht teilgenommen — der Dispo-Abgleich zählt nur Bestandene.">–</span>
                                @endif
                            </td>
                            {{-- KLAERUNG. Abhaken kann nur, wer eine Buchung an
                                 DIESEM Termin hat und in der Nachverfolgung steht
                                 („ohne Einsatz“) oder schon abgehakt ist. „Nicht
                                 prüfbar“ ist ein anderer Arbeitsauftrag (Mitarbeiter
                                 anlegen, Personalnummer nachtragen) und bekommt
                                 deshalb keinen Haken.

                                 Ein Haken, der LIEGEN GEBLIEBEN ist, weil inzwischen
                                 ein Einsatz kam, wird trotzdem gezeigt — unsichtbar
                                 würde er die Person still wieder verstecken, sobald
                                 die Zuweisung eines Tages wegfällt. Lösen geht
                                 deshalb immer, neu setzen nur auf der Arbeitsliste
                                 (dieselbe Schranke sitzt in der Komponente). --}}
                            @php
                                $klaerbar = in_array($person['topf'], ['ohne_einsatz', 'geklaert'], true);
                                $hatNotiz = ($person['geklaert_note'] ?? null) !== null;
                            @endphp
                            <td class="px-3 py-2 text-xs">
                                @if ($person['booking_id'] === null || (!$klaerbar && !$hatNotiz))
                                    <span class="text-[color:var(--ui-muted)]">–</span>
                                @elseif ($person['geklaert'])
                                    <div class="space-y-0.5">
                                        @if ($klaerbar)
                                            <div class="font-medium text-teal-800">✓ geklärt</div>
                                        @else
                                            <div class="font-medium text-[color:var(--ui-muted)]"
                                                 title="Der Haken wirkt nicht mehr — die Person ist im Einsatz. Er bliebe aber liegen und würde wieder greifen, wenn die Zuweisung wegfällt.">✓ geklärt (nicht mehr nötig)</div>
                                        @endif
                                        <div class="text-[11px] text-[color:var(--ui-secondary)]">{{ $person['geklaert_note'] }}</div>
                                        @if ($person['geklaert_wiedervorlage'] !== null)
                                            <div class="text-[11px] text-[color:var(--ui-muted)]">
                                                wieder auf der Liste ab {{ \Illuminate\Support\Carbon::parse($person['geklaert_wiedervorlage'])->format('d.m.Y') }}
                                            </div>
                                        @endif
                                        <div class="flex gap-2 text-[11px]">
                                            @if ($klaerbar)
                                                <button type="button" class="underline"
                                                        wire:click="openKlaerung({{ $person['booking_id'] }})">bearbeiten</button>
                                            @endif
                                            <button type="button" class="underline text-orange-700"
                                                    wire:click="removeKlaerung({{ $person['booking_id'] }})">Haken entfernen</button>
                                        </div>
                                    </div>
                                @elseif ($klaerbar)
                                    <div class="space-y-1">
                                        @if ($hatNotiz)
                                            {{-- Abgelaufen: der Fall steht wieder auf der Liste, die
                                                 alte Begründung bleibt aber lesbar — sonst rätselt
                                                 man beim Wiedersehen, was damals besprochen war. --}}
                                            <div class="text-[11px] text-[color:var(--ui-muted)]">
                                                Klärung abgelaufen: {{ $person['geklaert_note'] }}
                                            </div>
                                        @endif
                                        <button type="button"
                                                class="rounded-full border border-[var(--ui-border)] px-2 py-0.5 text-[11px] font-medium hover:bg-[var(--ui-muted-5)] cursor-pointer"
                                                title="Grund festhalten und den Fall von der Arbeitsliste nehmen."
                                                wire:click="openKlaerung({{ $person['booking_id'] }})">abhaken</button>
                                    </div>
                                @else
                                    {{-- Abgelaufener Haken an einer Zeile, die gar nicht
                                         mehr auf der Arbeitsliste steht: nur noch lesen und
                                         wegräumen. --}}
                                    <div class="space-y-1">
                                        <div class="text-[11px] text-[color:var(--ui-muted)]">
                                            Alte Klärung: {{ $person['geklaert_note'] }}
                                        </div>
                                        <button type="button" class="underline text-[11px] text-orange-700"
                                                wire:click="removeKlaerung({{ $person['booking_id'] }})">Haken entfernen</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @if ($this->klaerungBookingId !== null && $this->klaerungBookingId === $person['booking_id'])
                            {{-- Das Formular steht INLINE unter der Person: ein
                                 zweites Modal über dem Modal wäre der Ort, an dem
                                 man nicht mehr sieht, worüber man gerade entscheidet. --}}
                            <tr class="bg-teal-50/60">
                                <td colspan="{{ $versandSpalten }}" class="px-3 py-3">
                                    <div class="space-y-2">
                                        <label class="block text-xs font-medium text-[color:var(--ui-secondary)]">
                                            Was ist geklärt? <span class="text-orange-700">*</span>
                                            <input type="text" maxlength="500" wire:model="klaerungNote"
                                                   placeholder="z. B. fängt erst im Oktober an — mit der Dispo besprochen"
                                                   class="mt-1 w-full rounded border border-[var(--ui-border)] px-2 py-1 text-sm" />
                                        </label>
                                        <label class="block text-xs font-medium text-[color:var(--ui-secondary)]">
                                            Wieder anzeigen ab (optional)
                                            <input type="date" wire:model="klaerungWiedervorlage"
                                                   class="mt-1 rounded border border-[var(--ui-border)] px-2 py-1 text-sm" />
                                        </label>
                                        <div class="text-[11px] text-[color:var(--ui-muted)]">
                                            Leer lassen heißt: dauerhaft geklärt, bis jemand den Haken entfernt oder ein Einsatz kommt.
                                        </div>
                                        @if ($this->klaerungError !== '')
                                            <div class="text-xs font-medium text-red-700">{{ $this->klaerungError }}</div>
                                        @endif
                                        <div class="flex gap-2">
                                            <button type="button" wire:click="saveKlaerung" wire:loading.attr="disabled"
                                                    class="rounded bg-[var(--ui-primary)] px-3 py-1 text-xs font-medium text-white cursor-pointer">Speichern</button>
                                            <button type="button" wire:click="closeKlaerung"
                                                    class="rounded border border-[var(--ui-border)] px-3 py-1 text-xs cursor-pointer">Abbrechen</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($versandAktiv)
            @include('recruiting::livewire.statistics.no-assignment-campaign')
        @endif
    @endif
