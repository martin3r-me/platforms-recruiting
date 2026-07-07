<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkTask
{
    public function __construct(
        public readonly array $payload,
        public readonly string $contentHash,
    ) {
    }
}
