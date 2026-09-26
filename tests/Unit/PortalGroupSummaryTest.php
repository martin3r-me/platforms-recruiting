<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalGroupSummary;

/**
 * Die eine Zeile unter dem Gruppennamen im Entwurf (.grouprow .v in
 * resources/mockups/crew-portal.html), z.B. "kevin.m@web.de · 0176 22 45 991".
 */
final class PortalGroupSummaryTest extends TestCase
{
    public function test_zeile_reiht_die_vorhandenen_werte(): void
    {
        $felder = ['email' => ['label' => 'Email'], 'phone' => ['label' => 'Telefon']];

        $this->assertSame(
            'kevin.m@web.de · 0176 22 45 991',
            PortalGroupSummary::zeile($felder, ['email' => 'kevin.m@web.de', 'phone' => '0176 22 45 991']),
        );
    }

    public function test_leere_werte_fallen_weg(): void
    {
        $felder = ['email' => ['label' => 'Email'], 'phone' => ['label' => 'Telefon']];

        $this->assertSame('kevin.m@web.de', PortalGroupSummary::zeile($felder, ['email' => 'kevin.m@web.de', 'phone' => '']));
    }

    public function test_ganz_leere_gruppe_sagt_es_deutlich(): void
    {
        $this->assertSame('Noch nichts hinterlegt', PortalGroupSummary::zeile(['email' => ['label' => 'Email']], []));
    }

    /**
     * Fixrunde 1 zu Aufgabe 7 (Befund 2): Aufrufer sollen die Leer-Meldung
     * ueber istLeer() erkennen, nicht per eigenem Zeichenketten-Vergleich
     * gegen den Wortlaut -- sonst zwei Wahrheiten statt einer.
     */
    public function test_ist_leer_erkennt_die_leer_meldung(): void
    {
        $leer = PortalGroupSummary::zeile(['email' => ['label' => 'Email']], []);
        $nichtLeer = PortalGroupSummary::zeile(['email' => ['label' => 'Email']], ['email' => 'kevin.m@web.de']);

        $this->assertTrue(PortalGroupSummary::istLeer($leer));
        $this->assertFalse(PortalGroupSummary::istLeer($nichtLeer));
    }

    public function test_zeile_wird_gekuerzt(): void
    {
        $felder = [];
        $werte = [];
        foreach (range(1, 20) as $i) {
            $felder["f{$i}"] = ['label' => "F{$i}"];
            $werte["f{$i}"] = "Wert{$i}";
        }

        $zeile = PortalGroupSummary::zeile($felder, $werte);

        $this->assertLessThanOrEqual(80, mb_strlen($zeile));
        $this->assertStringEndsWith('…', $zeile);
    }
}
