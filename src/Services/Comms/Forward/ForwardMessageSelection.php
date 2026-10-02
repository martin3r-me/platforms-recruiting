<?php

namespace Platform\Recruiting\Services\Comms\Forward;

/**
 * Welche Nachrichten im Weiterleiten-Fenster waehlbar sind. Pure (Unix-
 * Timestamps, kein Carbon), damit im Unit-Test ohne vendor pruefbar.
 *
 * Waehlbar: eingehende Nicht-Vorlagen-Nachrichten der letzten Tage — und
 * immer die angeklickte, auch wenn sie aelter ist (das Symbol sitzt an jeder
 * eingehenden Blase des Verlaufs).
 */
final class ForwardMessageSelection
{
    /**
     * @param list<array{id:int, direction:string, kind:string, ts:int}> $messages
     * @return list<int>
     */
    public static function candidates(array $messages, int $clickedId, int $now, int $days = 7): array
    {
        $since = $now - $days * 86400;
        $eligible = array_values(array_filter(
            $messages,
            static fn (array $m) => $m['direction'] === 'inbound' && $m['kind'] !== 'template',
        ));

        $clickedOk = false;
        foreach ($eligible as $m) {
            if ((int) $m['id'] === $clickedId) {
                $clickedOk = true;
                break;
            }
        }
        if (!$clickedOk) {
            return [];
        }

        $picked = array_values(array_filter(
            $eligible,
            static fn (array $m) => (int) $m['id'] === $clickedId || (int) $m['ts'] >= $since,
        ));
        usort($picked, static fn (array $a, array $b) => [$b['ts'], $b['id']] <=> [$a['ts'], $a['id']]);

        return array_map(static fn (array $m) => (int) $m['id'], $picked);
    }

    /**
     * @param array<int, int|string> $requested
     * @param list<int> $candidateIds
     * @return list<int>
     */
    public static function sanitize(array $requested, array $candidateIds): array
    {
        $wanted = array_flip(array_map('intval', $requested));

        return array_values(array_filter($candidateIds, static fn (int $id) => isset($wanted[$id])));
    }
}
