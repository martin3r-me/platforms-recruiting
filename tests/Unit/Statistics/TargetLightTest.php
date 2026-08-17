<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Statistics\TargetLight;

/**
 * Ampel-Logik. Herleitung in Spec §7: Pipeline rechnet eine Hochrechnung aufs
 * Laufzeitende (eine absolute Schwelle steht am Kampagnenanfang immer auf Rot
 * und am Ende immer auf Gruen), Erfuellung bleibt absolut (Unterschriften
 * kommen in Schueben nach jeder Schulung).
 */
final class TargetLightTest extends TestCase
{
    // ---------- Pipeline: fehlende Pflege ----------

    public function test_ohne_bedarf_oder_faktor_keine_ampel(): void
    {
        $ohneBedarf = TargetLight::pipeline(50, null, 7.0, '2026-07-01', '2026-09-01', '2026-08-17');
        $this->assertSame(TargetLight::GREY, $ohneBedarf['status']);
        $this->assertNull($ohneBedarf['pct']);

        $ohneFaktor = TargetLight::pipeline(50, 40, null, '2026-07-01', '2026-09-01', '2026-08-17');
        $this->assertSame(TargetLight::GREY, $ohneFaktor['status']);

        // Bedarf 0 ist kein Ziel — auch grau, nicht "100 % erreicht"
        $this->assertSame(
            TargetLight::GREY,
            TargetLight::pipeline(50, 0, 7.0, '2026-07-01', '2026-09-01', '2026-08-17')['status'],
        );
    }

    // ---------- Pipeline: Hochrechnung ----------

    public function test_hochrechnung_auf_das_laufzeitende(): void
    {
        // Bedarf 40 x Faktor 7 = 280 Ziel. Tag 47 von 62, 33 Bewerbungen.
        // Hochrechnung: 33 / 47 * 62 = 43,5 -> 44 von 280 = 16 % -> rot
        $r = TargetLight::pipeline(33, 40, 7.0, '2026-07-01', '2026-09-01', '2026-08-17');
        $this->assertSame(280, $r['target']);
        $this->assertSame(44, $r['projected']);
        $this->assertSame(16, $r['pct']);
        $this->assertSame(TargetLight::RED, $r['status']);
    }

    public function test_schwellen_greifen_auf_die_hochrechnung(): void
    {
        // Halbzeit (Tag 31 von 62), Ziel 100 (Bedarf 50 x Faktor 2)
        // 30 Bewerbungen -> Hochrechnung 60 -> 60 % -> gelb (Grenze ist inklusiv)
        $gelb = TargetLight::pipeline(30, 50, 2.0, '2026-07-01', '2026-09-01', '2026-08-01');
        $this->assertSame(60, $gelb['pct']);
        $this->assertSame(TargetLight::YELLOW, $gelb['status']);

        // 29 -> 58 % -> rot
        $this->assertSame(
            TargetLight::RED,
            TargetLight::pipeline(29, 50, 2.0, '2026-07-01', '2026-09-01', '2026-08-01')['status'],
        );

        // 45 -> Hochrechnung 90 -> 90 % -> gruen (Grenze inklusiv)
        $gruen = TargetLight::pipeline(45, 50, 2.0, '2026-07-01', '2026-09-01', '2026-08-01');
        $this->assertSame(90, $gruen['pct']);
        $this->assertSame(TargetLight::GREEN, $gruen['status']);
    }

    public function test_abgelaufene_laufzeit_rechnet_nicht_mehr_hoch(): void
    {
        // Laufzeit vorbei: es kommt nichts mehr dazu, die Hochrechnung ist der Ist-Wert
        $r = TargetLight::pipeline(100, 50, 2.0, '2026-06-01', '2026-07-01', '2026-08-17');
        $this->assertSame(100, $r['projected']);
        $this->assertSame(100, $r['pct']);
        $this->assertSame(TargetLight::GREEN, $r['status']);
    }

    // ---------- Pipeline: Schutzregeln ----------

    public function test_zu_frueh_fuer_eine_aussage(): void
    {
        // Tag 2 von 62: bei 4 Bewerbungen waere jede Hochrechnung Kaffeesatz,
        // und eine falsche rote Ampel verbrennt das Vertrauen ins Feature.
        $r = TargetLight::pipeline(4, 40, 7.0, '2026-08-15', '2026-10-15', '2026-08-17');
        $this->assertSame(TargetLight::GREY, $r['status']);
        $this->assertNull($r['pct']);
        $this->assertStringContainsString('zu früh', $r['reason']);
    }

    public function test_startdatum_in_der_zukunft_ist_grau(): void
    {
        $this->assertSame(
            TargetLight::GREY,
            TargetLight::pipeline(0, 40, 7.0, '2026-09-01', '2026-10-01', '2026-08-17')['status'],
        );
    }

    public function test_ohne_laufzeit_absolute_lesart(): void
    {
        // Kein Enddatum gepflegt -> keine Hochrechnung, aber die nackte Quote
        // ist besser als gar keine Aussage. Muss im Grund benannt sein.
        $r = TargetLight::pipeline(140, 40, 7.0, '2026-07-01', null, '2026-08-17');
        $this->assertNull($r['projected']);
        $this->assertSame(50, $r['pct'], '140 von 280');
        $this->assertSame(TargetLight::RED, $r['status']);
        $this->assertStringContainsString('Laufzeitende', $r['reason']);

        // Auch ohne Startdatum: absolute Lesart, keine Division durch null
        $ohneStart = TargetLight::pipeline(140, 40, 7.0, null, '2026-09-01', '2026-08-17');
        $this->assertSame(50, $ohneStart['pct']);
        $this->assertNull($ohneStart['projected']);
    }

    public function test_kaputte_oder_verdrehte_laufzeit_faellt_auf_absolut_zurueck(): void
    {
        // Ende vor Anfang: Laufzeit 0 oder negativ -> keine Hochrechnung
        $verdreht = TargetLight::pipeline(140, 40, 7.0, '2026-09-01', '2026-07-01', '2026-08-17');
        $this->assertNull($verdreht['projected']);
        $this->assertSame(50, $verdreht['pct']);

        // Unlesbares Datum -> ebenfalls absolut, nicht abstuerzen
        $kaputt = TargetLight::pipeline(140, 40, 7.0, '2026-02-30', '2026-09-01', '2026-08-17');
        $this->assertNull($kaputt['projected']);
        $this->assertSame(50, $kaputt['pct']);
    }

    public function test_gebrochener_faktor_wird_aufgerundet(): void
    {
        // Bedarf 3 x Faktor 7,5 = 22,5 -> 23 Bewerbungen noetig, nicht 22
        $r = TargetLight::pipeline(23, 3, 7.5, '2026-06-01', '2026-07-01', '2026-08-17');
        $this->assertSame(23, $r['target']);
        $this->assertSame(100, $r['pct']);
    }

    // ---------- Erfuellung ----------

    public function test_erfuellung_rechnet_absolut(): void
    {
        $r = TargetLight::fulfilment(6, 40);
        $this->assertSame(15, $r['pct']);
        $this->assertSame(TargetLight::RED, $r['status']);

        $this->assertSame(TargetLight::YELLOW, TargetLight::fulfilment(24, 40)['status'], '60 %');
        $this->assertSame(TargetLight::GREEN, TargetLight::fulfilment(36, 40)['status'], '90 %');
        $this->assertSame(TargetLight::GREEN, TargetLight::fulfilment(50, 40)['status'], 'ueber 100 % bleibt gruen');
    }

    public function test_erfuellung_ohne_bedarf_keine_ampel(): void
    {
        $r = TargetLight::fulfilment(6, null);
        $this->assertSame(TargetLight::GREY, $r['status']);
        $this->assertNull($r['pct']);

        $this->assertSame(TargetLight::GREY, TargetLight::fulfilment(0, 0)['status']);
    }
}
