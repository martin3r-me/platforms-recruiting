<?php

namespace Platform\Recruiting\Services\RefParsers;

interface SourceRefParser
{
    /**
     * Extract the external reference (job id / Anzeigentitel / Posting-UUID) from an inbound message.
     */
    public function extract(?string $subject, ?string $body): ?string;
}
