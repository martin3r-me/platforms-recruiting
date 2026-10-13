<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * „Doch nicht“ (Runde 2, Spec §9): die eigene Zusage zuruecknehmen, solange
 * der Disponent sie nicht in ZAS eingetragen hat. Gleiche Sperr-Disziplin wie
 * JobOffersZusage: Schicht-Zeilen aufsteigend sperren, alles Veraenderliche
 * danach sperrend lesen. Das Update selbst ist bedingt — setzt der Disponent
 * im selben Moment den Haken, gewinnt der Haken.
 *
 * Eine geschlossene Schicht sperrt die Ruecknahme NICHT: eine volle Schicht im
 * Modus „zuerst“ wird wieder geoeffnet, wenn nach der Ruecknahme Zusagen <
 * gesucht sind. Schwestern (dieselbe Schicht aus einer anderen Anfrage) werden
 * mit zurueckgenommen; ist eine davon schon eingetragen, bleibt alles stehen.
 */
final class JobOffersRuecknahme
{
    /**
     * @param list<int> $employeeIds Identitaets-Gruppe der Person
     * @return 'ok'|'eingetragen'|'weg'
     */
    public static function ausfuehren(int $offerShiftId, array $employeeIds): string
    {
        return DB::transaction(function () use ($offerShiftId, $employeeIds) {
            // Vorab nur Unveraenderliches (Schicht der Angebots-Zeile, Schicht-Merkmale).
            $vorab = RecDispoJobOfferShift::query()->with('shift')->find($offerShiftId);
            if ($vorab === null || $vorab->shift === null) {
                return 'weg';
            }
            $schwestern = JobOffersSchwestern::finden($vorab->shift, $employeeIds, (int) $vorab->id, true);

            $shiftIds = array_values(array_unique(array_merge([(int) $vorab->shift_id], array_map(fn ($s) => (int) $s->shift_id, $schwestern))));
            sort($shiftIds);
            $shifts = [];
            foreach ($shiftIds as $id) {
                $shift = RecDispoJobShift::query()->whereKey($id)->lockForUpdate()->first();
                if ($shift !== null) {
                    $shifts[$id] = $shift;
                }
            }
            $shift = $shifts[(int) $vorab->shift_id] ?? null;
            if ($shift === null) {
                return 'weg';
            }

            // Frisch und sperrend NACH der Schicht-Sperre: Besitz + Idempotenz auf aktuellem Stand.
            $os = RecDispoJobOfferShift::query()->whereKey($offerShiftId)->with('offer')->lockForUpdate()->first();
            if ($os === null || $os->offer === null || !in_array((int) $os->offer->rec_employee_id, $employeeIds, true)) {
                return 'weg';
            }
            if ($os->accepted_at === null) {
                return $os->withdrawn_at !== null ? 'ok' : 'weg';
            }
            $request = RecDispoJobRequest::query()->whereKey($shift->request_id)->sharedLock()->first();
            if ($request === null || !$request->laeuft() || $shift->datum->lt(now()->startOfDay())) {
                return 'weg';
            }
            if ($os->zas_entered_at !== null || $os->zas_assignment_id !== null) {
                return 'eingetragen';
            }

            $frischeSchwestern = [];
            foreach ($schwestern as $s) {
                $frisch = RecDispoJobOfferShift::query()->whereKey($s->id)->lockForUpdate()->first();
                if ($frisch === null || $frisch->accepted_at === null) {
                    continue;
                }
                if ($frisch->zas_entered_at !== null || $frisch->zas_assignment_id !== null) {
                    return 'eingetragen';
                }
                $frischeSchwestern[] = $frisch;
            }

            if (self::zuruecknehmen($os) === 0) {
                return 'eingetragen';
            }
            self::wiederOeffnen($shift, $request);
            foreach ($frischeSchwestern as $frisch) {
                if (self::zuruecknehmen($frisch) > 0 && isset($shifts[(int) $frisch->shift_id])) {
                    $schwesterShift = $shifts[(int) $frisch->shift_id];
                    $schwesterRequest = RecDispoJobRequest::query()->whereKey($schwesterShift->request_id)->sharedLock()->first();
                    if ($schwesterRequest !== null) {
                        self::wiederOeffnen($schwesterShift, $schwesterRequest);
                    }
                }
            }

            return 'ok';
        }, 3);
    }

    /** Bedingtes Update: trifft nichts, wenn inzwischen ein Haken gesetzt wurde. */
    private static function zuruecknehmen(RecDispoJobOfferShift $os): int
    {
        return RecDispoJobOfferShift::query()
            ->whereKey($os->id)
            ->whereNotNull('accepted_at')
            ->whereNull('zas_entered_at')
            ->whereNull('zas_assignment_id')
            ->update(['accepted_at' => null, 'withdrawn_at' => now()]);
    }

    /** Modus zuerst: geschlossene Schicht wieder oeffnen, sobald wieder ein Platz frei ist. */
    private static function wiederOeffnen(RecDispoJobShift $shift, RecDispoJobRequest $request): void
    {
        if ($request->modus !== RecDispoJobRequest::MODUS_ZUERST || $shift->closed_at === null) {
            return;
        }
        $zusagen = RecDispoJobOfferShift::query()->where('shift_id', $shift->id)->whereNotNull('accepted_at')->lockForUpdate()->count();
        if ($zusagen < (int) $shift->gesucht) {
            $shift->update(['closed_at' => null]);
        }
    }
}
