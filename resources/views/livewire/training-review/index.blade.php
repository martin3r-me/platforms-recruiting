{{-- Schulungsliste der Teamleiter-Ansicht (14.09.2026).
     Gleiches Seitengeruest wie die Nachbereitung (x-ui-page + Panel), damit
     sich die Ansicht nicht wie ein Fremdkoerper anfuehlt.

     Breadcrumbs OHNE Dashboard-Link: fuer eingeschraenkte Konten ist das
     Dashboard gesperrt, ein Link dorthin wuerde nur umleiten. --}}
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Schulungen bewerten" icon="heroicon-o-star" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Schulungen bewerten'],
        ]" />
    </x-slot>

    <x-ui-page-container width="full">
        <div class="px-4 sm:px-6 lg:px-8">
            <x-ui-panel
                title="Schulungen"
                subtitle="Die letzten vier Wochen und alle kommenden. Öffnen, um Anwesenheit und Bewertung einzutragen."
            >
                @if (session('success'))
                    <div class="mb-3 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">
                        {{ session('success') }}
                    </div>
                @endif

                @php
                    $anstehende = $this->upcomingInterviews;
                    $vergangene = $this->pastInterviews;
                @endphp
                <div class="overflow-x-auto" x-data="{ showPast: false }">
                    <table class="w-full table-auto border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 text-xs uppercase tracking-wide">
                                <th class="px-4 py-3">Schulung</th>
                                <th class="px-4 py-3">Datum</th>
                                <th class="px-4 py-3">Ort</th>
                                <th class="px-4 py-3">Teilnehmer</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--ui-border)]/60">
                            @forelse($anstehende as $interview)
                                @include('recruiting::livewire.training-review._row', ['interview' => $interview])
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-[var(--ui-muted)]">
                                        {{-- Zwei verschiedene Lagen, zwei Saetze: gar keine Schulungen,
                                             oder nur vergangene (die stehen dann eingeklappt darunter). --}}
                                        <div class="text-sm">
                                            {{ $vergangene->isEmpty() ? 'Keine Schulungen in diesem Zeitraum' : 'Keine anstehenden Schulungen' }}
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>

                        @if($vergangene->isNotEmpty())
                            <tbody class="border-t border-[var(--ui-border)]/60">
                                <tr>
                                    <td colspan="5" class="px-4 py-2 bg-[var(--ui-muted-5)]">
                                        <button type="button" @click="showPast = !showPast"
                                            class="flex w-full items-center gap-2 text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)] hover:text-[var(--ui-secondary)]">
                                            <span class="inline-flex transition-transform" :class="showPast ? 'rotate-90' : ''">
                                                @svg('heroicon-o-chevron-right', 'w-4 h-4')
                                            </span>
                                            <span>Vergangene Schulungen ({{ $vergangene->count() }})</span>
                                            <span class="ml-auto font-normal normal-case tracking-normal"
                                                  x-text="showPast ? 'ausblenden' : 'anzeigen'"></span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            {{-- style statt x-cloak: im Projekt ist keine [x-cloak]-CSS-Regel
                                 definiert (vgl. interview-schedule/index.blade.php) --}}
                            <tbody x-show="showPast" style="display: none;" class="divide-y divide-[var(--ui-border)]/60">
                                @foreach($vergangene as $interview)
                                    @include('recruiting::livewire.training-review._row', ['interview' => $interview])
                                @endforeach
                            </tbody>
                        @endif
                    </table>
                </div>
            </x-ui-panel>
        </div>
    </x-ui-page-container>
</x-ui-page>
