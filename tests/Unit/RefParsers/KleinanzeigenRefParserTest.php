<?php

namespace Platform\Recruiting\Tests\Unit\RefParsers;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\RefParsers\KleinanzeigenRefParser;

class KleinanzeigenRefParserTest extends TestCase
{
    private KleinanzeigenRefParser $parser;

    protected function setUp(): void
    {
        $this->parser = new KleinanzeigenRefParser();
    }

    public function test_extracts_anzeigentitel_from_real_subject(): void
    {
        // Realer Alteingang aus dem System
        $subject = 'Nutzer-Anfrage zu deiner Anzeige "SERVICEKRÄFTE | EVENTGASTRONOMIE | KÖLN"';

        $this->assertSame(
            'SERVICEKRÄFTE | EVENTGASTRONOMIE | KÖLN',
            $this->parser->extract($subject, null)
        );
    }

    public function test_returns_null_without_anzeigen_pattern(): void
    {
        $this->assertNull($this->parser->extract('Bewerbung als Koch', 'Hallo, ich suche Arbeit'));
    }

    public function test_returns_null_for_null_subject(): void
    {
        $this->assertNull($this->parser->extract(null, 'irgendein Body'));
    }
}
