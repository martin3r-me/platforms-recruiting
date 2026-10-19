<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\LohnMa;

final class LohnMaTest extends TestCase
{
    private const EG1 = 15.33;
    private const MINDEST = 13.90;

    public function test_fall_1_angleich_rg_lohn_ueber_eg1(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, '1');

        $this->assertSame(LohnMa::ANGLEICH, $l->fall);
        $this->assertSame(15.33, $l->grundlohn);
        $this->assertSame(1.07, $l->zuschlag);
        $this->assertSame('15,33', $l->grundlohnText());
        $this->assertSame('1,07', $l->zuschlagText());
        $this->assertStringContainsString('Angleich an RG 16,40 € (13,90 + 2,50) → 1,07 €', $l->begruendung);
    }

    /** Review Focus 1: RG-Lohn unter EG 1 → 0,00, nie negativ. */
    public function test_fall_1_rg_lohn_unter_eg1_ergibt_null_nicht_negativ(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 0.60, LohnMa::QUELLE_BEWERBUNG, '3');

        $this->assertSame(0.0, $l->zuschlag);
        $this->assertSame('0,00', $l->zuschlagText());
    }

    public function test_steuerklasse_6_gibt_einen_euro_dazu(): void
    {
        $ohne = LohnMa::rechne(self::EG1, self::MINDEST, true, 0.60, LohnMa::QUELLE_BEWERBUNG, '1');
        $mit = LohnMa::rechne(self::EG1, self::MINDEST, true, 0.60, LohnMa::QUELLE_BEWERBUNG, '6');
        $mitAngleich = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, '6');

        $this->assertSame(0.0, $ohne->zuschlag);
        $this->assertSame(1.0, $mit->zuschlag);
        $this->assertSame(2.07, $mitAngleich->zuschlag);
        $this->assertStringContainsString('Steuerklasse 6 +1,00 €', $mit->begruendung);
        $this->assertStringNotContainsString('Steuerklasse 6', $ohne->begruendung);
    }

    public function test_steuerklasse_unbekannt_kein_euro_aber_sichtbar(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, null);

        $this->assertSame(1.07, $l->zuschlag);
        $this->assertStringContainsString('Steuerklasse unbekannt', $l->begruendung);
    }

    public function test_fall_2_unbekannt_liefert_leeren_zuschlag(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, null, null, '6');

        $this->assertSame(LohnMa::UNBEKANNT, $l->fall);
        $this->assertNull($l->zuschlag);
        $this->assertNull($l->zuschlagText());
        $this->assertSame('15,33', $l->grundlohnText(), 'Grundlohn ist immer bekannt');
        $this->assertStringContainsString('RG-Zuschlag unbekannt', $l->begruendung);
        $this->assertStringContainsString('Zuschlag leer', $l->begruendung);
    }

    public function test_fall_1b_zas_rechnet_wie_angleich(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_ZAS, '6');

        $this->assertSame(LohnMa::ZAS, $l->fall);
        $this->assertSame(2.07, $l->zuschlag);
        $this->assertStringContainsString('laut ZAS', $l->begruendung);
    }

    public function test_fall_3_manuell_rechnet_wie_angleich(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_MANUELL, '6');

        $this->assertSame(LohnMa::MANUELL, $l->fall);
        $this->assertSame(2.07, $l->zuschlag);
        $this->assertStringContainsString('von HR eingetragen', $l->begruendung);
    }

    public function test_fall_4_nur_ma_ohne_angleich(): void
    {
        $ohne = LohnMa::rechne(self::EG1, self::MINDEST, false, null, null, '1');
        $sechs = LohnMa::rechne(self::EG1, self::MINDEST, false, null, null, '6');

        $this->assertSame(LohnMa::NUR_MA, $ohne->fall);
        $this->assertSame(0.0, $ohne->zuschlag);
        $this->assertSame('0,00', $ohne->zuschlagText(), 'bekannter Wert wird ausdruecklich geschickt');
        $this->assertSame(1.0, $sechs->zuschlag);
        $this->assertStringContainsString('nur MA, kein Angleich', $ohne->begruendung);
    }

    /** Ohne RG zaehlt ein mitgegebener Zuschlag nicht — es gibt nichts anzugleichen. */
    public function test_nur_ma_ignoriert_einen_rg_zuschlag(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, false, 2.50, LohnMa::QUELLE_BEWERBUNG, '1');

        $this->assertSame(LohnMa::NUR_MA, $l->fall);
        $this->assertSame(0.0, $l->zuschlag);
    }

    public function test_rundung_auf_zwei_stellen(): void
    {
        $l = LohnMa::rechne(15.33, 13.90, true, 1.436, LohnMa::QUELLE_BEWERBUNG, '1');

        $this->assertSame(0.01, $l->zuschlag);
        $this->assertSame('0,01', $l->zuschlagText());
    }
}
