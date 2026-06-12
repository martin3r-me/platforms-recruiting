<?php

namespace Platform\Recruiting\Services\RefParsers;

class WebsiteFormRefParser implements SourceRefParser
{
    public function extract(?string $subject, ?string $body): ?string
    {
        if ($body && preg_match('/Posting-Ref:\s*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $body, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }
}
