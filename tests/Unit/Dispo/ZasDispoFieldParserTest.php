<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoFieldParser;

class ZasDispoFieldParserTest extends TestCase
{
    public function test_date(): void
    {
        $this->assertSame('2026-05-19', ZasDispoFieldParser::date('19.05.2026'));
        $this->assertNull(ZasDispoFieldParser::date(''));
        $this->assertNull(ZasDispoFieldParser::date('2026-05-19')); // falsches Format -> null
        $this->assertNull(ZasDispoFieldParser::date('99.99.2026'));
        $this->assertNull(ZasDispoFieldParser::date(null));
    }

    public function test_time_accepts_with_and_without_seconds(): void
    {
        $this->assertSame('10:30', ZasDispoFieldParser::time('10:30'));
        $this->assertSame('10:30', ZasDispoFieldParser::time('10:30:00'));
        $this->assertSame('07:00', ZasDispoFieldParser::time('07:00'));
        $this->assertNull(ZasDispoFieldParser::time(''));
        $this->assertNull(ZasDispoFieldParser::time('25:00'));
        $this->assertNull(ZasDispoFieldParser::time(null));
    }

    public function test_decimal_comma(): void
    {
        $this->assertSame(27.49, ZasDispoFieldParser::decimal('27,49'));
        $this->assertSame(0.0, ZasDispoFieldParser::decimal('0'));
        $this->assertNull(ZasDispoFieldParser::decimal(''));
        $this->assertNull(ZasDispoFieldParser::decimal('abc'));
        $this->assertSame(1234.56, ZasDispoFieldParser::decimal('1.234,56'));
        $this->assertNull(ZasDispoFieldParser::decimal('1.5')); // mehrdeutig -> null, nie 15.0
        $this->assertNull(ZasDispoFieldParser::decimal(null));
    }

    public function test_int(): void
    {
        $this->assertSame(2, ZasDispoFieldParser::int('2'));
        $this->assertNull(ZasDispoFieldParser::int(''));
        $this->assertNull(ZasDispoFieldParser::int('x'));
        $this->assertNull(ZasDispoFieldParser::int(null));
    }

    public function test_text_converts_br_and_trims(): void
    {
        $this->assertSame(
            "Rhein-Energie-Stadion\nAachener Straße 999\nKassenhaus",
            ZasDispoFieldParser::text('Rhein-Energie-Stadion<br/>Aachener Straße 999<br>Kassenhaus')
        );
        $this->assertSame('x', ZasDispoFieldParser::text('  x  '));
        $this->assertNull(ZasDispoFieldParser::text('   '));
        $this->assertNull(ZasDispoFieldParser::text(null));
        // Trailing <br/> erzeugt keinen haengenden Umbruch
        $this->assertSame('Servicekräfte', ZasDispoFieldParser::text('Servicekräfte<br/>'));
    }
}
