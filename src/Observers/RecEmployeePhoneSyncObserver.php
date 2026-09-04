<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;

/**
 * Akten-Korrektur zieht den CRM-Kontakt mit (Vorfall RG19734, 04.09.):
 * wird die Telefonnummer eines MA geaendert, bekommt der verknuepfte Kontakt
 * denselben Stand — sonst ordnet der WhatsApp-Eingang Antworten von der neuen
 * Nummer keinem Kontakt zu und der Chat der Person bleibt leer.
 *
 * Bewusst NUR auf Eloquent-updated: die observer-freien Direkt-Schreibwege
 * (Format-Normalisierung, ZAS-Marker-Restaurierung) loesen hier nichts aus —
 * die aendern die Kennung der Nummer auch nicht.
 */
class RecEmployeePhoneSyncObserver
{
    public function updated(RecEmployee $employee): void
    {
        if (!$employee->wasChanged('phone')) {
            return;
        }

        try {
            app(ContactPhoneSync::class)->syncEmployee($employee);
        } catch (\Throwable $e) {
            // Nebenwirkung darf die eigentliche Speicherung nie zu Fall bringen.
            Log::warning('contact_phone_sync_failed', ['employee_id' => $employee->id, 'error' => $e->getMessage()]);
        }
    }
}
