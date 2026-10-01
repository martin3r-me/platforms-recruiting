<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EinsatzBezug;

/**
 * Welcher Einsatz gehoert zu einem offenen Punkt — und loest er ueberhaupt
 * etwas aus? Rein, kein Framework, keine Uhr: Heute und die Schwellenwerte
 * werden hereingereicht.
 */
final class EinsatzBezugTest extends TestCase
{
    public function test_ein_angebot_loest_nichts_aus(): void
    {
        // STATUS_ANGEBOT = 0. Angefragt ist nicht gebucht.
        $this->assertFalse(EinsatzBezug::loestAus(
            ['datum' => '2026-10-20', 'status_id' => 0], '2026-10-01', 4
        ));
    }

    public function test_ein_auftrag_mit_genug_vorlauf_loest_aus(): void
    {
        $this->assertTrue(EinsatzBezug::loestAus(
            ['datum' => '2026-10-20', 'status_id' => 1], '2026-10-01', 4
        ));
    }

    public function test_vier_tage_vorlauf_reichen_genau(): void
    {
        $this->assertTrue(EinsatzBezug::loestAus(
            ['datum' => '2026-10-05', 'status_id' => 1], '2026-10-01', 4
        ));
    }

    public function test_drei_tage_vorlauf_reichen_nicht(): void
    {
        // Darunter ist die Nachricht keine Hilfe mehr, sondern Stoerung.
        $this->assertFalse(EinsatzBezug::loestAus(
            ['datum' => '2026-10-04', 'status_id' => 1], '2026-10-01', 4
        ));
    }

    public function test_ein_vergangener_einsatz_loest_nichts_aus(): void
    {
        $this->assertFalse(EinsatzBezug::loestAus(
            ['datum' => '2026-09-30', 'status_id' => 1], '2026-10-01', 4
        ));
    }

    public function test_zwei_tage_vorher_ist_die_erinnerung_faellig(): void
    {
        $this->assertTrue(EinsatzBezug::erinnerungFaellig(
            ['datum' => '2026-10-03', 'status_id' => 1], '2026-10-01', 2
        ));
    }

    public function test_drei_tage_vorher_noch_nicht(): void
    {
        $this->assertFalse(EinsatzBezug::erinnerungFaellig(
            ['datum' => '2026-10-04', 'status_id' => 1], '2026-10-01', 2
        ));
    }

    public function test_der_naechste_einsatz_ist_der_zeitlich_naechste_kommende(): void
    {
        $naechster = EinsatzBezug::naechster([
            ['datum' => '2026-10-20', 'status_id' => 1],
            ['datum' => '2026-10-05', 'status_id' => 1],
            ['datum' => '2026-09-28', 'status_id' => 1],
        ], '2026-10-01');

        $this->assertSame('2026-10-05', $naechster['datum']);
    }

    public function test_ohne_kommenden_einsatz_gibt_es_keinen_bezug(): void
    {
        $this->assertNull(EinsatzBezug::naechster([
            ['datum' => '2026-09-28', 'status_id' => 1],
        ], '2026-10-01'));
    }

    /**
     * ET-7-Korrektur: erinnerungFaellig() hatte keine untere Schranke. Ein
     * VERGANGENER Einsatz (tageBis = -1) erfuellte "-1 <= schwelle" und loeste
     * eine Erinnerung fuer etwas aus, das schon vorbei ist.
     */
    public function test_ein_vergangener_einsatz_loest_keine_erinnerung_aus(): void
    {
        $this->assertFalse(EinsatzBezug::erinnerungFaellig(
            ['datum' => '2026-09-30', 'status_id' => 1], '2026-10-01', 2
        ));
    }

    /** Ein Einsatz HEUTE (tage = 0) muss weiterhin ausloesen. */
    public function test_ein_einsatz_heute_loest_die_erinnerung_aus(): void
    {
        $this->assertTrue(EinsatzBezug::erinnerungFaellig(
            ['datum' => '2026-10-01', 'status_id' => 1], '2026-10-01', 2
        ));
    }

    /** Ein unlesbares Datum darf keinen Versand ausloesen — die sichere Richtung. */
    public function test_ein_unlesbares_datum_loest_nichts_aus(): void
    {
        $this->assertFalse(EinsatzBezug::loestAus(
            ['datum' => 'Schrottwert', 'status_id' => 1], '2026-10-01', 4
        ));
    }

    /**
     * Die drei Zahlen werden hereingereicht, damit Tests sie ausschreiben
     * koennen — dieser Test sichert ihre tatsaechlichen Werte zu, sonst
     * prueft nichts, dass sie 4, 2 und 7 sind.
     */
    public function test_die_drei_konstanten_haben_die_vereinbarten_werte(): void
    {
        $this->assertSame(4, EinsatzBezug::VORLAUF_TAGE);
        $this->assertSame(2, EinsatzBezug::ERINNERUNG_TAGE);
        $this->assertSame(7, EinsatzBezug::PAUSE_TAGE);
    }
}
