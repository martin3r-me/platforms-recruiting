<?php

use Illuminate\Support\Facades\Route;
use Platform\Recruiting\Http\Controllers\ZasExportController;
use Platform\Recruiting\Http\Controllers\ZasFileController;
use Platform\Recruiting\Http\Middleware\ZasBearerAuth;

/*
|--------------------------------------------------------------------------
| ZAS Bewerber-Export (externe IBEI-Schnittstelle)
|--------------------------------------------------------------------------
|
| Eigene Route-Gruppe ohne `web`-Middleware (kein Session, kein CSRF —
| ZAS spricht maschinell auf den Endpoint).
|
| Auth-Strategie:
|   - /zas/applicants/export.csv → Bearer-Token via ZasBearerAuth
|   - /zas/files/{uuid}/{slot}   → signierte URL (Signatur ist die Auth)
|
| Siehe docs/meingedeck/zas-applicant-export.md
*/

// CSV-Export-Endpoint
Route::middleware([ZasBearerAuth::class])->group(function () {
    Route::get('/applicants/export.csv', ZasExportController::class)
        ->name('recruiting.zas.applicants.export');
});

// Datei-Stream (Signatur statt Bearer — sonst koennte ZAS die URL nicht
// einfach im Importer aufrufen).
Route::get('/files/{applicantUuid}/{slot}', ZasFileController::class)
    ->name('recruiting.zas.files')
    ->where('slot', 'upl-[a-z0-9-]+');
