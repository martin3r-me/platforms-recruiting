{{-- Bestätigungs-Chips einer Einbuchung (Tabelle Desktop + Karten mobil nutzen dieselbe Logik). Erwartet $assignment. --}}
@php
    $msgStatus = $assignment->reminderMessage?->status;
    $escalation1Status = $assignment->escalation1Message?->status;
    $escalation2Status = $assignment->escalation2Message?->status;
    // Stufen-Fehler nur zeigen, solange keine NEUERE Ansprache existiert —
    // nach Nummernkorrektur + Neuversand ist die Zeile sofort sauber.
    $lastSend = $assignment->reminder_sent_at;
    $esc1FailedActive = $escalation1Status === 'failed' && ($lastSend === null || $assignment->escalation_1_at === null || $assignment->escalation_1_at >= $lastSend);
    $esc2FailedActive = $escalation2Status === 'failed' && ($lastSend === null || $assignment->escalation_2_at === null || $assignment->escalation_2_at >= $lastSend);
@endphp
@if ($assignment->deletion_marked_at)
    <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">zur Löschung gemeldet</span>
@elseif ($assignment->declined_at)
    <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700" title="Erfasst {{ $assignment->declined_at->format('d.m.Y H:i') }}{{ trim((string) $assignment->declined_note) !== '' ? ' — ' . $assignment->declined_note : '' }}">✕ {{ $assignment->declined_reason === 'krank' ? 'krank gemeldet' : 'abgesagt' }}</span>
@elseif ($assignment->confirmed_at)
    <span class="rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800" title="{{ $assignment->confirmed_at->format('d.m.Y H:i') }}{{ $assignment->manually_confirmed_by_user_id ? ' — manuell durch die Dispo bestätigt' : '' }}">✓ bestätigt{{ $assignment->manually_confirmed_by_user_id ? ' (manuell)' : '' }}</span>
@elseif ($assignment->reminder_sent_at)
    @if ($assignment->wasSentToOutdatedPhone())
        <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-800" title="Angeschrieben am {{ $assignment->reminder_sent_at->format('d.m.Y H:i') }} an {{ $assignment->reminder_sent_to }} — die aktuelle Nummer der Akte weicht ab. Bitte neu senden (Haken „Nur nicht zugestellte“).">an alte Nummer angeschrieben</span>
    @endif
    <span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700" title="Gesendet {{ $assignment->reminder_sent_at->format('d.m.Y H:i') }}{{ $assignment->escalation_due_1_at ? ' — eigener Eskalationsplan: ' . $assignment->escalation_due_1_at->format('d.m.Y') . ' · ' . $assignment->escalation_due_1_at->format('H:i') . ' / ' . $assignment->escalation_due_2_at?->format('H:i') . ' / ' . $assignment->escalation_due_3_at?->format('H:i') : '' }}">angeschrieben</span>
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
@if ($esc1FailedActive)
    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">Erinnerung{{ $assignment->escalation_1_at ? ' ' . $assignment->escalation_1_at->format('H:i') : '' }} nicht zugestellt</span>
@endif
@if ($esc2FailedActive)
    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">Letzte Erinnerung{{ $assignment->escalation_2_at ? ' ' . $assignment->escalation_2_at->format('H:i') : '' }} nicht zugestellt</span>
@endif
