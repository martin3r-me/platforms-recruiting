<?php

namespace Platform\Recruiting\Services\Comms\Forward;

/**
 * Chip am Dispo-Thread: "bei HR" (offen) oder "HR erledigt". Massgeblich ist
 * die JUENGSTE Weiterleitung des Threads.
 */
final class ForwardStatus
{
    public const OPEN = 'open';
    public const DONE = 'done';

    /**
     * `label` (optional) ist die Beschriftung des Ziel-Kanals ("Dispo Köln") und
     * wird durchgereicht — sie gehoert zur JUENGSTEN Weiterleitung, nicht zu irgendeiner.
     *
     * @param list<array{forwarded_at:int, done:bool, target:string, label?:?string}> $forwards
     * @return array{state:string, target:string, label:?string}|null
     */
    public static function latest(array $forwards): ?array
    {
        if ($forwards === []) {
            return null;
        }
        usort($forwards, static fn (array $a, array $b) => $b['forwarded_at'] <=> $a['forwarded_at']);

        return [
            'state' => $forwards[0]['done'] ? self::DONE : self::OPEN,
            'target' => (string) $forwards[0]['target'],
            'label' => $forwards[0]['label'] ?? null,
        ];
    }
}
