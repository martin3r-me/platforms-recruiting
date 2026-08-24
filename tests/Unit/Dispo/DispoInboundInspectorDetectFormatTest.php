<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\DispoInboundInspector;

/**
 * detectFormat() erkennt den ZAS-Webexport ({Block}-Format) VOR der
 * JSON-Heuristik — vorher landete er faelschlich als 'unknown'
 * (irrefuehrendes parse_status='unparseable', obwohl die Datei per
 * Block-Splitter einwandfrei lesbar ist). Siehe Report
 * 2026-08-24-dispo-inbound-cleanup.
 */
class DispoInboundInspectorDetectFormatTest extends TestCase
{
    private DispoInboundInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new DispoInboundInspector();
    }

    public function test_personal_block_marker_is_blocks(): void
    {
        $this->assertSame('blocks', $this->inspector->detectFormat("{Personal}\nRG1;Mustermann;Max"));
    }

    public function test_dispo2_block_marker_is_blocks(): void
    {
        $this->assertSame('blocks', $this->inspector->detectFormat("{Dispo2}\n"));
    }

    public function test_dispo_block_marker_is_blocks(): void
    {
        $this->assertSame('blocks', $this->inspector->detectFormat("{Dispo}\nDS-1;RG19063;RG14"));
    }

    public function test_real_json_object_is_still_json(): void
    {
        $this->assertSame('json', $this->inspector->detectFormat('{"a":1}'));
    }

    public function test_semicolon_csv_is_still_csv(): void
    {
        $this->assertSame('csv', $this->inspector->detectFormat('a;b;c'));
    }

    public function test_empty_is_still_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat(''));
    }

    public function test_plain_text_without_delimiter_is_still_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat('nur eine zeile ohne trennzeichen'));
    }
}
