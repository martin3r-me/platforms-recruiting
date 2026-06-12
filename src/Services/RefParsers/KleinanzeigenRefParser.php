<?php

namespace Platform\Recruiting\Services\RefParsers;

class KleinanzeigenRefParser implements SourceRefParser
{
    public function extract(?string $subject, ?string $body): ?string
    {
        if ($subject && preg_match('/zu deiner Anzeige\s+"([^"]+)"/iu', $subject, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
