<?php

use Illuminate\Support\Facades\Route;
use Platform\Core\Models\CorePublicFormLink;

// Backwards compatibility: redirect old /recruiting/a/{token} URLs to new /form/{token}
Route::get('/a/{publicToken}', function (string $publicToken) {
    // Try to find the link by the old public_token (migrated to core_public_form_links)
    $link = CorePublicFormLink::where('token', $publicToken)->first();

    if ($link) {
        return redirect($link->getUrl(), 301);
    }

    // Fallback: try to find via rec_applicants.public_token for tokens not yet migrated
    if (class_exists(\Platform\Recruiting\Models\RecApplicant::class)) {
        $applicant = \Platform\Recruiting\Models\RecApplicant::where('public_token', $publicToken)->first();
        if ($applicant) {
            $link = $applicant->getOrCreatePublicFormLink();
            return redirect($link->getUrl(), 301);
        }
    }

    abort(404);
})->name('recruiting.public.applicant-form');

// Interview Booking (public, token-based)
Route::get('/interviews/{publicToken}', \Platform\Recruiting\Livewire\Public\InterviewBooking::class)
    ->name('recruiting.public.interview-booking');

// Contract Signing (public, token-based)
Route::get('/contract/{token}', \Platform\Recruiting\Livewire\Public\ContractSigning::class)
    ->name('recruiting.public.contract-signing');

// Applicant Portal — lists all active contracts of the applicant
Route::get('/portal/{token}', \Platform\Recruiting\Livewire\Public\ApplicantPortal::class)
    ->name('recruiting.public.applicant-portal');

// Contract PDF Download (public, token-based)
Route::get('/applicant/{token}/contract/{contractId}/pdf', [\Platform\Recruiting\Http\Controllers\ContractPdfController::class, '__invoke'])
    ->name('recruiting.public.contract-pdf');
