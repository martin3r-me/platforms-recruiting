<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\CsvEncodingNormalizer;

class CsvEncodingNormalizerTest extends TestCase
{
    public function test_utf8_passthrough(): void
    {
        $this->assertSame("Müller;Köln\n", CsvEncodingNormalizer::toUtf8("Müller;Köln\n"));
    }

    public function test_strips_utf8_bom(): void
    {
        $this->assertSame('Name;Ort', CsvEncodingNormalizer::toUtf8("\xEF\xBB\xBFName;Ort"));
    }

    public function test_converts_windows_1252(): void
    {
        // "Müller;Köln" in Windows-1252-Bytes (ü=0xFC, ö=0xF6)
        $this->assertSame('Müller;Köln', CsvEncodingNormalizer::toUtf8("M\xFCller;K\xF6ln"));
    }

    public function test_windows_1252_euro_sign(): void
    {
        // 0x80 ist € in Windows-1252 (in ISO-8859-1 nicht belegt)
        $this->assertSame('32 €', CsvEncodingNormalizer::toUtf8("32 \x80"));
    }

    public function test_empty_string(): void
    {
        $this->assertSame('', CsvEncodingNormalizer::toUtf8(''));
    }

    public function test_bom_only(): void
    {
        $this->assertSame('', CsvEncodingNormalizer::toUtf8("\xEF\xBB\xBF"));
    }

    public function test_result_is_valid_utf8(): void
    {
        $out = CsvEncodingNormalizer::toUtf8("Stra\xDFe;\xE4\xF6\xFC");
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertSame('Straße;äöü', $out);
    }
}
