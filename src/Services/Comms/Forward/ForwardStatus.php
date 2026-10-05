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
     * @param list<array{forwarded_at:int, done:bool, target:string}> $forwards
     * @return array{state:string, target:string}|null
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
        ];
    }
}
