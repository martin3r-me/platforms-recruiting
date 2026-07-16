<div class="min-h-screen bg-[var(--ui-surface)] py-8 px-4">
    <div class="max-w-2xl mx-auto">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-semibold text-[var(--ui-secondary)]">{{ $duzen ? 'Deine Verträge' : 'Ihre Verträge' }}</h1>
            @if($state === 'ready' && $applicantName)
                <p class="text-sm text-[var(--ui-muted)] mt-1">für {{ $applicantName }}</p>
            @endif
        </div>

        @if($state === 'loading')
            <div class="text-center py-12">
                <p class="text-sm text-[var(--ui-muted)]">Lade Verträge...</p>
            </div>
        @elseif($state === 'invalid')
            <div class="p-6 bg-red-50 border border-red-200 rounded-lg text-center">
                @svg('heroicon-o-exclamation-triangle', 'w-10 h-10 text-red-600 mx-auto mb-3')
                <h2 class="text-lg font-medium text-red-900 mb-1">Link ungültig</h2>
                <p class="text-sm text-red-700">{{ $duzen ? 'Dieser Link ist nicht gültig oder wurde widerrufen. Bitte wende dich an den Absender.' : 'Dieser Link ist nicht gültig oder wurde widerrufen. Bitte wenden Sie sich an den Absender.' }}</p>
            </div>
        @elseif($state === 'expired')
            <div class="p-6 bg-amber-50 border border-amber-200 rounded-lg text-center">
                @svg('heroicon-o-clock', 'w-10 h-10 text-amber-600 mx-auto mb-3')
                <h2 class="text-lg font-medium text-amber-900 mb-1">Link abgelaufen</h2>
                <p class="text-sm text-amber-700">{{ $duzen ? 'Dieser Link ist abgelaufen. Bitte fordere einen neuen Link an.' : 'Dieser Link ist abgelaufen. Bitte fordern Sie einen neuen Link an.' }}</p>
            </div>
        @elseif($state === 'empty')
            <div class="p-6 bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg text-center">
                @svg('heroicon-o-document-text', 'w-10 h-10 text-[var(--ui-muted)] mx-auto mb-3')
                <h2 class="text-lg font-medium text-[var(--ui-secondary)] mb-1">Keine Verträge</h2>
                <p class="text-sm text-[var(--ui-muted)]">{{ $duzen ? 'Aktuell sind für dich keine Verträge zur Unterschrift hinterlegt.' : 'Aktuell sind für Sie keine Verträge zur Unterschrift hinterlegt.' }}</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach($contracts as $c)
                    <div class="p-4 bg-white border border-[var(--ui-border)] rounded-lg shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <h3 class="font-medium text-[var(--ui-secondary)]">
                                    {{ $c['display_name'] }}
                                </h3>
                                <div class="mt-2 text-xs">
                                    @if($c['status'] === 'completed' || $c['signed_at'])
                                        <span class="inline-flex items-center gap-1 text-green-700">
                                            @svg('heroicon-o-check-circle', 'w-4 h-4')
                                            Unterschrieben
                                            @if($c['signed_at'])
                                                am {{ \Carbon\Carbon::parse($c['signed_at'])->format('d.m.Y') }}
                                            @endif
                                        </span>
                                    @elseif($c['status'] === 'sent')
                                        <span class="inline-flex items-center gap-1 text-blue-700">
                                            @svg('heroicon-o-pencil', 'w-4 h-4')
                                            {{ $duzen ? 'Wartet auf deine Unterschrift' : 'Wartet auf Ihre Unterschrift' }}
                                        </span>
                                    @elseif($c['status'] === 'in_progress')
                                        <span class="inline-flex items-center gap-1 text-amber-700">
                                            @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                            Begonnen, aber noch nicht abgeschlossen
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-[var(--ui-muted)]">
                                            @svg('heroicon-o-clock', 'w-4 h-4')
                                            {{ $c['status'] }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex-shrink-0 flex items-center gap-2">
                                @if(!$c['signed_at'] && in_array($c['status'], ['sent', 'in_progress']))
                                    <a href="{{ $c['sign_url'] }}"
                                       class="inline-flex items-center gap-2 px-4 py-2 bg-[var(--ui-primary)] text-white text-sm font-medium rounded-md hover:bg-[var(--ui-primary-dark)] transition-colors">
                                        @svg('heroicon-o-pencil', 'w-4 h-4')
                                        Jetzt unterschreiben
                                    </a>
                                @endif
                                @if($c['pdf_url'])
                                    <a href="{{ $c['pdf_url'] }}"
                                       target="_blank"
                                       class="inline-flex items-center gap-2 px-4 py-2 border border-emerald-300 text-emerald-800 bg-emerald-50 text-sm font-medium rounded-md hover:bg-emerald-100 transition-colors">
                                        @svg('heroicon-o-document-arrow-down', 'w-4 h-4')
                                        PDF herunterladen
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="text-xs text-[var(--ui-muted)] text-center mt-6">
                {{ $duzen ? 'Bei Rückfragen wende dich bitte an deinen Ansprechpartner.' : 'Bei Rückfragen wenden Sie sich bitte an Ihren Ansprechpartner.' }}
            </p>
        @endif
    </div>
</div>
