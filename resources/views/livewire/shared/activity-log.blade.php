{{--
    AKTIVITAETEN-ZEITSTRAHL (Bewerberakte und MA-Akte).

    Nimmt die Eintraege als VARIABLE entgegen, nicht ueber $this->: ein
    geteiltes Partial, das Methoden seiner Wirtskomponente ruft, bricht erst
    beim Klick des Nutzers (siehe SharedPartialContractTest). Hier gibt es
    deshalb gar keinen Vertrag zu brechen — der Aufrufer reicht durch, was er
    hat.

    Erwartet:
      $logs      iterable  RecAutoPilotLog-Eintraege, neueste zuerst
      $leerText  ?string   Text, wenn nichts da ist (optional)
--}}
@php
    $leerText = $leerText ?? 'Keine Aktivitäten verfügbar';
@endphp
<div class="p-6 space-y-3 text-sm">
    @if (count($logs) === 0)
        <div class="text-[var(--ui-muted)]">{{ $leerText }}</div>
    @else
        <div class="relative">
            <div class="absolute left-3 top-0 bottom-0 w-px bg-[var(--ui-border)]/40"></div>
            <div class="space-y-4">
                @foreach ($logs as $log)
                    @php
                        $icon = match($log->type) {
                            'run_started' => 'heroicon-o-play',
                            'state_changed' => 'heroicon-o-arrow-path',
                            'email_sent' => 'heroicon-o-envelope',
                            'completed' => 'heroicon-o-check-circle',
                            'error' => 'heroicon-o-exclamation-triangle',
                            'einsatz_klaerung_gesetzt' => 'heroicon-o-check',
                            'einsatz_klaerung_aufgehoben' => 'heroicon-o-arrow-uturn-left',
                            default => 'heroicon-o-document-text',
                        };
                        $iconColor = match($log->type) {
                            'run_started' => 'text-blue-500',
                            'state_changed' => 'text-amber-500',
                            'email_sent' => 'text-indigo-500',
                            'completed' => 'text-green-500',
                            'error' => 'text-red-500',
                            'einsatz_klaerung_gesetzt' => 'text-teal-600',
                            'einsatz_klaerung_aufgehoben' => 'text-orange-500',
                            default => 'text-gray-400',
                        };
                        // Die Klaerungs-Saetze tragen Notiz UND Wiedervorlage und
                        // reissen bei 120 Zeichen mitten im Grund ab — genau der
                        // Teil, wegen dem der Eintrag existiert.
                        $laenge = str_starts_with($log->type, 'einsatz_klaerung_') ? 400 : 120;
                    @endphp
                    <div class="relative flex gap-3 pl-1">
                        <div class="flex-shrink-0 w-5 h-5 rounded-full bg-white flex items-center justify-center z-10">
                            @svg($icon, 'w-4 h-4 ' . $iconColor)
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[var(--ui-secondary)] leading-snug break-words">{{ \Illuminate\Support\Str::limit($log->summary, $laenge) }}</p>
                            <p class="text-xs text-[var(--ui-muted)] mt-0.5">{{ $log->created_at->timezone(auth()->user()->timezone ?? config('app.timezone', 'Europe/Berlin'))->diffForHumans() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
