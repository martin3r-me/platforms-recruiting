<div class="p-6 max-w-5xl space-y-8">
    <div>
        <h1 class="text-xl font-semibold">Anleitungen</h1>
        <p class="text-sm text-gray-500">Schritt für Schritt erklärt — immer auf dem aktuellen Stand. Jede Anleitung lässt sich als PDF speichern.</p>
    </div>

    @forelse ($gruppen as $bereich => $anleitungen)
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ $bereich }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($anleitungen as $a)
                    <a href="{{ route('recruiting.anleitungen.show', ['slug' => $a->slug]) }}" wire:navigate
                       class="block rounded-lg border border-gray-200 bg-white p-4 hover:border-blue-400 hover:shadow-sm">
                        <div class="font-semibold text-gray-900">{{ $a->titel }}</div>
                        <div class="mt-1 text-xs text-gray-500">Stand {{ $a->standDeutsch() }} · {{ count($a->folien) }} {{ count($a->folien) === 1 ? 'Folie' : 'Folien' }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <p class="text-sm text-gray-500">Noch keine Anleitungen.</p>
    @endforelse

    @if ($fehlerhaft !== [])
        <section class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <div class="font-semibold">Diese Anleitungen konnten nicht gelesen werden</div>
            <ul class="mt-2 list-disc pl-5">
                @foreach ($fehlerhaft as $a)
                    <li><code>{{ $a->slug }}</code>: {{ $a->fehler }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
