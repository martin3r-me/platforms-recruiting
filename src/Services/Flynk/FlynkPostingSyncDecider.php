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

    /** @param array<int,array{event_type:string,status:string,generation:int,seq:int,content_hash:string}> $rows */
    public static function generation(array $rows): int
    {
        $sentCloses = 0;
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::CLOSE && $r['status'] === 'sent') {
                $sentCloses++;
            }
        }
        return $sentCloses + 1;
    }

    public static function publishRowExists(array $rows, int $gen): bool
    {
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::PUBLISH && (int) $r['generation'] === $gen) {
                return true;
            }
        }
        return false;
    }

    public static function publishSent(array $rows, int $gen): bool
    {
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::PUBLISH && (int) $r['generation'] === $gen && $r['status'] === 'sent') {
                return true;
            }
        }
        return false;
    }

    public static function closeRowExists(array $rows, int $gen): bool
    {
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::CLOSE && (int) $r['generation'] === $gen) {
                return true;
            }
        }
        return false;
    }

    public static function lastDeliverableContentHash(array $rows, int $gen): ?string
    {
        $best = null;
        $bestSeq = -1;
        foreach ($rows as $r) {
            if ((int) $r['generation'] !== $gen) {
                continue;
            }
            if (!in_array($r['event_type'], [FlynkEvent::PUBLISH, FlynkEvent::UPDATE], true)) {
                continue;
            }
            if (!in_array($r['status'], ['pending', 'sent'], true)) {
                continue;
            }
            if ((int) $r['seq'] > $bestSeq) {
                $bestSeq = (int) $r['seq'];
                $best = (string) $r['content_hash'];
            }
        }
        return $best;
    }

    public static function buildState(array $rows, bool $isOpen, string $contentHash): FlynkPostingState
    {
        $gen = self::generation($rows);

        return new FlynkPostingState(
            isOpen: $isOpen,
            contentHash: $contentHash,
            generation: $gen,
            publishRowExists: self::publishRowExists($rows, $gen),
            publishSent: self::publishSent($rows, $gen),
            closeRowExists: self::closeRowExists($rows, $gen),
            lastDeliverableContentHash: self::lastDeliverableContentHash($rows, $gen),
        );
    }
}
