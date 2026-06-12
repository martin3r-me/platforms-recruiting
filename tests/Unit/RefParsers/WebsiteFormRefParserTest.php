<?php

namespace Platform\Recruiting\Tests\Unit\RefParsers;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\RefParsers\WebsiteFormRefParser;

class WebsiteFormRefParserTest extends TestCase
{
    private WebsiteFormRefParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WebsiteFormRefParser();
    }

    public function test_extracts_posting_ref_from_body(): void
    {
        $body = "Neue Bewerbung über das Formular\n\nPosting-Ref: 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b\n\nName: Max";

        $this->assertSame(
            '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
            $this->parser->extract(null, $body)
        );
    }

    public function test_returns_null_without_ref_line(): void
    {
        $this->assertNull($this->parser->extract('Bewerbung', 'Hallo, hier meine Bewerbung'));
    }
}
