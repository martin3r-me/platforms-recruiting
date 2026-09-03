@php
    /** Treffer nur holen, wenn der Suchtext lang genug ist (siehe SearchJump::MIN_CHARS). */
    $hits = $this->hasQuery ? $this->results : null;

    /* Die zweite Query (Gesamtzahl) laeuft nur, wenn das Limit voll ist —
       sonst waere sie bei jedem Tastendruck teuer und ohne Aussage. */
    $more = 0;
    if ($hits !== null && $hits->count() === \Platform\Recruiting\Livewire\Employees\SearchJump::LIMIT) {
        $more = max(0, $this->totalCount - $hits->count());
    }
@endphp
<div class="relative"
     x-data="{ open: false }"
     @click.outside="open = false"
     @keydown.escape="open = false">

    <div class="relative">
        @svg('heroicon-o-magnifying-glass', 'w-3.5 h-3.5 text-[var(--ui-muted)] absolute left-2 top-1/2 -translate-y-1/2 pointer-events-none')
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            @focus="open = true"
            @input="open = true"
            placeholder="Mitarbeiter suchen …"
            class="w-52 pl-7 pr-7 py-1 text-xs border border-[var(--ui-border)] rounded-md bg-[var(--ui-surface)] text-[color:var(--ui-secondary)] placeholder:text-[color:var(--ui-muted)] focus:outline-none focus:ring-1 focus:ring-[var(--ui-primary)]"
        />
        @if($this->hasQuery)
            <button
                type="button"
                wire:click="clear"
                title="Suche leeren"
                class="absolute right-1.5 top-1/2 -translate-y-1/2 text-[var(--ui-muted)] hover:text-[color:var(--ui-secondary)]"
            >
                @svg('heroicon-o-x-mark', 'w-3.5 h-3.5')
            </button>
        @endif
    </div>

    @if($hits !== null)
        {{-- style statt x-cloak: im Projekt ist keine [x-cloak]-CSS-Regel definiert --}}
        <div x-show="open" style="display: none;"
             class="absolute right-0 mt-1 w-80 max-h-80 overflow-y-auto bg-[var(--ui-surface)] border border-[var(--ui-border)] rounded-lg shadow-lg z-50">
            @forelse($hits as $hit)
                @php
                    $hitName = trim(($hit->first_name ?? '') . ' ' . ($hit->last_name ?? ''))
                        ?: 'Mitarbeiter #' . $hit->id;
                    $hitMeta = collect([$hit->email, $hit->phone])->filter()->implode(' · ');
                @endphp
                <a href="{{ route('recruiting.employees.show', $hit->id) }}"
                   wire:navigate
                   class="block px-3 py-2 border-b border-[var(--ui-border)]/40 last:border-b-0 hover:bg-[var(--ui-muted-5)]">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium text-[color:var(--ui-secondary)] truncate">{{ $hitName }}</span>
                        @if(!$hit->is_active)
                            <span class="shrink-0 px-1.5 py-0.5 rounded-full text-[10px] bg-[var(--ui-muted-5)] text-[color:var(--ui-muted)] border border-[var(--ui-border)]">inaktiv</span>
                        @endif
                    </div>
                    @if($hitMeta !== '')
                        <div class="text-[11px] text-[color:var(--ui-muted)] truncate">{{ $hitMeta }}</div>
                    @endif
                </a>
            @empty
                <div class="px-3 py-3 text-xs text-[color:var(--ui-muted)]">Kein Mitarbeiter gefunden.</div>
            @endforelse

            @if($more > 0)
                <a href="{{ route('recruiting.employees.index') }}"
                   wire:navigate
                   class="block px-3 py-2 text-[11px] text-[var(--ui-primary)] hover:underline bg-[var(--ui-muted-5)]">
                    + {{ $more }} weitere — in der Mitarbeiter-Liste verfeinern
                </a>
            @endif
        </div>
    @endif
</div>
