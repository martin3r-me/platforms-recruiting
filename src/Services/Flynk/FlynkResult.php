<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus,
        public readonly ?string $taskId,
        public readonly bool $permanent,
        public readonly bool $rateLimited,
        public readonly bool $unauthorized,
        public readonly ?string $error,
    ) {
    }
}
