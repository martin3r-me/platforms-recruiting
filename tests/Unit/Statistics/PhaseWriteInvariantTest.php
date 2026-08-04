<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;

/**
 * Nagelt die Observer-Vollstaendigkeit fest (Spec §7): Query-Builder-Updates
 * auf rec_phase_id umgehen Model-Events. Gescannt werden zwei Wurzeln:
 *  - src/               laufender Anwendungscode
 *  - database/migrations/  historische UND zukuenftige Migrationen
 * Migrationen sind ein dritter, eigener Schreibpfad (nicht nur ein
 * Unterfall von src/): sie laufen einmalig, meist vor/ausserhalb des
 * Observer-Lebenszyklus, und neue Migrationen tauchen erst hier auf.
 *
 * Statt einer Einzel-Ausnahme (FixApplicantPhase) gibt es eine ALLOWLIST
 * exakt zweier Dateien:
 *  - FixApplicantPhase.php: expliziter Transition-Insert direkt nach dem
 *    Update (Task 5) — Observer-Umgehung ist dokumentiert und kompensiert.
 *  - 2026_04_12_000003_migrate_extra_fields_to_phases.php: historischer
 *    Bulk-Update, lief vor Existenz der rec_phase_transitions-Tabelle —
 *    harmlos, aber nicht nachtraeglich korrigierbar.
 * Jede NEUE Migration, die rec_phase_id per Query-Builder anfasst, MUSS
 * diesen Test brechen, bis sie bewusst in die Allowlist aufgenommen wird
 * (siehe Assert-Message).
 *
 * HEURISTIK, kein Beweis: flaggt nur ->update([...rec_phase_id...]) in
 * Dateien, die auch DB::table enthalten. False Negatives moeglich (Update
 * ueber Variable, Query-Builder ohne DB::table-Literal), False Positives
 * bei Model-update() neben unabhaengigem DB::table. Der Test ist ein
 * Stolperdraht fuer den haeufigsten Fehler, kein Ersatz fuer Review.
 */
class PhaseWriteInvariantTest extends TestCase
{
    /**
     * Exakt diese Dateien duerfen rec_phase_id per Query-Builder anfassen.
     * Jede weitere Datei mit diesem Muster ist ein unbewachter Schreibpfad
     * und muss den Test brechen (siehe Klassen-Docblock).
     */
    private const ALLOWLIST = [
        'FixApplicantPhase.php',
        '2026_04_12_000003_migrate_extra_fields_to_phases.php',
    ];

    public function test_kein_rec_phase_id_update_ausserhalb_allowlist(): void
    {
        $roots = [
            __DIR__ . '/../../../src',
            __DIR__ . '/../../../database/migrations',
        ];
        $offenders = [];

        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if ($this->isAllowlisted($file->getPathname())) {
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
        }

        $this->assertSame([], $offenders,
            'Query-Builder-Update auf rec_phase_id gefunden — Observer wird umgangen. '
            . 'Entweder auf Model-Save umstellen, einen expliziten Transition-Insert '
            . 'ergaenzen (wie FixApplicantPhase), oder — falls bewusst und dokumentiert '
            . 'akzeptiert — den Dateinamen in PhaseWriteInvariantTest::ALLOWLIST aufnehmen.');
    }

    private function isAllowlisted(string $pathname): bool
    {
        foreach (self::ALLOWLIST as $allowed) {
            if (str_ends_with($pathname, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
