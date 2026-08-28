<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Pure Regel (Runde 4, #2): braucht eine BESTAETIGTE Einbuchung nach einer
 * Lieferung eine erneute Bestaetigung? Ja, wenn datum/von/bis vom Snapshot
 * zum Bestaetigungszeitpunkt abweichen und der alte ODER neue Einsatztag
 * heute oder spaeter liegt. Ort/Adresse/Taetigkeit zaehlen NICHT (Kundenentscheid).
 * Ohne Snapshot (Altbestand) wird nichts verglichen.
 */
final class DispoReconfirmPolicy
{
    /**
     * @param array{datum:?string, von:?string, bis:?string} $confirmed Snapshot (Y-m-d, HH:MM)
     * @param array{datum:?string, von:?string, bis:?string} $incoming  Lieferung
     */
    public static function needsReconfirm(array $confirmed, array $incoming, string $today): bool
    {
        if (empty($confirmed['datum']) || empty($incoming['datum'])) {
            return false;
        }
        $changed = (string) $confirmed['datum'] !== (string) $incoming['datum']
            || self::norm($confirmed['von'] ?? null) !== self::norm($incoming['von'] ?? null)
            || self::norm($confirmed['bis'] ?? null) !== self::norm($incoming['bis'] ?? null);
        if (!$changed) {
            return false;
        }

        return max((string) $confirmed['datum'], (string) $incoming['datum']) >= $today;
    }

    private static function norm(?string $t): string
    {
        return $t === null ? '' : trim($t);
    }
}
