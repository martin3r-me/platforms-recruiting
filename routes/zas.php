<?php

use Illuminate\Support\Facades\Route;
use Platform\Recruiting\Http\Controllers\ZasDispoInboundController;
use Platform\Recruiting\Http\Controllers\ZasEmployeeFileController;
use Platform\Recruiting\Http\Controllers\ZasEmployeeInitialExportController;
use Platform\Recruiting\Http\Controllers\ZasEmployeeUpdateExportController;
use Platform\Recruiting\Http\Controllers\ZasExportController;
use Platform\Recruiting\Http\Controllers\ZasFileController;
use Platform\Recruiting\Http\Controllers\ZasInboundController;
use Platform\Recruiting\Http\Middleware\ZasBearerAuth;

/*
|--------------------------------------------------------------------------
| ZAS Bewerber- + Mitarbeiter-Export (externe IBEI-Schnittstelle)
|--------------------------------------------------------------------------
|
| Eigene Route-Gruppe ohne `web`-Middleware (kein Session, kein CSRF —
| ZAS spricht maschinell auf den Endpoint).
|
| Auth-Strategie:
|   - CSV-Endpoints                 → Bearer-Token via ZasBearerAuth
|   - File-Streams (/files, /employee-files) → signierte URL (Signatur ist die Auth)
*/

// CSV-Export-Endpoints (Bewerber + Mitarbeiter)
Route::middleware([ZasBearerAuth::class])->group(function () {
    // Bewerber-Export (alter Pfad, unangetastet)
    Route::get('/applicants/export.csv', ZasExportController::class)
        ->name('recruiting.zas.applicants.export');

    // Mitarbeiter-Initial-Export — frisch angelegte MAs, einmalig
    Route::get('/employees/initial.csv', ZasEmployeeInitialExportController::class)
        ->name('recruiting.zas.employees.initial');

    // Mitarbeiter-Update-Export — Delta-Sync bei Aenderungen
    Route::get('/employees/updates.csv', ZasEmployeeUpdateExportController::class)
        ->name('recruiting.zas.employees.updates');

    // Eingang: ZAS spielt uns eine CSV zurueck (Push-Richtung).
    // Phase 1: nur annehmen + roh speichern. ?dry_run=true markiert Tests.
    Route::post('/inbound', ZasInboundController::class)
        ->name('recruiting.zas.inbound');

    // Dispo-Eingang: Veranstaltungen + eingebuchtes Personal aus ZAS.
    // Phase 1: nur annehmen + roh speichern (Sichtung: Disposition → ZAS-Eingang).
    Route::post('/dispo-inbound', ZasDispoInboundController::class)
        ->name('recruiting.zas.dispo-inbound');
});

// Bewerber-Datei-Stream (Slot-Prefix `upl-*`)
Route::get('/files/{applicantUuid}/{slot}', ZasFileController::class)
    ->name('recruiting.zas.files')
    ->where('slot', 'upl-[a-z0-9-]+');

// Mitarbeiter-Datei-Stream (Slot-Prefix `emp-*`)
Route::get('/employee-files/{employeeUuid}/{slot}', ZasEmployeeFileController::class)
    ->name('recruiting.zas.employee-files')
    ->where('slot', 'emp-[a-z0-9-]+');
