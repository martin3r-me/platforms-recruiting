<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingState
{
    public function __construct(
        public readonly bool $isOpen,
        public readonly string $contentHash,
        public readonly int $generation,
        public readonly bool $publishRowExists,
        public readonly bool $publishSent,
        public readonly bool $closeRowExists,
        public readonly ?string $lastDeliverableContentHash,
    ) {
    }
}
