@if ($anzahl > 1)
    <div class="mt-3 flex items-center gap-2 text-sm">
        @for ($i = 1; $i <= $anzahl; $i++)
            <button type="button" wire:click="blaettern({{ $i }})"
                    class="rounded border px-2 py-0.5 {{ $seite === $i ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-gray-300 text-gray-600' }}">{{ $i }}</button>
        @endfor
    </div>
@endif
