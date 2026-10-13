{{-- Jobs anbieten → Übersicht (Spec 2026-10-12 §4). Du-Form; eine Zusage ist nie „gebucht“. --}}
<div class="p-6 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Jobs anbieten</h1>
            @include('recruiting::anleitungen._hilfe', ['route' => 'recruiting.dispo.jobs.index'])
            @if ($offeneZusagen > 0)
                <span class="rounded-full border border-orange-200 bg-orange-50 px-2 py-0.5 text-xs text-orange-700">{{ $offeneZusagen }} {{ $offeneZusagen === 1 ? 'Zusage wartet' : 'Zusagen warten' }} auf ZAS</span>
            @endif
        </div>
        <a href="{{ route('recruiting.dispo.jobs.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Neue Anfrage</a>
    </div>

    @php $pillen = ['offen' => 'Offen', 'zu' => 'Abgeschlossen', 'alle' => 'Alle']; @endphp
    <div class="flex flex-wrap gap-2 text-sm">
        @foreach ($pillen as $wert => $label)
            @php $pilleKlasse = $filter === $wert ? 'border-blue-600 bg-blue-50 font-semibold text-blue-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'; @endphp
            <button type="button" wire:click="$set('filter', '{{ $wert }}')" class="rounded-full border px-3 py-1 {{ $pilleKlasse }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($anfragen->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500">
            @if ($filter === 'offen')
                Keine offene Anfrage. Mit „Neue Anfrage“ schreibst du Mitarbeiter an, die an deinen offenen Schichten noch frei sind.
            @else
                Keine Anfrage in dieser Auswahl.
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Filiale</th>
                        <th class="px-4 py-2 font-medium">Zeitraum</th>
                        <th class="px-4 py-2 font-medium">Anlass</th>
                        <th class="px-4 py-2 font-medium">Schichten</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Zusagen</th>
                        <th class="px-4 py-2 font-medium">Noch in ZAS</th>
                        <th class="px-4 py-2 font-medium">Letzte Welle</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($anfragen as $a)
                        @php
                            $offen = $a->closed_at === null && $a->status !== \Platform\Recruiting\Models\RecDispoJobRequest::STATUS_ABGESCHLOSSEN;
                            $statusText = !$offen ? 'abgeschlossen' : ($a->status === \Platform\Recruiting\Models\RecDispoJobRequest::STATUS_LAEUFT ? 'läuft' : 'wartet auf den Start');
                            $statusKlasse = !$offen ? 'border-gray-200 bg-gray-50 text-gray-600' : 'border-green-200 bg-green-50 text-green-700';
                            $letzte = $a->waves->first();
                            $letzteText = '–';
                            if ($letzte !== null) {
                                $letzteZeit = $letzte->started_at ?? $letzte->scheduled_for ?? $letzte->created_at;
                                $letzteText = 'Welle ' . $letzte->nr . ($letzteZeit ? ' · ' . $letzteZeit->format('d.m. H:i') : '');
                            }
                            $anlassText = ($a->anlass ?? '') !== '' ? \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnlass::label((string) $a->anlass) : '—';
                            $z = $zahlen[$a->id] ?? ['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0];
                            $zusagenKlasse = $z['gesucht'] > 0 && $z['zugesagt'] >= $z['gesucht'] ? 'text-green-700 font-medium' : '';
                            $link = route('recruiting.dispo.jobs.show', ['requestId' => $a->id]);
                        @endphp
                        <tr wire:key="anfrage-{{ $a->id }}" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-2"><a href="{{ $link }}" class="font-medium text-blue-700 hover:underline">{{ \Platform\Recruiting\Support\Filialen::code($a->filial_nr) ?? ('#' . $a->filial_nr) }}</a></td>
                            <td class="px-4 py-2 tabular-nums">{{ $a->zeitraum_von->format('d.m.') }}–{{ $a->zeitraum_bis->format('d.m.Y') }}</td>
                            <td class="px-4 py-2">{{ $anlassText }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ $a->shifts_count }}</td>
                            <td class="px-4 py-2"><span class="rounded-full border px-2 py-0.5 text-xs {{ $statusKlasse }}">{{ $statusText }}</span></td>
                            <td class="px-4 py-2 tabular-nums {{ $zusagenKlasse }}">{{ $z['zugesagt'] }} / {{ $z['gesucht'] }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ $z['noch_zas'] > 0 ? $z['noch_zas'] : '–' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $letzteText }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
