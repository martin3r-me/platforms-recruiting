<?php

namespace Platform\Recruiting\Services\Flynk;

/** Die Datei, die mit einer Meldung als `files[]` an FLYNK geht. */
final class FlynkAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $contents,
        public readonly ?string $mime = null,
    ) {
    }
}
