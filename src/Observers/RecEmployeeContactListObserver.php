<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Haelt das MA-Kontaktbuch (sync-verwaltete CRM-Liste) bei Einzel-Aenderungen
 * aktuell. Voll-Sync + Guard: EmployeeContactListSyncService::syncAll
 * (Command recruiting:sync-employee-contact-list / Settings-Panel).
 *
 * Bewusst KEIN deleted-Hook: recruiting:delete-employee loescht die
 * CrmContactLink-Zeilen vor dem forceDelete — beim deleted-Event ist der
 * Kontakt nicht mehr aufloesbar. Aufraeumen uebernimmt der Voll-Sync
 * (Spec: benannte Luecke).
 */
class RecEmployeeContactListObserver
{
    public function created(RecEmployee $employee): void
    {
        $this->sync($employee);
    }

    public function updated(RecEmployee $employee): void
    {
        if (!$employee->wasChanged(['is_active', 'employment_ended_at'])) {
            return;
        }

        $this->sync($employee);
    }

    private function sync(RecEmployee $employee): void
    {
        try {
            app(EmployeeContactListSyncService::class)->syncEmployee($employee);
        } catch (\Throwable $e) {
            // CRM-Fehler duerfen den MA-Save nie kippen (Muster: IncomingApplicationService).
            Log::error('[EmployeeContactListSync] Observer-Sync fehlgeschlagen', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
