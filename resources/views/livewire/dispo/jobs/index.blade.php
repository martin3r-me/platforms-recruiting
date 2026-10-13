{{-- Jobs anbieten → Übersicht (Spec 2026-10-12 §4). Du-Form; eine Zusage ist nie „gebucht“. --}}
<div class="p-6 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Jobs anbieten</h1>
            @include('recruiting::anleitungen._hilfe', ['route' => 'recruiting.dispo.jobs.index'])
        </div>
        <a href="{{ route('recruiting.dispo.jobs.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Neue Anfrage</a>
    </div>

    @if ($anfragen->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500">
            Noch keine Anfrage. Mit „Neue Anfrage“ schreibst du Mitarbeiter an, die an deinen offenen Schichten noch frei sind.
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
                            $link = route('recruiting.dispo.jobs.show', ['requestId' => $a->id]);
                        @endphp
                        <tr wire:key="anfrage-{{ $a->id }}" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-2"><a href="{{ $link }}" class="font-medium text-blue-700 hover:underline">{{ \Platform\Recruiting\Support\Filialen::code($a->filial_nr) ?? ('#' . $a->filial_nr) }}</a></td>
                            <td class="px-4 py-2 tabular-nums">{{ $a->zeitraum_von->format('d.m.') }}–{{ $a->zeitraum_bis->format('d.m.Y') }}</td>
                            <td class="px-4 py-2">{{ $anlassText }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ $a->shifts_count }}</td>
                            <td class="px-4 py-2"><span class="rounded-full border px-2 py-0.5 text-xs {{ $statusKlasse }}">{{ $statusText }}</span></td>
                            <td class="px-4 py-2 tabular-nums">{{ $zusagen[$a->id] ?? 0 }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $letzteText }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
