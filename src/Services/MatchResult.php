<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecPosting;

class MatchResult
{
    public const VIA_DEDICATED_CHANNEL = 'dedicated_channel';
    public const VIA_EXTERNAL_REF = 'external_ref';
    public const VIA_LLM = 'llm';
    public const VIA_CHANNEL_DEFAULT = 'channel_default';
    public const VIA_MANUAL = 'manual';
    /** Kein Auto-Assign, nur Vorschlag für die Inbox (z. B. Referenz auf geschlossene Ausschreibung). */
    public const VIA_SUGGESTION = 'suggestion';

    public function __construct(
        public readonly RecPosting $posting,
        public readonly string $via,
        public readonly ?string $confidence = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function isAssignable(): bool
    {
        return $this->via !== self::VIA_SUGGESTION;
    }
}
