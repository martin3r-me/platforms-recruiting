{{-- Bestätigungs-Chips einer Einbuchung (Tabelle Desktop + Karten mobil nutzen dieselbe Logik). Erwartet $assignment. --}}
@php
    $msgStatus = $assignment->reminderMessage?->status;
    $escalation1Status = $assignment->escalation1Message?->status;
    $escalation2Status = $assignment->escalation2Message?->status;
@endphp
@if ($assignment->deletion_marked_at)
    <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">zur Löschung gemeldet</span>
@elseif ($assignment->confirmed_at)
    <span class="rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800" title="{{ $assignment->confirmed_at->format('d.m.Y H:i') }}">✓ bestätigt</span>
@elseif ($assignment->reminder_sent_at)
    <span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700" title="Gesendet {{ $assignment->reminder_sent_at->format('d.m.Y H:i') }}">angeschrieben</span>
    @if ($msgStatus === 'failed')
        <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">nicht zugestellt</span>
    @elseif (in_array($msgStatus, ['delivered', 'read'], true))
        <span class="ml-1 rounded bg-green-50 px-1.5 py-0.5 text-xs text-green-700">{{ $msgStatus === 'read' ? 'gelesen' : 'zugestellt' }}</span>
    @endif
@elseif ($assignment->reconfirm_required_at)
    {{-- Chip kommt aus dem eigenstaendigen Block unten — hier bewusst nichts,
         damit "—" nicht zusammen mit "⟳ Zeit geändert" erscheint. --}}
@else
    <span class="text-xs text-gray-400">—</span>
@endif
@if ($assignment->reconfirm_required_at && !$assignment->confirmed_at)
    @php
        $prev = $assignment->reconfirm_previous ?? [];
        $prevTitle = 'Zeit geändert am ' . $assignment->reconfirm_required_at->format('d.m.Y H:i') . (!empty($prev['datum']) ? ' — vorher ' . \Illuminate\Support\Carbon::parse($prev['datum'])->format('d.m.Y') . ' ' . ($prev['von'] ?? '') . (!empty($prev['bis']) ? '–' . $prev['bis'] : '') : '');
    @endphp
    <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700" title="{{ $prevTitle }}">⟳ Zeit geändert</span>
@endif
@if ($escalation1Status === 'failed')
    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">14-Uhr nicht zugestellt</span>
@endif
@if ($escalation2Status === 'failed')
    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">15-Uhr nicht zugestellt</span>
@endif
