<div class="applicant-wrap min-h-screen relative overflow-hidden">

    {{-- Background --}}
    @php
        $bgFiles = glob(public_path('images/bg-images/*.{jpeg,jpg,png,webp}'), GLOB_BRACE);
        $bgImage = !empty($bgFiles) ? basename($bgFiles[array_rand($bgFiles)]) : null;
    @endphp
    <div class="fixed inset-0 -z-10" aria-hidden="true">
        <div class="applicant-bg"></div>
        @if($bgImage)
            <img src="{{ asset('images/bg-images/' . $bgImage) }}"
                 class="absolute inset-0 w-full h-full object-cover"
                 alt="" loading="eager">
        @endif
        <div class="absolute inset-0 bg-gradient-to-br from-black/50 via-black/30 to-black/50"></div>
        <div class="absolute inset-0 backdrop-blur-[6px]"></div>
    </div>

    {{-- Loading --}}
    @if($state === 'loading')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-16 h-16 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="animate-spin w-8 h-8 text-blue-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                </div>
                <p class="text-gray-500 text-lg">Wird geladen...</p>
            </div>
        </div>

    {{-- Not Found --}}
    @elseif($state === 'notFound')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-red-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Link ungültig</h1>
                <p class="text-gray-500 text-lg">Dieser Link ist ungültig oder existiert nicht mehr. Bitte kontaktieren Sie die Personalabteilung.</p>
            </div>
        </div>

    {{-- Not Active --}}
    @elseif($state === 'notActive')
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-amber-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Bewerbung nicht aktiv</h1>
                <p class="text-gray-500 text-lg">Ihre Bewerbung ist derzeit nicht aktiv. Bitte kontaktieren Sie die Personalabteilung.</p>
            </div>
        </div>

    {{-- Selection --}}
    @elseif($state === 'selection')
        <header class="sticky top-0 z-50">
            <div class="applicant-header-glass">
                <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <h1 class="text-base font-semibold text-white truncate">Termin auswählen</h1>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-3xl mx-auto px-6 py-8">
            @if(count($this->availableInterviews) > 0)
                <div class="space-y-4">
                    @foreach($this->availableInterviews as $interview)
                        <div class="applicant-card p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-lg font-bold text-gray-900 mb-3">{{ $interview->title }}</h3>

                                    <div class="space-y-2">
                                        {{-- Date & Time --}}
                                        <div class="flex items-center gap-2 text-sm text-gray-600">
                                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            <span>{{ $interview->starts_at->translatedFormat('l, d. F Y') }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 text-sm text-gray-600">
                                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <span>
                                                {{ $interview->starts_at->format('H:i') }} Uhr
                                                @if($interview->ends_at)
                                                    – {{ $interview->ends_at->format('H:i') }} Uhr
                                                @endif
                                            </span>
                                        </div>

                                        {{-- Location --}}
                                        @if($interview->location)
                                            <div class="flex items-center gap-2 text-sm text-gray-600">
                                                <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                </svg>
                                                <span>{{ $interview->location }}</span>
                                            </div>
                                        @endif

                                        {{-- Available spots --}}
                                        @if($interview->max_participants)
                                            @php $freeSpots = $interview->max_participants - $interview->bookings_count; @endphp
                                            <div class="flex items-center gap-2 text-sm {{ $freeSpots <= 2 ? 'text-amber-600' : 'text-gray-600' }}">
                                                <svg class="w-4 h-4 {{ $freeSpots <= 2 ? 'text-amber-400' : 'text-gray-400' }} flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                </svg>
                                                <span>
                                                    {{ $freeSpots }} {{ $freeSpots === 1 ? 'Platz' : 'Plätze' }} frei
                                                </span>
                                            </div>
                                        @endif
                                    </div>

                                    @if($interview->description)
                                        <p class="mt-3 text-sm text-gray-500">{{ $interview->description }}</p>
                                    @endif
                                </div>

                                <div class="flex-shrink-0 pt-1">
                                    <button
                                        wire:click="bookInterview({{ $interview->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="bookInterview({{ $interview->id }})"
                                        class="applicant-btn-primary whitespace-nowrap"
                                    >
                                        <span wire:loading.remove wire:target="bookInterview({{ $interview->id }})">Buchen</span>
                                        <span wire:loading wire:target="bookInterview({{ $interview->id }})" class="inline-flex items-center gap-2">
                                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="applicant-card w-full max-w-md mx-auto p-10 text-center">
                    <div class="w-20 h-20 rounded-full bg-gray-50 flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <h2 class="text-xl font-bold text-gray-900 mb-3">Keine freien Termine</h2>
                    <p class="text-gray-500 text-lg">Aktuell sind keine freien Termine verfügbar. Bitte versuchen Sie es später erneut.</p>
                </div>
            @endif
        </main>

        <footer class="max-w-3xl mx-auto px-6 pb-8 text-center">
            <p class="text-[11px] text-white/20 tracking-wider uppercase">Powered by Recruiting</p>
        </footer>

    {{-- Booked --}}
    @elseif($state === 'booked')
        @php $booking = $this->existingBooking; @endphp
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="applicant-card w-full max-w-md p-10 text-center">
                <div class="w-20 h-20 rounded-full bg-emerald-50 flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-3">Termin gebucht!</h1>
                <p class="text-gray-500 text-lg mb-6">Ihr Interview-Termin wurde erfolgreich gebucht.</p>

                @if($booking && $booking->interview)
                    <div class="bg-gray-50 rounded-2xl p-6 text-left mb-6">
                        <h3 class="font-bold text-gray-900 mb-3">{{ $booking->interview->title }}</h3>
                        <div class="space-y-2">
                            <div class="flex items-center gap-2 text-sm text-gray-600">
                                <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <span>{{ $booking->interview->starts_at->translatedFormat('l, d. F Y') }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-sm text-gray-600">
                                <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span>
                                    {{ $booking->interview->starts_at->format('H:i') }} Uhr
                                    @if($booking->interview->ends_at)
                                        – {{ $booking->interview->ends_at->format('H:i') }} Uhr
                                    @endif
                                </span>
                            </div>
                            @if($booking->interview->location)
                                <div class="flex items-center gap-2 text-sm text-gray-600">
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    <span>{{ $booking->interview->location }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                <button
                    wire:click="cancelAndRebook"
                    wire:loading.attr="disabled"
                    class="text-sm font-medium text-gray-500 hover:text-gray-700 underline underline-offset-2 transition-colors"
                >
                    <span wire:loading.remove wire:target="cancelAndRebook">Umbuchen</span>
                    <span wire:loading wire:target="cancelAndRebook" class="inline-flex items-center gap-2">
                        <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        Wird umgebucht...
                    </span>
                </button>
            </div>
        </div>
    @endif
</div>

<style>
    .applicant-wrap {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    }
    .applicant-bg {
        position: fixed;
        inset: 0;
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        z-index: -10;
    }
    .applicant-card {
        background: white;
        border-radius: 24px;
        border: 1px solid rgba(0, 0, 0, 0.06);
        box-shadow:
            0 4px 6px -1px rgba(0, 0, 0, 0.05),
            0 25px 50px -12px rgba(0, 0, 0, 0.15);
    }
    .applicant-header-glass {
        background: rgba(15, 10, 26, 0.6);
        backdrop-filter: blur(30px);
        -webkit-backdrop-filter: blur(30px);
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
    .applicant-btn-primary {
        padding: 12px 28px;
        background: #6366f1;
        color: white;
        font-size: 14px;
        font-weight: 600;
        border-radius: 14px;
        transition: all 0.2s ease;
    }
    .applicant-btn-primary:hover {
        background: #4f46e5;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);
    }
    .applicant-btn-primary:disabled {
        opacity: 0.5;
    }
</style>
