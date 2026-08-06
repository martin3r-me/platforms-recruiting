<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\DispoInboundInspector;

class DispoInboundInspectorTest extends TestCase
{
    private DispoInboundInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new DispoInboundInspector();
    }

    // --- detectFormat ---

    public function test_detects_semicolon_csv(): void
    {
        $this->assertSame('csv', $this->inspector->detectFormat("VaNr;Kunde;Ort\n1;Broich;Koeln"));
    }

    public function test_detects_comma_csv(): void
    {
        $this->assertSame('csv', $this->inspector->detectFormat("VaNr,Kunde\n1,Broich"));
    }

    public function test_detects_json_object(): void
    {
        $this->assertSame('json', $this->inspector->detectFormat('{"events": []}'));
    }

    public function test_detects_json_array_with_leading_whitespace(): void
    {
        $this->assertSame('json', $this->inspector->detectFormat("  \n[{\"id\": 1}]"));
    }

    public function test_invalid_json_without_delimiter_is_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat('{kaputt'));
    }

    public function test_invalid_json_with_comma_is_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat('{"a":1,"b":'));
    }

    public function test_plain_text_is_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat("nur eine zeile ohne trennzeichen"));
    }

    public function test_empty_is_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat(''));
        $this->assertSame('unknown', $this->inspector->detectFormat("   \n  "));
    }

    // --- inspectCsv ---

    public function test_inspects_semicolon_csv(): void
    {
        $result = $this->inspector->inspectCsv("VaNr;Kunde;Ort\n1;Broich;Koeln\n2;EFP;Wuppertal\n");

        $this->assertSame(';', $result['delimiter']);
        $this->assertSame(['VaNr', 'Kunde', 'Ort'], $result['columns']);
        $this->assertSame([], $result['extra_columns']);
        $this->assertSame(2, $result['row_count']);
        $this->assertSame(['VaNr' => '1', 'Kunde' => 'Broich', 'Ort' => 'Koeln'], $result['rows'][0]);
    }

    public function test_inspects_crlf_and_quoted_values(): void
    {
        $result = $this->inspector->inspectCsv("Name;Bemerkung\r\n\"Meyer; Klaus\";\"Zeile \"\"zwei\"\"\"\r\n");

        $this->assertSame(1, $result['row_count']);
        $this->assertSame('Meyer; Klaus', $result['rows'][0]['Name']);
    }

    public function test_skips_empty_lines(): void
    {
        $result = $this->inspector->inspectCsv("A;B\n1;2\n\n\n3;4\n");
        $this->assertSame(2, $result['row_count']);
    }

    public function test_row_longer_than_header_gets_col_keys(): void
    {
        $result = $this->inspector->inspectCsv("A;B\n1;2;3\n");
        $this->assertSame(['A' => '1', 'B' => '2', 'col_2' => '3'], $result['rows'][0]);
        $this->assertSame(['col_2'], $result['extra_columns']);
    }

    public function test_header_only_means_zero_rows(): void
    {
        $result = $this->inspector->inspectCsv("A;B;C\n");
        $this->assertSame(0, $result['row_count']);
        $this->assertSame([], $result['rows']);
    }

    public function test_empty_content(): void
    {
        $result = $this->inspector->inspectCsv('');
        $this->assertNull($result['delimiter']);
        $this->assertSame([], $result['columns']);
        $this->assertSame([], $result['extra_columns']);
        $this->assertSame(0, $result['row_count']);
    }

    public function test_tab_delimiter_detected(): void
    {
        $result = $this->inspector->inspectCsv("A\tB\n1\t2\n");
        $this->assertSame("\t", $result['delimiter']);
        $this->assertSame(['A', 'B'], $result['columns']);
    }
}
