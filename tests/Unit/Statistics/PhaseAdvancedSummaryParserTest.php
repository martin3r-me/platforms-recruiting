<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Statistics\PhaseAdvancedSummaryParser;

class PhaseAdvancedSummaryParserTest extends TestCase
{
    public function test_format_a_auto_advance_liefert_from_und_to(): void
    {
        $r = PhaseAdvancedSummaryParser::parse('Phase "Bewerbung" abgeschlossen — weiter zu "Onboarding".');
        $this->assertSame(['from' => 'Bewerbung', 'to' => 'Onboarding'], $r);
    }

    public function test_format_b_manuell_liefert_NUR_to_from_bleibt_null(): void
    {
        // Spec §5: from wird NICHT abgeleitet und NICHT geschrieben —
        // Ableitung passiert beim LESEN aus dem Vorgaenger.
        $r = PhaseAdvancedSummaryParser::parse('Manuell weiter zu Phase "Schulung buchen".');
        $this->assertSame(['from' => null, 'to' => 'Schulung buchen'], $r);
    }

    public function test_anfuehrungszeichen_im_phasennamen(): void
    {
        $r = PhaseAdvancedSummaryParser::parse('Phase "A" abgeschlossen — weiter zu "B (Teil "2")".');
        $this->assertNotNull($r);
        $this->assertSame('B (Teil "2")', $r['to']);
    }

    public function test_unbekanntes_format_liefert_null(): void
    {
        $this->assertNull(PhaseAdvancedSummaryParser::parse('Irgendein anderer Text.'));
        $this->assertNull(PhaseAdvancedSummaryParser::parse(''));
    }
}
