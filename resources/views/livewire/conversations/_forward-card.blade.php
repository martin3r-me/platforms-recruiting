{{-- Partial: interne Karte einer Weiterleitung. Erwartet $card mit
     messages (list{body, media_type, received_at}), comment, by, forwarded_at.
     Der MA sieht diese Karte nie. --}}
<div class="rounded-xl border border-violet-200 bg-violet-50/60 p-3 text-sm">
    <div class="mb-1.5 text-[11px] font-semibold text-violet-700">
        Aus der Dispo weitergeleitet · {{ $card['forwarded_at'] }}
        @if (($card['by'] ?? '') !== '')
            · {{ $card['by'] }}
        @endif
    </div>
    @foreach ($card['messages'] as $fm)
        @php
            $fmAt = !empty($fm['received_at']) ? \Illuminate\Support\Carbon::parse($fm['received_at'])->format('d.m. H:i') : '';
        @endphp
        <div class="mb-1 whitespace-pre-line rounded-lg bg-white px-2.5 py-1.5 text-gray-800 shadow-sm">
            @if ($fmAt !== '')
                <div class="text-[10.5px] text-gray-400 tabular-nums">{{ $fmAt }}</div>
            @endif
            @if (!empty($fm['media_type']))
                📎 {{ \Platform\Recruiting\Services\Comms\Forward\ConversationForwarder::mediaLabel($fm['media_type']) }}
            @endif
            {{ $fm['body'] }}
        </div>
    @endforeach
    @if (!empty($card['comment']))
        <div class="mt-1.5 text-xs text-gray-700"><span class="font-semibold">Kommentar:</span> {{ $card['comment'] }}</div>
    @endif
</div>
