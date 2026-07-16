{{-- Bestätigungs-Box am Ende des P3-Forms (nach erfolgreichem Save).
     Wird via RecApplicant::renderPublicFormCompletionExtras($state) eingebunden
     und vom PublicExtraFieldForm-Renderer am Ende ausgegeben. --}}
<div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-center">
    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100 mb-3">
        @svg('heroicon-o-check-circle', 'w-7 h-7 text-emerald-600')
    </div>
    <h3 class="text-lg font-semibold text-emerald-900 mb-2">{{ $bestaetigtSatz }}</h3>

    @if($interview)
        <p class="text-sm text-emerald-800 mb-1">
            <strong>{{ $interview->starts_at?->format('d.m.Y') }}</strong>
            @if($interview->starts_at)
                um <strong>{{ $interview->starts_at->format('H:i') }} Uhr</strong>
            @endif
        </p>
        @if($interview->location)
            <p class="text-sm text-emerald-800 mb-4">{{ $interview->location }}</p>
        @endif
    @endif

    <p class="text-sm text-emerald-800 mb-4">
        {{ $duzen ? 'Weitere Infos findest du hier:' : 'Weitere Infos finden Sie hier:' }}
    </p>
    <a href="https://rheingedeck.de/schulung"
       target="_blank"
       rel="noopener"
       class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-md hover:bg-emerald-700 transition-colors">
        @svg('heroicon-o-arrow-top-right-on-square', 'w-4 h-4')
        rheingedeck.de/schulung
    </a>
</div>
