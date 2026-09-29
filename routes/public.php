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

// Mitarbeiterkonto, Registrierung (Canvas 68, Spec 3). Die Einladung von HR
// fuehrt hierher: Token aus der Adresse, dazu Geburtsdatum und Passwort.
//
// TOKEN AM URL-ENDE, wie bei /mitarbeiter/neu/{token} und /einsaetze/{token}:
// Meta-URL-Buttons erlauben die Variable nur als Suffix, und diese Adresse geht
// per WhatsApp-Knopf raus.
//
// DROSSEL 1 VON 2 (Ruling GD-4, tragend): der Token ist acht Zeichen aus 31 und
// sieben Tage gueltig. Diese Bremse trifft das Durchprobieren von TOKEN -
// hoechstens 20 Aufrufe je Minute und IP. Zwanzig statt zehn, weil mehrere
// Mitarbeiter hinter derselben Firmen-IP sitzen koennen und ein Fehlalarm hier
// die Kontoanlage blockierte. Die zweite Bremse (falsches Geburtsdatum bei
// bekanntem Token) sitzt in der Komponente; keine der beiden ersetzt die andere.
Route::get('/konto/anlegen/{token}', \Platform\Recruiting\Livewire\Public\KontoAnlegen::class)
    ->middleware('throttle:20,1')
    ->name('recruiting.public.konto-anlegen');

// Dieselbe Komponente OHNE Token - die zweite Haelfte von Ruling GD-4.
// Canvas 68, Eintrag 1740: die Einladung geht "als Link UND als kurzen
// lesbaren Code ... am Rechner kann man den Code auch eintippen." Der Link
// steht darueber, hier ist das Eingabefeld.
//
// KEINE ZWEITE SEITE und kein zweiter Pruefpfad: die Komponente zeigt ohne
// Token nur ein Feld und leitet die Eingabe auf die Route darueber weiter.
// Geprueft wird also weiterhin genau einmal.
//
// DIESELBE DROSSEL wie oben, und zwar zwingend: ohne sie waere diese Seite
// die bequemere Tuer zum Durchprobieren als der Link. Acht Zeichen aus 31
// sind rund 850 Milliarden Moeglichkeiten - aber nur mit Bremse.
//
// KEINE KOLLISION mit der Route darueber: die beiden haben eine
// unterschiedliche Anzahl an Pfadsegmenten (zwei vs. drei), und {token}
// matcht nur gegen genau ein Segment ohne Slash.
Route::get('/konto/anlegen', \Platform\Recruiting\Livewire\Public\KontoAnlegen::class)
    ->middleware('throttle:20,1')
    ->name('recruiting.public.konto-anlegen-code');

// Mitarbeiterkonto, Anmeldung (Canvas 68, Spec 2.1). Handynummer und
// Passwort - oeffentlich, ohne Token, ohne Team-Kontext (Ruling GD-2).
//
// KEINE Route-Drossel: die Anmeldeversuche laufen ueber /livewire/update und
// fassen diese GET-Adresse gar nicht wieder an. Die wirksame Bremse sitzt in
// PortalAuth (fuenf Versuche je Nummer, fuenfzehn Minuten) - wer hier eine
// Route-Drossel ergaenzt, gewinnt nichts und sperrt Firmen-IPs aus.
Route::get('/konto', \Platform\Recruiting\Livewire\Public\KontoAnmelden::class)
    ->name('recruiting.public.konto');

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
