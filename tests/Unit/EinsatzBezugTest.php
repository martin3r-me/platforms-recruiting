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

    // --- Nachbesserungsrunde 1 (Pruefbericht task-6-7-review.md) ---------

    /**
     * EB-7: ein fehlendes status_id darf NICHT wie AUFTRAG behandelt werden.
     * Der Default von "?? -1" ist die sichere Richtung — unbekannt loest
     * nichts aus.
     */
    public function test_ein_fehlender_status_loest_nichts_aus(): void
    {
        $this->assertFalse(EinsatzBezug::loestAus(
            ['datum' => '2026-10-20'], '2026-10-01', 4
        ));
    }

    /**
     * EB-11: der Statuswaechter in erinnerungFaellig() ist eine eigene Kopie
     * des Waechters in loestAus() — bisher ungetestet. Ein storniertes
     * Angebot (STATUS_STORNO = 3 in RecDispoAssignment) darf keine
     * Erinnerung ausloesen, obwohl es im Vorlauffenster liegt.
     */
    public function test_ein_stornierter_einsatz_loest_keine_erinnerung_aus(): void
    {
        $this->assertFalse(EinsatzBezug::erinnerungFaellig(
            ['datum' => '2026-10-02', 'status_id' => 3], '2026-10-01', 2
        ));
    }

    /**
     * EB-4: die Grenze von naechster() ist der Einsatz HEUTE — bisher hat
     * keiner der Tests sie beruehrt (Muster 11, bereits in Aufgabe 7 einmal
     * korrigiert). Ein Einsatz am selben Tag ist "naechster", nicht "schon
     * vorbei".
     */
    public function test_ein_einsatz_heute_ist_der_naechste_bezug(): void
    {
        $naechster = EinsatzBezug::naechster([
            ['datum' => '2026-10-01', 'status_id' => 1],
        ], '2026-10-01');

        $this->assertSame('2026-10-01', $naechster['datum']);
    }

    /**
     * EB-5 / EB-6: ein leeres Datum muss in naechster() ausgeschlossen
     * bleiben. Das deckt zwei Stellen zugleich — den Leer-Waechter in
     * tageBis() (EB-6) und den "?? -1"-Fallback im Filter von naechster()
     * (EB-5): wird der Fallback zu "?? 0", wuerde ein unlesbares Datum als
     * "heute" durchrutschen.
     */
    public function test_ein_leeres_datum_ist_niemals_der_naechste_bezug(): void
    {
        $this->assertNull(EinsatzBezug::naechster([
            ['datum' => '', 'status_id' => 1],
        ], '2026-10-01'));
    }

    /**
     * EB-8: loestAus() muss den uebergebenen Vorlauf benutzen, nicht die
     * Konstante 4 fest verdrahten. Mit vorlaufTage=10 reichen 5 Tage nicht —
     * ein hartcodiertes "4" wuerde hier faelschlich true liefern.
     */
    public function test_ein_anderer_vorlauf_als_vier_wird_tatsaechlich_benutzt(): void
    {
        $this->assertFalse(EinsatzBezug::loestAus(
            ['datum' => '2026-10-06', 'status_id' => 1], '2026-10-01', 10
        ));
    }

    /**
     * EB-8b: erinnerungFaellig() muss die uebergebene Schwelle benutzen,
     * nicht die Konstante 2 fest verdrahten. Mit schwelleTage=5 ist ein
     * Einsatz in 4 Tagen faellig — ein hartcodiertes "2" wuerde hier
     * faelschlich false liefern.
     */
    public function test_eine_andere_schwelle_als_zwei_wird_tatsaechlich_benutzt(): void
    {
        $this->assertTrue(EinsatzBezug::erinnerungFaellig(
            ['datum' => '2026-10-05', 'status_id' => 1], '2026-10-01', 5
        ));
    }

    /**
     * EB-10: tageBis() normalisiert auf Mitternacht (setTime(0,0)). Ohne das
     * wuerde eine Uhrzeit in "heute" den Vorlauf verfaelschen: von
     * "01.10. 23:00 Uhr" bis "05.10. 00:00 Uhr" liegen nur 3 Stunden und
     * 1 Tag Differenz in echten Zeitstempeln, aber vier volle Kalendertage.
     */
    public function test_eine_uhrzeit_in_heute_veraendert_den_vorlauf_nicht(): void
    {
        $this->assertTrue(EinsatzBezug::loestAus(
            ['datum' => '2026-10-05', 'status_id' => 1], '2026-10-01 23:00:00', 4
        ));
    }

    /**
     * ET-13 / EB-16: ein stornierter Einsatz darf nicht als "naechster
     * Bezug" zurueckkommen, auch wenn er zeitlich vor einem gueltigen
     * Auftrag liegt.
     */
    public function test_ein_stornierter_einsatz_ist_nicht_der_naechste_bezug(): void
    {
        $naechster = EinsatzBezug::naechster([
            ['datum' => '2026-10-05', 'status_id' => 3],
            ['datum' => '2026-10-10', 'status_id' => 1],
        ], '2026-10-01');

        $this->assertSame('2026-10-10', $naechster['datum']);
    }

    /** ET-13 / EB-16: ebenso fuer einen bereits beendeten Einsatz. */
    public function test_ein_beendeter_einsatz_ist_nicht_der_naechste_bezug(): void
    {
        $naechster = EinsatzBezug::naechster([
            ['datum' => '2026-10-05', 'status_id' => 2],
            ['datum' => '2026-10-10', 'status_id' => 1],
        ], '2026-10-01');

        $this->assertSame('2026-10-10', $naechster['datum']);
    }

    /** Nur Storno/Beendet in der Liste: es gibt keinen naechsten Bezug. */
    public function test_nur_stornierte_einsaetze_ergeben_keinen_naechsten_bezug(): void
    {
        $this->assertNull(EinsatzBezug::naechster([
            ['datum' => '2026-10-05', 'status_id' => 3],
            ['datum' => '2026-10-10', 'status_id' => 2],
        ], '2026-10-01'));
    }
}
