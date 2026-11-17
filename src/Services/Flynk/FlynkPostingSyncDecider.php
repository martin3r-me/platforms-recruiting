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
        // Nur das Enddatum hat sich geaendert: FLYNK laesst die Anzeige selbst
        // am Enddatum auslaufen und muss das neue Datum kennen. Unbekanntes
        // Datum auf einer Seite (NULL) loest nichts aus.
        if ($s->isOpen && $s->publishSent
            && $s->closesKey !== null && $s->lastDeliverableClosesKey !== null
            && $s->closesKey !== $s->lastDeliverableClosesKey
        ) {
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
            // 'expired' = still verbuchtes Auslaufen (FLYNK nimmt selbst raus):
            // schliesst die Generation genauso ab wie ein gesendetes „entfernen".
            if ($r['event_type'] === FlynkEvent::CLOSE && in_array($r['status'], ['sent', 'expired'], true)) {
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
        $row = self::lastDeliverableRow($rows, $gen);

        return $row === null ? null : (string) $row['content_hash'];
    }

    /** Enddatum der letzten Meldung, die FLYNK hat oder noch bekommt. NULL = unbekannt. */
    public static function lastDeliverableClosesKey(array $rows, int $gen): ?string
    {
        $row = self::lastDeliverableRow($rows, $gen);
        $key = $row['closes_key'] ?? null;

        return $key === null || $key === '' ? null : (string) $key;
    }

    /**
     * Enddatum der letzten Meldung, die FLYNK WIRKLICH hat (nur `sent`).
     * Strenger als lastDeliverableClosesKey: fuer das stille Auslaufen zaehlt
     * nur, was angekommen ist — eine haengende oder gescheiterte Meldung mit
     * dem neuen Datum hat FLYNK nicht.
     */
    public static function lastSentClosesKey(array $rows, int $gen): ?string
    {
        $best = null;
        $bestSeq = -1;
        foreach ($rows as $r) {
            if ((int) $r['generation'] !== $gen || $r['status'] !== 'sent') {
                continue;
            }
            if (!in_array($r['event_type'], [FlynkEvent::PUBLISH, FlynkEvent::UPDATE], true)) {
                continue;
            }
            if ((int) $r['seq'] > $bestSeq) {
                $bestSeq = (int) $r['seq'];
                // Ein bei der Migration nur ANGENOMMENER Wert ist nicht
                // „angekommen": dann gilt das Datum bei FLYNK als unbekannt.
                $best = !empty($r['closes_assumed']) ? null : ($r['closes_key'] ?? null);
            }
        }

        return $best === null || $best === '' ? null : (string) $best;
    }

    /**
     * Uebernimmt FLYNK das Auslaufen dieser Anzeige selbst? Nur wenn ein
     * Stichtag gesetzt ist UND die „veroeffentlichen"-Meldung dieser Generation
     * an oder nach dem Stichtag zugestellt wurde. Aeltere Anzeigen hat FLYNK
     * von Hand gebaut — die bekommen weiter ein echtes „entfernen".
     *
     * @param string|null $since  Stichtag `YYYY-MM-DD`, null = Automatik aus
     */
    public static function autoExpiryCovered(array $rows, int $gen, ?string $since): bool
    {
        if ($since === null || $since === '') {
            return false;
        }
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::PUBLISH && (int) $r['generation'] === $gen && $r['status'] === 'sent') {
                $sentOn = (string) ($r['sent_on'] ?? '');

                return $sentOn !== '' && $sentOn >= $since;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|null */
    private static function lastDeliverableRow(array $rows, int $gen): ?array
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
                $best = $r;
            }
        }
        return $best;
    }

    /**
     * @param string|null $closesKey  Enddatum jetzt (`YYYY-MM-DD` / `none`), null = nicht vergleichen
     * @param bool $expired           der Tag des Enddatums ist rum
     * @param string|null $autoExpirySince  Stichtag, ab dem FLYNK selbst auslaufen laesst
     */
    public static function buildState(
        array $rows,
        bool $isOpen,
        string $contentHash,
        bool $hasStarted = true,
        ?string $closesKey = null,
        bool $expired = false,
        ?string $autoExpirySince = null,
    ): FlynkPostingState {
        $gen = self::generation($rows);
        $lastSentKey = self::lastSentClosesKey($rows, $gen);

        return new FlynkPostingState(
            isOpen: $isOpen,
            contentHash: $contentHash,
            generation: $gen,
            publishRowExists: self::publishRowExists($rows, $gen),
            publishSent: self::publishSent($rows, $gen),
            closeRowExists: self::closeRowExists($rows, $gen),
            lastDeliverableContentHash: self::lastDeliverableContentHash($rows, $gen),
            hasStarted: $hasStarted,
            closesKey: $closesKey,
            lastDeliverableClosesKey: self::lastDeliverableClosesKey($rows, $gen),
            // Still nur, wenn FLYNK GENAU dieses Enddatum hat: wurde es z.B.
            // nachtraeglich in die Vergangenheit gesetzt, kennt FLYNK noch das
            // alte — dann muss ein echtes „entfernen" raus.
            expiresSilently: $expired
                && $closesKey !== null && $closesKey !== 'none'
                && $lastSentKey === $closesKey
                && self::autoExpiryCovered($rows, $gen, $autoExpirySince),
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
