{{-- Reiter der Statistik: „Recruiting“ (Bewerbungen/Termine) und „Mitarbeiter“ (Markus 19.11.2026).
     Der aktive Reiter kommt als Variable ($statAktivRoute), nicht aus request()->routeIs():
     bei Livewire-Updates ist die Route livewire.update, der Reiter verloere sonst seine Markierung. --}}
@php
    $statTabs = [
        'recruiting.statistics.index' => 'Recruiting',
        'recruiting.statistics.employees' => 'Mitarbeiter',
    ];
@endphp
<nav class="flex gap-1 border-b border-[var(--ui-border)]">
    @foreach ($statTabs as $statRoute => $statLabel)
        @php
            $statAktiv = ($statAktivRoute ?? '') === $statRoute;
            // Ohne Router (Render-Tests der Statistikseite) kein Absturz: der Reiter ist dort Beiwerk.
            try {
                $statHref = route($statRoute);
            } catch (\Throwable) {
                $statHref = '#';
            }
            $statKlasse = $statAktiv
                ? 'border-[var(--ui-primary)] text-[var(--ui-secondary)] font-semibold'
                : 'border-transparent text-[color:var(--ui-muted)] hover:text-[var(--ui-secondary)]';
        @endphp
        <a href="{{ $statHref }}" wire:navigate class="px-4 py-2 -mb-px border-b-2 text-sm {{ $statKlasse }}">{{ $statLabel }}</a>
    @endforeach
</nav>
