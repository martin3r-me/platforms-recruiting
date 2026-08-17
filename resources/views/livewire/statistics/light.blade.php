{{--
    Ampel-Darstellung. Heller Zeilen-Tint plus Statuspunkt statt Volltoenung:
    eine durchgefaerbte Zeile wuerde die Trichter-Farben erschlagen und die
    Zahlen unlesbar machen.

    Erwartet:
      $light  array{status:string, pct:?int, reason:string, ...} aus TargetLight
      $label  string  kurzer Text vor dem Prozentwert (z. B. 'Pipeline')
--}}
@php
    $dot = match ($light['status']) {
        'green' => 'bg-emerald-500',
        'yellow' => 'bg-amber-500',
        'red' => 'bg-red-500',
        default => 'bg-gray-300',
    };
    $text = match ($light['status']) {
        'green' => 'text-emerald-700',
        'yellow' => 'text-amber-700',
        'red' => 'text-red-700',
        default => 'text-[color:var(--ui-muted)]',
    };
@endphp
<span class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs tabular-nums {{ $text }}"
      title="{{ $label }}: {{ $light['reason'] }}">
    <span class="h-2 w-2 shrink-0 rounded-full {{ $dot }}"></span>
    @if ($light['pct'] === null)
        <span class="cursor-help">–</span>
    @else
        {{ $light['pct'] }} %
    @endif
</span>
