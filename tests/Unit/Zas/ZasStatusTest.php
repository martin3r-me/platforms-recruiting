<?php

namespace Platform\Recruiting\Tests\Unit\Zas;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasStatus;

/**
 * ZAS liefert den Mitarbeiter-Status in wechselnder Schreibweise
 * ("go", "MA", "inaktiv", "Inaktive"). Der Import arbeitet nur mit der
 * Normalform — sonst wuerde "inaktiv" als unbekannter Wert durchrutschen und
 * niemand deaktiviert.
 */
class ZasStatusTest extends TestCase
{
    public function test_go_und_ma_werden_grossgeschrieben_und_getrimmt(): void
    {
        $this->assertSame('GO', ZasStatus::normalize('go'));
        $this->assertSame('MA', ZasStatus::normalize(' MA '));
    }

    public function test_jede_inaktiv_schreibweise_wird_inaktiv(): void
    {
        $this->assertSame('INAKTIV', ZasStatus::normalize('inaktiv'));
        $this->assertSame('INAKTIV', ZasStatus::normalize('INAKTIV'));
        $this->assertSame('INAKTIV', ZasStatus::normalize('Inaktive'));
        $this->assertSame(ZasStatus::INAKTIV, 'INAKTIV');
    }

    public function test_leer_wird_null(): void
    {
        $this->assertNull(ZasStatus::normalize(''));
        $this->assertNull(ZasStatus::normalize(null));
        $this->assertNull(ZasStatus::normalize('   '));
    }

    public function test_unbekannter_wert_bleibt_grossgeschrieben(): void
    {
        $this->assertSame('XY', ZasStatus::normalize('xy'));
    }

    public function test_ist_bekannt(): void
    {
        $this->assertFalse(ZasStatus::istBekannt('XY'));
        $this->assertTrue(ZasStatus::istBekannt('INAKTIV'));
        $this->assertTrue(ZasStatus::istBekannt('GO'));
        $this->assertTrue(ZasStatus::istBekannt('MA'));
    }
}
