<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;

/**
 * „Ich bin dabei“ — Zusage zu einer Schicht, in einer Transaktion. Prueft zum
 * Klickzeitpunkt neu (Anfrage laeuft, Schicht offen, Tag nicht vorbei); im
 * Modus zuerst zaehlt sie die Zusagen gegen „gesucht“ und schliesst bei voll.
 */
final class JobOffersZusage
{
    /**
     * @param list<int> $employeeIds Identitaets-Gruppe der Person
     * @return 'ok'|'voll'|'weg'
     */
    public static function ausfuehren(int $offerShiftId, array $employeeIds): string
    {
        return DB::transaction(function () use ($offerShiftId, $employeeIds) {
            $os = RecDispoJobOfferShift::query()->lockForUpdate()->with(['offer', 'shift.request'])->find($offerShiftId);
            if ($os === null || !in_array((int) $os->offer->rec_employee_id, $employeeIds, true)) {
                return 'weg';
            }
            if ($os->accepted_at !== null) {
                return 'ok';
            }
            $shift = $os->shift;
            $request = $shift->request;
            if (!$request->laeuft() || $shift->closed_at !== null || $shift->datum->lt(now()->startOfDay())) {
                return $request->laeuft() && $shift->closed_at !== null ? 'voll' : 'weg';
            }
            if ($request->modus === RecDispoJobRequest::MODUS_ZUERST && $shift->gesucht > 0) {
                $zusagen = RecDispoJobOfferShift::query()->where('shift_id', $shift->id)->whereNotNull('accepted_at')->lockForUpdate()->count();
                if ($zusagen >= $shift->gesucht) {
                    $shift->update(['closed_at' => now()]);

                    return 'voll';
                }
                if ($zusagen + 1 >= $shift->gesucht) {
                    $shift->update(['closed_at' => now()]);
                }
            }
            $os->update(['accepted_at' => now()]);

            return 'ok';
        });
    }
}
