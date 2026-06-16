<?php

namespace Platform\Recruiting\Tests\Unit\RefParsers;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\RefParsers\RefCodeParser;

class RefCodeParserTest extends TestCase
{
    private RefCodeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RefCodeParser();
    }

    public function test_extracts_code_from_subject(): void
    {
        $this->assertSame('RG-K7M3', $this->parser->extract('Bewerbung RG-K7M3', null));
    }

    public function test_extracts_code_from_body_case_insensitive(): void
    {
        $this->assertSame('RG-K7M3', $this->parser->extract(null, "Hallo,\nich bewerbe mich. Code: rg-k7m3\nGruß"));
    }

    public function test_subject_wins_over_body(): void
    {
        $this->assertSame('RG-AAAA', $this->parser->extract('Re: RG-AAAA', 'anderer Code RG-BBBB im Text'));
    }

    public function test_returns_null_without_code(): void
    {
        $this->assertNull($this->parser->extract('Bewerbung als Koch', 'kein Code enthalten'));
        $this->assertNull($this->parser->extract(null, null));
    }

    public function test_ignores_lookalike_words(): void
    {
        $this->assertNull($this->parser->extract('RG-TOOL eingesetzt', null));
    }

    public function test_generate_produces_valid_codes(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $code = RefCodeParser::generate();
            $this->assertMatchesRegularExpression('/^RG-[A-HJ-NP-Z2-9]{4}$/', $code);
            $this->assertSame($code, $this->parser->extract("Betreff {$code}", null));
        }
    }
}
