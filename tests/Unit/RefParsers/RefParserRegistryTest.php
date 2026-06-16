<?php

namespace Platform\Recruiting\Tests\Unit\RefParsers;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\RefParsers\KleinanzeigenRefParser;
use Platform\Recruiting\Services\RefParsers\RefParserRegistry;

class RefParserRegistryTest extends TestCase
{
    public function test_resolves_known_parser(): void
    {
        $this->assertInstanceOf(KleinanzeigenRefParser::class, RefParserRegistry::for('kleinanzeigen'));
    }

    public function test_returns_null_for_unknown_or_null_key(): void
    {
        $this->assertNull(RefParserRegistry::for('gibtsnicht'));
        $this->assertNull(RefParserRegistry::for(null));
    }

    public function test_keys_lists_all_parsers(): void
    {
        $this->assertSame(['kleinanzeigen', 'website_form', 'ref_code'], RefParserRegistry::keys());
    }
}
