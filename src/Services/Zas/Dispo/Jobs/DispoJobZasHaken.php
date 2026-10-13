<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;

/**
 * Haken „in ZAS eingetragen“ (Runde 1 §4.7, Runde 2 §6): einzeln umschalten
 * oder alle offenen Zusagen einer Schicht auf einmal. IDs aus dem Browser
 * werden nur innerhalb der uebergebenen Anfrage gesucht.
 */
final class DispoJobZasHaken
{
    /** @return bool false, wenn die Zeile nicht zur Anfrage gehoert oder (inzwischen) nicht mehr zugesagt ist */
    public static function umschalten(RecDispoJobRequest $request, int $offerShiftId, ?int $userId): bool
    {
        $zeile = RecDispoJobOfferShift::query()
            ->whereKey($offerShiftId)
            ->whereNotNull('accepted_at')
            ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
            ->first();
        if ($zeile === null) {
            return false;
        }
        $an = $zeile->zas_entered_at === null;
        // Bedingtes Update: nimmt die Person zwischen Lesen und Schreiben zurueck, bleibt die Zeile ohne Haken.
        $n = RecDispoJobOfferShift::query()
            ->whereKey($zeile->id)
            ->whereNotNull('accepted_at')
            ->update([
                'zas_entered_at'         => $an ? now() : null,
                'zas_entered_by_user_id' => $an ? $userId : null,
            ]);

        return $n > 0;
    }

    /** @return int Anzahl neu gesetzter Haken */
    public static function alle(RecDispoJobRequest $request, int $shiftId, ?int $userId): int
    {
        $shift = $request->shifts()->whereKey($shiftId)->first();
        if ($shift === null) {
            return 0;
        }

        return RecDispoJobOfferShift::query()
            ->where('shift_id', $shift->id)
            ->whereNotNull('accepted_at')
            ->whereNull('zas_entered_at')
            ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
            ->update(['zas_entered_at' => now(), 'zas_entered_by_user_id' => $userId]);
    }
}
