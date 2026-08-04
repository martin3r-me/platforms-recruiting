<?php

namespace Platform\Recruiting\Services\Statistics;

/**
 * Parst die zwei phase_advanced-Summary-Formate aus rec_auto_pilot_logs
 * (RecApplicant.php:479-483 bzw. :550-554). phase_returned braucht keinen
 * Parser (IDs strukturiert in details).
 */
final class PhaseAdvancedSummaryParser
{
    /** @return array{from: ?string, to: string}|null null = Extraktion fehlgeschlagen */
    public static function parse(string $summary): ?array
    {
        // Format A: Phase "X" abgeschlossen — weiter zu "Y".
        if (preg_match('/^Phase "(.+)" abgeschlossen — weiter zu "(.+)"\.$/su', $summary, $m)) {
            return ['from' => $m[1], 'to' => $m[2]];
        }
        // Format B: Manuell weiter zu Phase "Y".  (from = NULL, Spec §5)
        if (preg_match('/^Manuell weiter zu Phase "(.+)"\.$/su', $summary, $m)) {
            return ['from' => null, 'to' => $m[1]];
        }

        return null;
    }
}
