<div class="p-6">
    @include('recruiting::anleitungen._stil')

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('recruiting.anleitungen.index') }}" wire:navigate class="text-sm text-blue-600 hover:underline">← Alle Anleitungen</a>
            <h1 class="mt-1 text-xl font-semibold">{{ $anleitung->titel }}</h1>
            @if ($anleitung->fehler === null)
                <p class="text-sm text-gray-500">{{ $anleitung->bereich }} · Stand {{ $anleitung->standDeutsch() }}</p>
            @endif
        </div>
        @if ($anleitung->fehler === null)
            <a href="{{ route('recruiting.anleitungen.druck', ['slug' => $anleitung->slug]) }}" target="_blank" rel="noopener"
               class="rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">Als PDF speichern</a>
        @endif
    </div>

    @if ($anleitung->fehler !== null)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">Diese Anleitung konnte nicht gelesen werden: {{ $anleitung->fehler }}</div>
    @else
        <div class="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
            <nav class="lg:sticky lg:top-4 lg:self-start" x-data="{ aktiv: '{{ $anleitung->folien[0]->anker() }}' }"
                 x-init="const o = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) aktiv = e.target.id }), { rootMargin: '-20% 0px -70% 0px' });
                         document.querySelectorAll('.rg-folie').forEach(el => o.observe(el))">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Inhalt</div>
                <ol class="space-y-1 text-sm">
                    @foreach ($anleitung->folien as $folie)
                        <li>
                            <a href="#{{ $folie->anker() }}" class="block rounded px-2 py-1 hover:bg-gray-100"
                               :class="aktiv === '{{ $folie->anker() }}' ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-700'">
                                {{ $folie->nr }}. {{ $folie->titel }}
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>
            <div class="min-w-0 space-y-6">
                @foreach ($anleitung->folien as $folie)
                    @include('recruiting::anleitungen._folie', ['anleitung' => $anleitung, 'folie' => $folie])
                @endforeach
            </div>
        </div>
    @endif
</div>
