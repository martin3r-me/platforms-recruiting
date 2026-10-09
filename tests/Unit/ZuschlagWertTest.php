<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ZuschlagWert;

/** Review-Focus 4: "0,60" mit Komma darf nirgends zu 0 oder leer werden. */
final class ZuschlagWertTest extends TestCase
{
    public function test_eingabe(): void
    {
        $this->assertSame(0.6, ZuschlagWert::ausEingabe('0,60'));
        $this->assertSame(0.6, ZuschlagWert::ausEingabe('0.6'));
        $this->assertSame(0.0, ZuschlagWert::ausEingabe('0'));
        $this->assertSame(1.6, ZuschlagWert::ausEingabe(' 1,6 '));
        $this->assertNull(ZuschlagWert::ausEingabe(''));
        $this->assertNull(ZuschlagWert::ausEingabe('abc'));
        $this->assertNull(ZuschlagWert::ausEingabe('-1'));
        $this->assertNull(ZuschlagWert::ausEingabe('1,234'));
        $this->assertNull(ZuschlagWert::ausEingabe(null));
    }

    public function test_lesen_und_formatieren(): void
    {
        $this->assertSame(0.6, ZuschlagWert::lesen('0,60'));
        $this->assertSame(0.6, ZuschlagWert::lesen('0.60'));
        $this->assertSame(0.6, ZuschlagWert::lesen(0.6));
        $this->assertSame(1.0, ZuschlagWert::lesen(1));
        $this->assertNull(ZuschlagWert::lesen(''));
        $this->assertNull(ZuschlagWert::lesen(null));
        $this->assertNull(ZuschlagWert::lesen('null Komma sechs'));
        $this->assertSame('0,60', ZuschlagWert::format(0.6));
        $this->assertSame('0,00', ZuschlagWert::format(0.0));
        $this->assertSame('1.234,50', ZuschlagWert::format(1234.5));
    }

    public function test_alter_code(): void
    {
        $this->assertSame(0.6, ZuschlagWert::ausAvCode('AV-060'));
        $this->assertSame(2.6, ZuschlagWert::ausAvCode('AV-260'));
        $this->assertNull(ZuschlagWert::ausAvCode('AV-default'));
        $this->assertNull(ZuschlagWert::ausAvCode('AV-MA-LOG'));
        $this->assertNull(ZuschlagWert::ausAvCode(null));
    }
}
