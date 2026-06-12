<?php

namespace Platform\Recruiting\Services\RefParsers;

class RefParserRegistry
{
    /** @var array<string, class-string<SourceRefParser>> */
    private const PARSERS = [
        'kleinanzeigen' => KleinanzeigenRefParser::class,
        'website_form' => WebsiteFormRefParser::class,
    ];

    public static function for(?string $key): ?SourceRefParser
    {
        $class = self::PARSERS[$key] ?? null;

        return $class ? new $class() : null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::PARSERS);
    }
}
