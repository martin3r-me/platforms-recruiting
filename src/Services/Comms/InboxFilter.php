<?php

namespace Platform\Recruiting\Services\Comms;

/** Filterzustand der neuen Liste. Reines DTO. */
final class InboxFilter
{
    public function __construct(
        /** all | unread | green | yellow | red | missed */
        public readonly string $level = 'all',
        /** all | mine | <userId> */
        public readonly string $owner = 'all',
        public readonly string $search = '',
        /** true = NUR abgehakte zeigen */
        public readonly bool $handled = false,
        /** fuer owner = 'mine' */
        public readonly ?int $currentUserId = null,
    ) {}
}
