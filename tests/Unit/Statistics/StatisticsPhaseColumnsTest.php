<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\StatisticsPhaseColumns;

/**
 * Ordnung der Phasen-Spalten in beiden Statistik-Tabellen (Claras Liste,
 * Antwortmail Sebastian ~18.08. + Defaults 07.09.):
 *
 *  - Die ERSTE Phase faellt weg: ihre kumulative Spalte („Phase 1 erreicht
 *    oder weiter") ist fast deckungsgleich mit „Bewerbungen" und stand als
 *    zweite, fast gleichnamige Spalte mit anderer Zahl daneben (178 vs. 183).
 *  - Die BUCHUNGS-Phase (completion_type 'booking', typ. „Schulung buchen")
 *    rueckt VOR den Buchungs-Trichter — Claras Prozess-Reihenfolge:
 *    Bewerbungen → Schulung buchen → Gebucht → Teilgenommen → Rest.
 *  - Die Vertragsversand-Phase (completion_type 'contract_sent') heisst in
 *    der Anzeige „Vollständig registriert": wer dort ankommt, hat das
 *    Onboarding abgeschlossen. Am TYP festgemacht, nicht am Phasennamen —
 *    der ist freier Nutzertext.
 */
final class StatisticsPhaseColumnsTest extends TestCase
{
    private const PHASES = [
        1 => ['name' => 'Bewerbung', 'completion_type' => 'fields'],
        2 => ['name' => 'Schulung buchen', 'completion_type' => 'booking'],
        3 => ['name' => 'Onboarding (Bestätigung)', 'completion_type' => 'fields'],
        4 => ['name' => 'Schulung & Verträge versenden', 'completion_type' => 'contract_sent'],
    ];

    public function test_erste_phase_faellt_weg_buchungsphase_rueckt_vor(): void
    {
        $plan = StatisticsPhaseColumns::plan(self::PHASES);

        $this->assertSame([2], $plan['early'], 'Buchungs-Phase vor den Trichter');
        $this->assertSame([3, 4], $plan['late'], 'Rest nach Teilgenommen, Reihenfolge nach order');
    }

    public function test_vertragsversand_phase_heisst_vollstaendig_registriert(): void
    {
        $plan = StatisticsPhaseColumns::plan(self::PHASES);

        $this->assertSame('Vollständig registriert', $plan['labels'][4]);
        $this->assertSame('Schulung buchen', $plan['labels'][2], 'andere Namen bleiben unveraendert');
    }

    public function test_ohne_buchungsphase_bleibt_alles_hinten(): void
    {
        // Direkteinstellungs-Stellen (completion_type manual/fields) haben keine
        // Buchungs-Phase — dann gibt es nichts vorzuziehen.
        $plan = StatisticsPhaseColumns::plan([
            1 => ['name' => 'Eingang', 'completion_type' => 'manual'],
            2 => ['name' => 'Datenerfassung', 'completion_type' => 'fields'],
        ]);

        $this->assertSame([], $plan['early']);
        $this->assertSame([2], $plan['late']);
    }

    public function test_eine_einzige_phase_ergibt_keine_spalten(): void
    {
        $plan = StatisticsPhaseColumns::plan([1 => ['name' => 'Eingang', 'completion_type' => 'manual']]);

        $this->assertSame([], $plan['early']);
        $this->assertSame([], $plan['late']);
    }

    public function test_leerer_phasensatz_bleibt_leer(): void
    {
        $plan = StatisticsPhaseColumns::plan([]);

        $this->assertSame(['early' => [], 'late' => [], 'labels' => []], $plan);
    }
}
