<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingSyncDecider
{
    public static function decide(FlynkPostingState $s): ?string
    {
        if ($s->isOpen && !$s->publishRowExists) {
            return FlynkEvent::PUBLISH;
        }
        if ($s->isOpen && $s->publishSent && $s->contentHash !== $s->lastDeliverableContentHash) {
            return FlynkEvent::UPDATE;
        }
        if (!$s->isOpen && $s->publishSent && !$s->closeRowExists) {
            return FlynkEvent::CLOSE;
        }
        return null;
    }
}
