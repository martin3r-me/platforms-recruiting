<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonPairAuditPlanner;

/**
 * Bestands-Audit: welche Mitarbeiter-Paare bekommen den person_key
 * automatisch (doppelt-exakt, genau zwei, noch ungepaart), welche gehoeren
 * einem Menschen vorgelegt? Pure Planung ueber Arrays — das Kommando ist
 * nur die Huelle (Muster EnableManualBookingPlanner).
 */
final class PersonPairAuditPlannerTest extends TestCase
{
    private function emp(int $id, array $o = []): array
    {
        return $o + [
            'id' => $id, 'first_name' => 'Wannes', 'last_name' => 'Chaieb',
            'birth_date' => '1998-08-03', 'personnel_number' => 'RG' . $id,
            'rec_applicant_id' => null, 'person_key' => null, 'einsaetze' => 0,
        ];
    }

    public function test_exaktes_zweierpaar_ist_sicher(): void
    {
        $plan = PersonPairAuditPlanner::plan([
            $this->emp(819, ['rec_applicant_id' => 2851]),
            $this->emp(847, ['personnel_number' => 'MA18232', 'einsaetze' => 2]),
        ]);

        $this->assertCount(1, $plan['sicher']);
        $this->assertSame([819, 847], $plan['sicher'][0]['ids']);
        // Bewerber-Uebernahme: genau EINER der beiden ist verlinkt → der
        // andere erbt (gleiche Regel wie der Import-Auto-Link)
        $this->assertSame(2851, $plan['sicher'][0]['applicant_id']);
        $this->assertSame(2, $plan['sicher'][0]['einsaetze_unverlinkt'], 'Sortier-Kriterium: Einsaetze auf dem unverlinkten Datensatz');
        $this->assertSame([], $plan['pruefen']);
    }

    public function test_schon_gepaart_ist_kein_fall(): void
    {
        $plan = PersonPairAuditPlanner::plan([
            $this->emp(1, ['person_key' => 'pk-1']),
            $this->emp(2, ['person_key' => 'pk-1']),
        ]);

        $this->assertSame([], $plan['sicher']);
        $this->assertSame([], $plan['pruefen']);
    }

    public function test_mehr_als_zwei_gehoert_dem_menschen(): void
    {
        $plan = PersonPairAuditPlanner::plan([
            $this->emp(1), $this->emp(2), $this->emp(3),
        ]);

        $this->assertSame([], $plan['sicher']);
        $this->assertCount(1, $plan['pruefen']);
        $this->assertSame([1, 2, 3], $plan['pruefen'][0]['ids']);
    }

    public function test_zwei_verlinkte_auf_verschiedene_bewerber_gehoeren_dem_menschen(): void
    {
        // Beide Datensaetze haengen an VERSCHIEDENEN Bewerbungen — automatisch
        // zu stempeln hiesse, still zu behaupten, zwei Bewerbungen seien eine
        // Person. Das entscheidet ein Mensch.
        $plan = PersonPairAuditPlanner::plan([
            $this->emp(1, ['rec_applicant_id' => 100]),
            $this->emp(2, ['rec_applicant_id' => 200]),
        ]);

        $this->assertSame([], $plan['sicher']);
        $this->assertCount(1, $plan['pruefen']);
    }

    public function test_sortierung_einsaetze_auf_unverlinktem_zuerst(): void
    {
        $plan = PersonPairAuditPlanner::plan([
            $this->emp(1, ['first_name' => 'Anna', 'rec_applicant_id' => 10]),
            $this->emp(2, ['first_name' => 'Anna', 'personnel_number' => 'MA2']),
            $this->emp(3, ['first_name' => 'Ben', 'rec_applicant_id' => 20]),
            $this->emp(4, ['first_name' => 'Ben', 'personnel_number' => 'MA4', 'einsaetze' => 5]),
        ]);

        $this->assertSame([3, 4], $plan['sicher'][0]['ids'], 'Ben zuerst — 5 Einsaetze haengen unverlinkt');
        $this->assertSame([1, 2], $plan['sicher'][1]['ids']);
    }

    public function test_ohne_geburtsdatum_keine_gruppe(): void
    {
        $plan = PersonPairAuditPlanner::plan([
            $this->emp(1, ['birth_date' => null]),
            $this->emp(2, ['birth_date' => null]),
        ]);

        $this->assertSame([], $plan['sicher']);
        $this->assertSame([], $plan['pruefen']);
    }
}
