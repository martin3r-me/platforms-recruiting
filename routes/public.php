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

// Mitarbeiter-Portal — Login-geschuetzter Bereich nach Phase-4-Konvertierung.
// Verifizierung mit Geburtsdatum + letzte 4 Ziffern Ausweisnummer.
Route::get('/mitarbeiter/{token}', \Platform\Recruiting\Livewire\Public\EmployeePortal::class)
    ->name('recruiting.public.employee-portal');

// Mitarbeiter-Portal, neue Fassung (Canvas 67). Laeuft NEBEN dem alten:
// wer hier hinkommt, entscheidet rec_employees.portal_v2_since — ohne Stempel
// antwortet die Komponente mit 404. Umstellen mit recruiting:portal-umstellen.
//
// TOKEN AM URL-ENDE, NICHT DAZWISCHEN (Fixrunde 1, Aufgabe 4): Meta-
// URL-Buttons erlauben die Variable NUR als Suffix — dieselbe Regel wie bei
// /einsaetze/{token} unten. Die urspruengliche Form /mitarbeiter/{token}/neu
// war fuer einen WhatsApp-Knopf unbrauchbar, weil "/neu" hinter dem Token
// stand. KEINE Kollision mit /mitarbeiter/{token} oben: die beiden Routen
// haben eine unterschiedliche Anzahl an Pfadsegmenten (eins vs. zwei), Laravel
// matcht {token} nur gegen genau EIN Segment ohne Slash — ein Aufruf mit zwei
// Segmenten kann die einsegmentige Route also strukturell nie treffen, ganz
// unabhaengig von der Registrierungsreihenfolge und unabhaengig davon, ob ein
// echter Token jemals "neu" heissen koennte (Tokens sind UUIDs, koennen es
// nicht). Festgenagelt in PortalTokenRouteTest.
Route::get('/mitarbeiter/neu/{token}', \Platform\Recruiting\Livewire\Public\PortalShell::class)
    ->name('recruiting.public.portal-shell');

// Dispo-Einsatz-Seite (token-only, NICHT im MA-Portal verlinkt — Spec 2026-08-14).
// Token am URL-Ende: Meta-URL-Buttons erlauben die Variable nur als Suffix.
Route::get('/einsaetze/{token}', \Platform\Recruiting\Livewire\Public\EmployeeAssignments::class)
    ->name('recruiting.public.employee-assignments');

// Anhang-Download von der Einsatz-Seite (token-only wie die Seite selbst).
Route::get('/einsaetze/{token}/anhang/{uuid}', \Platform\Recruiting\Http\Controllers\DispoAttachmentController::class)
    ->name('recruiting.public.employee-assignments.attachment')
    ->where('uuid', '[0-9a-fA-F-]{36}');

// Design-Vorschau des Mitarbeiterportals — statische HTML-Datei hinter einem
// Zahlencode. Nur zum Zeigen, enthaelt ausschliesslich Beispieldaten.
Route::match(['GET', 'POST'], '/entwurf/portal', \Platform\Recruiting\Http\Controllers\PortalMockupController::class)
    ->name('recruiting.public.portal-mockup');

// Contract PDF Download (public, token-based)
Route::get('/applicant/{token}/contract/{contractId}/pdf', [\Platform\Recruiting\Http\Controllers\ContractPdfController::class, '__invoke'])
    ->name('recruiting.public.contract-pdf');

// Schulungszertifikat-PDF (public, ueber die Zertifikat-uuid).
// Bewusst NICHT ueber den Applicant-Token wie die Vertrags-Route darueber: der
// Token oeffnet auch Bewerbungsformular und Vertrags-PDFs, unbegrenzt und ohne
// Rotation. Dieser Link geht per WhatsApp an abgelehnte Bewerber und soll genau
// ein Dokument oeffnen. Wer hier auf {token} umbaut, macht aus einem
// Trostpreis-Link einen Generalschluessel.
Route::get('/zertifikat/{uuid}', [\Platform\Recruiting\Http\Controllers\TrainingCertificatePdfController::class, '__invoke'])
    ->name('recruiting.public.training-certificate');
