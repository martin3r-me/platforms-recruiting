<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;

/**
 * Nagelt die Observer-Vollstaendigkeit fest (Spec §7): Query-Builder-Updates
 * auf rec_phase_id umgehen Model-Events. Einzige erlaubte Stelle ist
 * FixApplicantPhase (dort expliziter Transition-Insert, Task 5).
 *
 * HEURISTIK, kein Beweis: flaggt nur ->update([...rec_phase_id...]) in
 * Dateien, die auch DB::table enthalten. False Negatives moeglich (Update
 * ueber Variable, Query-Builder ohne DB::table-Literal), False Positives
 * bei Model-update() neben unabhaengigem DB::table. Der Test ist ein
 * Stolperdraht fuer den haeufigsten Fehler, kein Ersatz fuer Review.
 */
class PhaseWriteInvariantTest extends TestCase
{
    public function test_kein_rec_phase_id_update_ausserhalb_fix_command(): void
    {
        $srcDir = __DIR__ . '/../../../src';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (str_ends_with($file->getPathname(), 'FixApplicantPhase.php')) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            // ->update([ ... 'rec_phase_id' ... ]) im selben Aufruf (dotall, non-greedy)
            if (preg_match('/->update\s*\(\s*\[[^)]*?rec_phase_id/s', $code)) {
                // Model-Instanz-update() feuert Events — nur DB::table/Query-Builder
                // ist gefaehrlich. Heuristik: Datei muss DB::table enthalten UND
                // das Muster; sonst manuell pruefen.
                if (str_contains($code, 'DB::table')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders,
            'Query-Builder-Update auf rec_phase_id gefunden — Observer wird umgangen. '
            . 'Entweder auf Model-Save umstellen oder expliziten Transition-Insert ergaenzen.');
    }
}
