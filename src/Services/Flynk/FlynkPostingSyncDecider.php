<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingSyncDecider
{
    public static function decide(FlynkPostingState $s): ?string
    {
        if ($s->isOpen && !$s->publishRowExists) {
            if (!$s->hasStarted) {
                return null; // Startdatum liegt noch in der Zukunft
            }
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
            // ENDGUELTIG gescheiterte Zeilen (422/413/415, Datei nicht lesbar)
            // zaehlen mit: derselbe Inhalt wuerde genauso wieder scheitern, und
            // ohne diese Zeile entstuende alle 30 Minuten eine neue Meldung.
            // Erst ein GEAENDERTER Inhalt (z.B. neue Datei) loest wieder aus.
            // Voruebergehend gescheiterte (5xx, Netz) bleiben draussen — die
            // heilen sich wie bisher durch eine frische Meldung.
            $permanentFailed = $r['status'] === 'failed' && !empty($r['permanent_failure']);
            if (!in_array($r['status'], ['pending', 'sent'], true) && !$permanentFailed) {
                continue;
            }
            if ((int) $r['seq'] > $bestSeq) {
                $bestSeq = (int) $r['seq'];
                $best = (string) $r['content_hash'];
            }
        }
        return $best;
    }

    public static function buildState(array $rows, bool $isOpen, string $contentHash, bool $hasStarted = true): FlynkPostingState
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
            hasStarted: $hasStarted,
        );
    }

    /**
     * @param array<int,array{id:int,event_type:string}> $undelivered  nur pending/failed-Zeilen der Generation
     * @param bool $hasStarted  false = Startdatum liegt noch in der Zukunft: eine noch nicht
     *                          zugestellte publish-Zeile ist dann ebenfalls stale (sonst wuerde
     *                          der Retry-Pass sie am Startdatum vorbei senden)
     * @return int[]
     */
    public static function staleRowIds(bool $isOpen, array $undelivered, bool $hasStarted = true): array
    {
        $stale = [];
        foreach ($undelivered as $r) {
            $due = match ($r['event_type']) {
                FlynkEvent::PUBLISH => $isOpen && $hasStarted,
                FlynkEvent::UPDATE => $isOpen,
                FlynkEvent::CLOSE => !$isOpen,
                default => true,
            };
            if (!$due) {
                $stale[] = (int) $r['id'];
            }
        }
        return $stale;
    }

    /**
     * Endgueltig gescheiterte publish-Zeilen, deren Inhalt inzwischen ein
     * ANDERER ist. Sie blockieren sonst ihren Platz fuer immer (publishRowExists
     * bleibt wahr, publishSent falsch) — die Anzeige kaeme nie bei FLYNK an,
     * auch nicht nach einer neuen Datei. Loeschen gibt den Platz frei.
     * Bei unveraendertem Inhalt bleibt die Zeile stehen (kein Dauerfeuer).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return int[]
     */
    public static function deadPublishRowIds(array $rows, int $gen, string $currentHash): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::PUBLISH
                && (int) $r['generation'] === $gen
                && $r['status'] === 'failed'
                && !empty($r['permanent_failure'])
                && (string) $r['content_hash'] !== $currentHash
            ) {
                $ids[] = (int) $r['id'];
            }
        }

        return $ids;
    }

    /** @param int[] $updateSeqsInGeneration */
    public static function nextUpdateSeq(array $updateSeqsInGeneration): int
    {
        return $updateSeqsInGeneration === [] ? 1 : (max($updateSeqsInGeneration) + 1);
    }
}
