<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoJobOfferShift;

/**
 * „Kann nicht“ auf der Jobseite — NUR bei Anlass Dringend (Markus 27.10.:
 * „Es geht nur um den einen dicken Tag wo es brennt“; Woche/Vierwochen volle
 * Flexibilitaet). Der Grund ist Pflicht (eine Huerde, kein Formular), die
 * Notiz freiwillig. Eine Absage aendert am Versand nichts; sie ist Information
 * fuer die Disposition und zaehlt in der Akte (DispoJobAbsagen).
 */
final class JobOffersAbsage
{
    public const GRUENDE = [
        'krank'       => 'krank',
        'anderer_job' => 'anderer Job',
        'privat'      => 'privat verhindert',
        'uni_schule'  => 'Uni/Schule',
        'sonstiges'   => 'sonstiges',
    ];

    public const NOTIZ_MAX = 200;

    /**
     * @param list<int> $employeeIds Identitaets-Gruppe der Person
     * @return 'ok'|'weg'|'kein_grund'|'nicht_dringend'|'schon_zugesagt'
     */
    public static function ausfuehren(int $offerShiftId, array $employeeIds, string $grund, string $notiz): string
    {
        if (!array_key_exists($grund, self::GRUENDE)) {
            return 'kein_grund';
        }

        return DB::transaction(function () use ($offerShiftId, $employeeIds, $grund, $notiz) {
            $os = RecDispoJobOfferShift::query()->whereKey($offerShiftId)->with(['offer', 'shift.request'])->lockForUpdate()->first();
            if ($os === null || $os->offer === null || $os->shift?->request === null
                || !in_array((int) $os->offer->rec_employee_id, $employeeIds, true)) {
                return 'weg';
            }
            if (!$os->shift->request->laeuft() || $os->shift->datum->lt(now()->startOfDay())) {
                return 'weg';
            }
            if ($os->shift->request->anlass !== 'dringend') {
                return 'nicht_dringend';
            }
            if ($os->accepted_at !== null) {
                return 'schon_zugesagt';
            }
            if ($os->declined_at !== null) {
                return 'ok'; // schon abgesagt — der erste Grund bleibt
            }
            $os->update([
                'declined_at'     => now(),
                'declined_reason' => $grund,
                'declined_note'   => mb_substr(trim($notiz), 0, self::NOTIZ_MAX),
            ]);

            return 'ok';
        }, 3);
    }
}
