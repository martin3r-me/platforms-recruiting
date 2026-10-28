<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\NonEuNachtraeglichGate;

/**
 * Fall Mina (28.10.2026): EU-Angabe stand auf Ja, der Schulungsleiter
 * meldet „kein EU-Bürger“. HR korrigiert das Feld nach der Schulung — dann
 * muss derselbe Nicht-EU-Fall entstehen wie beim Übergang zu „teilgenommen“.
 */
class NonEuNachtraeglichGateTest extends TestCase
{
    private function gate(array $abweichend = []): bool
    {
        $w = array_merge([
            'hasLegalStatus' => true, 'isEuCitizen' => false, 'isChecked' => false,
            'schulungBesucht' => true, 'vertragRaus' => false, 'nonEuFallAbgelehnt' => false, 'aktiv' => true,
        ], $abweichend);

        return NonEuNachtraeglichGate::shouldRoute(...$w);
    }

    public function test_nachtraeglich_nein_nach_der_schulung_routet(): void
    {
        $this->assertTrue($this->gate());
    }

    public function test_vor_der_schulung_nicht_das_macht_der_uebergang_zu_attended(): void
    {
        $this->assertFalse($this->gate(['schulungBesucht' => false]));
    }

    public function test_eu_oder_unbeantwortet_routet_nicht(): void
    {
        $this->assertFalse($this->gate(['isEuCitizen' => true]));
        $this->assertFalse($this->gate(['isEuCitizen' => null]));
        $this->assertFalse($this->gate(['hasLegalStatus' => false]));
    }

    public function test_schon_geprueft_vertrag_raus_oder_inaktiv_routet_nicht(): void
    {
        $this->assertFalse($this->gate(['isChecked' => true]));
        $this->assertFalse($this->gate(['vertragRaus' => true]));
        $this->assertFalse($this->gate(['aktiv' => false]));
    }

    public function test_abgelehnter_nicht_eu_fall_wird_nicht_wiederbelebt(): void
    {
        // Abgelehnt: kein neuer Fall bei jedem spaeteren Aufruf.
        $this->assertFalse($this->gate(['nonEuFallAbgelehnt' => true]));
    }
}
