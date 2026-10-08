<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Wer bekommt ein Dokument "an alle Eingebuchten" (Spec §3.3): Einbuchungen
 * mit Status Auftrag, nicht verschwunden, mit zugeordnetem Mitarbeiter.
 * Einmaliger Stand zum Zeitpunkt des Bereitstellens — Nachruecker legt HR nach.
 */
final class EingebuchteFuerDokument
{
    /** @return list<int> */
    public static function ids(int $eventId): array
    {
        return DB::table('rec_dispo_assignments')
            ->where('rec_dispo_event_id', $eventId)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNotNull('rec_employee_id')
            ->distinct()
            ->orderBy('rec_employee_id')
            ->pluck('rec_employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
