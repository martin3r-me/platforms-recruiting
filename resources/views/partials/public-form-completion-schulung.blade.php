{{--
    Recruiting-spezifisches Snippet fuer den Core PublicExtraFieldForm
    completion-extras hook. Wird unter dem Standard-Card im saved/completed-
    State eingebettet. Zeigt confirmed Schulungs-Termin + Info-Button.

    Variablen:
      $booking     RecInterviewBooking (status=confirmed)
      $interview   RecInterview (eager-loaded ueber $booking->interview)
      $schulungUrl string — location-aware Schulungs-Info-URL
--}}

<div class="text-left">
    <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-5 mb-4">
        <p class="text-xs uppercase tracking-wider text-emerald-700 font-semibold mb-2">Deine Schulung ist bestätigt</p>

        @if($interview->starts_at)
            <p class="text-lg font-semibold text-gray-900">
                {{ \Carbon\Carbon::parse($interview->starts_at)->locale('de')->translatedFormat('l, d. F Y') }}
            </p>
            <p class="text-base text-gray-700">
                {{ \Carbon\Carbon::parse($interview->starts_at)->format('H:i') }} Uhr
            </p>
        @endif

        @if(!empty($interview->location))
            <p class="text-sm text-gray-500 mt-1">{{ $interview->location }}</p>
        @endif
    </div>

    <a href="{{ $schulungUrl }}"
       target="_blank"
       rel="noopener"
       class="inline-flex items-center justify-center gap-2 w-full px-7 py-3 bg-indigo-500 hover:bg-indigo-600 text-white text-sm font-semibold rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-500/25">
        Alle Infos zur Schulung
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
        </svg>
    </a>
</div>
