<?php

namespace Platform\Recruiting\Services\Dashboard;

/**
 * Baut das Change-Token für den Dashboard-Dirty-Check-Poll.
 * Pure: Inputs rein, deterministischer Hash raus — keine DB, kein Laravel.
 */
class DashboardChangeToken
{
    /**
     * @param array $counters      flache COUNT/MAX-Werte (int|string|null) in fester Reihenfolge
     * @param array $enrichingIds  Bewerber-IDs mit laufendem Enrichment (Reihenfolge egal)
     * @param string $timeBucket   grober Zeit-Bucket, z. B. now()->format('Y-m-d H')
     */
    public static function build(array $counters, array $enrichingIds, string $timeBucket): string
    {
        $enrichingIds = array_values($enrichingIds);
        sort($enrichingIds);

        return hash('sha256', json_encode([
            array_values($counters),
            $enrichingIds,
            $timeBucket,
        ]));
    }
}
