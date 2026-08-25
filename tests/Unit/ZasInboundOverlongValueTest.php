<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Ueberlange ZAS-Werte duerfen nicht die ganze Zeile kosten.
 *
 * Anlass (Massenimport 2026-08-25, Lieferung #15): im ZAS-Feld `Fuehrerschein`
 * stand der Freitext "Ich habe praxis Pruefung am 13.05.2026" (37 Zeichen)
 * gegen `drivers_license_class` = string(32). Der INSERT lief in SQLSTATE
 * 22001 und die KOMPLETTE Zeile fiel aus — ein Mensch fehlte im System,
 * wegen eines Nebenfeldes.
 *
 * Regel: Feld leer lassen + warnen (mit Originalwert, damit HR ihn uebertragen
 * kann). Bewusst NICHT kappen — ein auf 32 Zeichen abgeschnittener Satz ist
 * ein plausibel aussehender Falschwert. Gleiche Haltung wie der Datums-Parser
 * derselben Klasse: "Fehlende Daten fallen im Portal/HR auf, falsche nicht."
 *
 * Gezaehlt wird in ZEICHEN (mb_strlen), nicht in Bytes — MySQL-VARCHAR(32)
 * unter utf8mb4 haelt 32 Zeichen, auch wenn Umlaute mehr Bytes brauchen.
 */
class ZasInboundOverlongValueTest extends TestCase
{
    /**
     * Der echte Resolver liest die Lookup-Werte per DB-Facade; hier ist keine
     * DB da und auch keine noetig. loadPairs() ist die EINZIGE DB-Tuer der
     * Klasse — leer heisst "kein Lookup-Treffer", also wird der Rohwert
     * durchgereicht. Genau der Fall, um den es hier geht.
     */
    private function map(array $row): array
    {
        $resolver = new class extends ZasLookupReverseResolver {
            protected function loadPairs(string $lookupName): array
            {
                return [];
            }
        };

        return (new ZasInboundRowMapper($resolver))->map($row);
    }

    public function test_zu_langer_wert_wird_nicht_uebernommen_und_warnt(): void
    {
        $freitext = 'Ich habe praxis Pruefung am 13.05.2026'; // 37 Zeichen

        $res = $this->map(['Fuehrerschein' => $freitext]);

        $this->assertArrayNotHasKey(
            'drivers_license_class',
            $res['employee'],
            'Ueberlanger Wert darf nicht ins Feld geschrieben werden'
        );
        $this->assertStringContainsString('drivers_license_class', implode(' | ', $res['warnings']));
        $this->assertStringContainsString($freitext, implode(' | ', $res['warnings']));
    }

    public function test_wert_genau_an_der_grenze_bleibt_erhalten(): void
    {
        $genau32 = str_repeat('A', 32);

        $res = $this->map(['Fuehrerschein' => $genau32]);

        $this->assertSame($genau32, $res['employee']['drivers_license_class']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_umlaute_zaehlen_als_zeichen_nicht_als_bytes(): void
    {
        // 32 Zeichen, aber 40 Bytes in UTF-8 — mit strlen() wuerde der Wert
        // faelschlich abgewiesen. Regressionswaechter fuer mb_strlen.
        $mitUmlauten = str_repeat('ü', 8) . str_repeat('A', 24);
        $this->assertSame(32, mb_strlen($mitUmlauten));
        $this->assertGreaterThan(32, strlen($mitUmlauten));

        $res = $this->map(['Fuehrerschein' => $mitUmlauten]);

        $this->assertSame($mitUmlauten, $res['employee']['drivers_license_class']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_zu_langer_lookup_rohwert_wird_nicht_uebernommen(): void
    {
        // Kein Lookup-Treffer => Rohwert wird uebernommen. health_insurance
        // ist string(64), der Freitext hier ist laenger.
        $lang = str_repeat('Krankenkasse ', 6); // 78 Zeichen

        $res = $this->map(['Krankenkasse' => $lang]);

        $this->assertArrayNotHasKey('health_insurance', $res['employee']);
        $this->assertStringContainsString('health_insurance', implode(' | ', $res['warnings']));
    }

    public function test_zu_langes_land_faellt_auf_default_zurueck_und_warnt(): void
    {
        $res = $this->map(['Land' => str_repeat('X', 70)]);

        $this->assertSame('de', $res['employee']['country_code']);
        $this->assertStringContainsString('country_code', implode(' | ', $res['warnings']));
    }

    public function test_zu_lange_anstellungsart_wird_nicht_uebernommen(): void
    {
        $res = $this->map(['Anstellungsart' => str_repeat('Y', 40)]);

        $this->assertArrayNotHasKey('employment_classification', $res['hr']);
        $this->assertStringContainsString('employment_classification', implode(' | ', $res['warnings']));
    }
}
