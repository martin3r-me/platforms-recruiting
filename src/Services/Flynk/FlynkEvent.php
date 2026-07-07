<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkEvent
{
    public const PUBLISH = 'publish';
    public const UPDATE = 'update';
    public const CLOSE = 'close';

    /** @return string[] */
    public static function all(): array
    {
        return [self::PUBLISH, self::UPDATE, self::CLOSE];
    }
}
