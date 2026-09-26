<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalCompleteness;

/**
 * Der Vollstaendigkeitsring aus dem Entwurf (.ring-row in
 * resources/mockups/crew-portal.html). Gezaehlt werden nur nach R28
 * RELEVANTE Felder — sonst staende der Ring bei jedem Nicht-Ersthelfer
 * dauerhaft unter 100 %, weil ihn Felder betreffen, die ihn gar nicht
 * betreffen.
 */
final class PortalCompletenessTest extends TestCase
{
    public function test_prozent_zaehlt_nur_relevante_felder(): void
    {
        // R28: Ersthelfer-Datum und -Schein zaehlen bei "Nein" nicht mit —
        // sonst staende der Ring bei jedem Nicht-Ersthelfer dauerhaft unter 100 %.
        $felder = [
            'email' => ['label' => 'Email'],
            'first_aider_valid_until' => ['label' => 'Bis', 'required_if' => ['is_first_aider' => true]],
        ];

        $stand = PortalCompleteness::stand($felder, ['email' => 'a@b.de', 'is_first_aider' => false]);

        $this->assertSame(100, $stand['prozent']);
        $this->assertSame(1, $stand['gesamt']);
        $this->assertSame([], $stand['fehlend']);
    }

    public function test_fehlende_felder_kommen_mit_beschriftung(): void
    {
        $felder = ['email' => ['label' => 'Email'], 'shoe_size' => ['label' => 'Schuhgroesse (Zahl)']];

        $stand = PortalCompleteness::stand($felder, ['email' => 'a@b.de']);

        $this->assertSame(50, $stand['prozent']);
        $this->assertSame(['shoe_size' => 'Schuhgroesse (Zahl)'], $stand['fehlend']);
    }

    public function test_leerstring_und_leeres_array_gelten_als_fehlend(): void
    {
        $felder = ['a' => ['label' => 'A'], 'b' => ['label' => 'B'], 'c' => ['label' => 'C']];

        $stand = PortalCompleteness::stand($felder, ['a' => '', 'b' => [], 'c' => null]);

        $this->assertSame(0, $stand['prozent']);
        $this->assertCount(3, $stand['fehlend']);
    }

    public function test_die_null_ist_kein_leerer_wert(): void
    {
        // Kinderzahl 0 und Steuerklasse sind echte Antworten.
        $stand = PortalCompleteness::stand(['number_of_children' => ['label' => 'Kinder']], ['number_of_children' => 0]);

        $this->assertSame(100, $stand['prozent']);
    }

    public function test_false_ist_kein_leerer_wert(): void
    {
        // Ein boolesches Nein ist eine Antwort, keine Luecke.
        $stand = PortalCompleteness::stand(['is_first_aider' => ['label' => 'Ersthelfer']], ['is_first_aider' => false]);

        $this->assertSame(100, $stand['prozent']);
    }

    public function test_ohne_felder_steht_der_ring_auf_hundert(): void
    {
        $this->assertSame(100, PortalCompleteness::stand([], [])['prozent']);
    }
}
