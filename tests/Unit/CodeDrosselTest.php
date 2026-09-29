<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\CodeDrossel;

/**
 * Ruling GD-1: ohne diese Bremse liesse sich Einmalcode::MAX_VERSUCHE durch
 * einfaches Neuanfordern beliebig oft zuruecksetzen.
 */
final class CodeDrosselTest extends TestCase
{
    public function test_ohne_vorgeschichte_darf_gesendet_werden(): void
    {
        $this->assertTrue(CodeDrossel::darfSenden([], '2026-09-29 12:00:00'));
    }

    public function test_die_vierte_anforderung_in_einer_stunde_wird_gebremst(): void
    {
        $this->assertFalse(CodeDrossel::darfSenden(
            ['2026-09-29 11:59:00', '2026-09-29 11:40:00', '2026-09-29 11:10:00'],
            '2026-09-29 12:00:00',
        ));
    }

    public function test_was_laenger_als_eine_stunde_her_ist_zaehlt_nicht_mehr_fuer_die_stunde(): void
    {
        $this->assertTrue(CodeDrossel::darfSenden(
            ['2026-09-29 10:59:00', '2026-09-29 10:40:00', '2026-09-29 10:10:00'],
            '2026-09-29 12:00:00',
        ));
    }

    public function test_die_sechste_am_tag_wird_gebremst(): void
    {
        $this->assertFalse(CodeDrossel::darfSenden([
            '2026-09-29 11:00:00', '2026-09-29 09:00:00', '2026-09-29 07:00:00',
            '2026-09-29 05:00:00', '2026-09-29 03:00:00',
        ], '2026-09-29 12:00:00'));
    }

    public function test_unlesbare_zeitstempel_werden_uebersprungen_und_bremsen_nicht(): void
    {
        $this->assertTrue(CodeDrossel::darfSenden(['nicht-lesbar'], '2026-09-29 12:00:00'));
    }

    /**
     * F7: der fruehere Test hatte nur zwei Eintraege, beide unter dem
     * Stundenlimit egal ob der 60-Minuten-Eintrag mitzaehlt oder nicht (0
     * oder 1 von 3 sind beide < 3) — er trennte `>` nicht von `>=`. Jetzt
     * drei Eintraege: zwei eindeutig innerhalb der Stunde plus einer exakt
     * 60 Minuten alt. Zaehlt der Grenz-Eintrag mit (`>=`), waeren es drei
     * und die Stunde waere voll; zaehlt er nicht mit (`>`, das richtige
     * Verhalten), bleiben es zwei und es darf noch gesendet werden.
     */
    public function test_genau_eine_stunde_alt_zaehlt_nicht_mehr_fuer_die_stunde(): void
    {
        $this->assertTrue(CodeDrossel::darfSenden(
            ['2026-09-29 11:50:00', '2026-09-29 11:40:00', '2026-09-29 11:00:00'],
            '2026-09-29 12:00:00',
        ));
    }

    /**
     * F5: Ruling GD-1 nennt die Fuenf ausdruecklich. Bisher pruefte nur der
     * Sechste-am-Tag-Test die Obergrenze von oben; dass vier Vor-Anforderungen
     * noch durchgehen (die fuenfte also noch gesendet werden darf), war
     * ungetestet. Zeitpunkte ausserhalb der Stunde, damit nur die
     * Tagesgrenze greift.
     */
    public function test_die_fuenfte_anforderung_am_tag_darf_noch_raus(): void
    {
        $this->assertTrue(CodeDrossel::darfSenden([
            '2026-09-29 09:00:00', '2026-09-29 07:00:00',
            '2026-09-29 05:00:00', '2026-09-29 03:00:00',
        ], '2026-09-29 12:00:00'));
    }

    /**
     * F6: Kalendertag und rollierendes 24-Stunden-Fenster waren bislang
     * ununterscheidbar, weil alle Testzeitstempel am selben Kalendertag
     * lagen. Fuenf Anforderungen gestern kurz vor Mitternacht duerfen die
     * naechste heute kurz nach Mitternacht NICHT bremsen (Kalendertag) —
     * ein rollierendes 24h-Fenster wuerde sie noch mitzaehlen und blockieren.
     */
    public function test_gestern_kurz_vor_mitternacht_zaehlt_nicht_fuer_den_heutigen_tag(): void
    {
        $this->assertTrue(CodeDrossel::darfSenden([
            '2026-09-29 20:00:00', '2026-09-29 20:15:00', '2026-09-29 20:30:00',
            '2026-09-29 20:45:00', '2026-09-29 21:00:00',
        ], '2026-09-30 00:30:00'));
    }

    /** Genau MAX_JE_STUNDE minus eins darf noch senden, MAX_JE_STUNDE selbst nicht mehr. */
    public function test_grenzwert_der_stunde_exakt(): void
    {
        $dritte = ['2026-09-29 11:50:00', '2026-09-29 11:40:00'];
        $this->assertTrue(CodeDrossel::darfSenden($dritte, '2026-09-29 12:00:00'));

        $vierte = [...$dritte, '2026-09-29 11:30:00'];
        $this->assertFalse(CodeDrossel::darfSenden($vierte, '2026-09-29 12:00:00'));
    }
}
