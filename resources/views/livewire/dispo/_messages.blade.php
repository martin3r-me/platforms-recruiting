{{-- Partial: Nachrichten-Verlauf eines Threads. Erwartet $messages (list) und
     optional $portalUrl (Link zur Einsatz-Seite an Vorlagen-Karten). Genutzt
     von der Kommunikation und dem VA-Chat-Panel (Runde 4, #1). --}}
@php
    $portalUrl = $portalUrl ?? null;
    $lastDay = null;
@endphp
@forelse ($messages as $message)
    @if ($message['day'] !== $lastDay)
        @php $lastDay = $message['day']; @endphp
        <div class="my-1 self-center rounded-full border border-gray-200 bg-white px-3 py-0.5 text-[11px] font-semibold text-gray-400">{{ $message['day_label'] }}</div>
    @endif
    @if ($message['kind'] === 'template')
        <div class="grid max-w-[85%] grid-cols-[30px_1fr] items-center gap-x-2.5 gap-y-0.5 self-end rounded-xl border border-gray-200 bg-white px-3 py-2 lg:max-w-[68%]">
            <span class="self-start grid h-[30px] w-[30px] place-items-center rounded-lg bg-blue-50 text-blue-700">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v12H7l-3 3z"/><path d="M8 9h8M8 12h5"/></svg>
            </span>
            <span class="text-[13px] font-semibold text-gray-800">{{ $message['template_label'] }} gesendet</span>
            <span class="col-start-2 text-[11px] text-gray-400 tabular-nums">{{ $message['time'] }}@if ($message['status']) · {{ $message['status'] }}@endif</span>
            @if ($portalUrl)
                <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="col-start-2 text-[11px] font-semibold text-blue-700 hover:underline" title="Persönlicher Link des Mitarbeiters — nicht weitergeben.">Einsatz-Seite öffnen ↗</a>
            @endif
        </div>
    @else
        <div class="flex max-w-[85%] flex-col gap-0.5 lg:max-w-[68%] {{ $message['direction'] === 'outbound' ? 'self-end items-end' : 'self-start' }}">
            <div class="whitespace-pre-line rounded-2xl px-3 py-2 text-sm leading-relaxed {{ $message['direction'] === 'outbound' ? 'rounded-br-md bg-blue-600 text-white' : 'rounded-bl-md bg-white text-gray-900 shadow-sm' }}">
                @if (!empty($message['media_type']))
                    @forelse ($message['attachments'] as $att)
                        @php $attUrl = $att['url'] ?? null; $attThumb = $att['thumbnail'] ?? $attUrl; $attTitle = $att['title'] ?? 'Datei'; @endphp
                        @if (in_array($message['media_type'], ['image', 'sticker'], true) && $attUrl)
                            <a href="{{ $attUrl }}" target="_blank" rel="noopener" class="block py-0.5"><img src="{{ $attThumb }}" alt="{{ $attTitle }}" class="max-h-48 max-w-full rounded-xl object-cover" loading="lazy"></a>
                        @elseif ($message['media_type'] === 'video' && $attUrl)
                            <video controls preload="metadata" class="max-h-48 max-w-full rounded-xl py-0.5"><source src="{{ $attUrl }}"></video>
                        @elseif (in_array($message['media_type'], ['voice', 'audio'], true) && $attUrl)
                            <audio controls preload="metadata" class="h-8 w-full min-w-[160px] py-0.5"><source src="{{ $attUrl }}"></audio>
                        @elseif ($attUrl)
                            <a href="{{ $attUrl }}" target="_blank" rel="noopener" class="flex items-center gap-1.5 py-0.5 text-[13px] font-medium underline">📄 {{ $attTitle }}</a>
                        @else
                            <span class="flex items-center gap-1.5 py-0.5 text-[13px] opacity-70">📎 {{ ucfirst($message['media_type']) }} (nicht abrufbar)</span>
                        @endif
                    @empty
                        <span class="flex items-center gap-1.5 py-0.5 text-[13px] opacity-70">📎 {{ ucfirst($message['media_type']) }} (nicht abrufbar)</span>
                    @endforelse
                @endif
                {{ $message['body'] }}
            </div>
            <div class="px-1 text-[11px] text-gray-400 tabular-nums">{{ $message['time'] }}@if ($message['direction'] === 'outbound' && $message['status']) · {{ $message['status'] }}@endif</div>
        </div>
    @endif
@empty
    <div class="self-center text-sm text-gray-500">Keine Nachrichten.</div>
@endforelse
