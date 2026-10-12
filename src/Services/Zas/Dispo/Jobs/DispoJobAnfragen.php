<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobRequest;

/** Pflege der Anfragen eines Teams (Uebersicht „Jobs anbieten“). */
final class DispoJobAnfragen
{
    /**
     * Spec §4.7: Eine Anfrage ist abgeschlossen, wenn ihr letzter angebotener
     * Tag vorbei ist. Gilt fuer laufende UND Entwuerfe (eine geplante Welle
     * einer abgelaufenen Anfrage soll nie mehr starten).
     *
     * @return int Anzahl abgeschlossener Anfragen
     */
    public static function abgelaufeneSchliessen(int $teamId): int
    {
        $heute = now()->toDateString();
        $ids = RecDispoJobRequest::query()
            ->where('team_id', $teamId)
            ->whereNull('closed_at')
            ->whereIn('status', [RecDispoJobRequest::STATUS_ENTWURF, RecDispoJobRequest::STATUS_LAEUFT])
            ->withMax('shifts', 'datum')
            ->get(['id'])
            ->filter(fn ($r) => $r->shifts_max_datum !== null && substr((string) $r->shifts_max_datum, 0, 10) < $heute)
            ->pluck('id')
            ->all();
        if ($ids === []) {
            return 0;
        }

        return RecDispoJobRequest::query()->where('team_id', $teamId)->whereIn('id', $ids)->whereNull('closed_at')
            ->update(['status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN, 'closed_at' => now()]);
    }
}
