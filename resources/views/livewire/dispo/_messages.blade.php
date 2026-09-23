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
        {{-- Kunde 23.09.: der echte Text der Vorlage, nicht der technische Name
             ("t_wo_bist"). Der Vorlagen-Name steht als kleine Zeile darunter. --}}
        @php
            $tplButtons = $message['template_buttons'] ?? [];
            $tplBody = trim((string) ($message['body'] ?? ''));
        @endphp
        <div class="flex max-w-[85%] flex-col items-end gap-0.5 self-end lg:max-w-[68%]">
            <div class="rounded-2xl rounded-br-md bg-blue-600 px-3 py-2 text-sm leading-relaxed text-white">
                @if ($tplBody !== '')
                    <div class="whitespace-pre-line">{{ $tplBody }}</div>
                @else
                    <div class="italic opacity-80">{{ $message['template_label'] }} gesendet (Text der Vorlage nicht abrufbar)</div>
                @endif
                @foreach ($tplButtons as $tplButton)
                    <div class="mt-1.5 border-t border-white/30 pt-1 text-center text-[13px] font-semibold">{{ $tplButton }}</div>
                @endforeach
            </div>
            <div class="px-1 text-[11px] text-gray-400 tabular-nums">
                Vorlage: {{ $message['template_label'] }} · {{ $message['time'] }}@if ($message['status']) · {{ $message['status'] }}@endif
            </div>
            @if ($portalUrl)
                <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="px-1 text-[11px] font-semibold text-blue-700 hover:underline" title="Persönlicher Link des Mitarbeiters — nicht weitergeben.">Einsatz-Seite öffnen ↗</a>
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
