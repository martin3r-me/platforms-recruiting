<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Zeit-Berechnungen des Bestaetigungs-Flows (pure):
 * „Sei um HH:MM da" (Einsatzbeginn − Vorlauf) und die Bestaetigungs-Deadline
 * (Einsatzbeginn − N Stunden; ohne von-Zeit konservativ ab Mitternacht).
 */
class DispoTimeCalculator
{
    public static function arrivalTime(?string $von, ?int $vorlaufMinuten): ?string
    {
        if ($von === null || !preg_match('/^(\d{2}):(\d{2})$/', $von, $m)) {
            return null;
        }
        $minutes = ((int) $m[1]) * 60 + (int) $m[2] - (int) ($vorlaufMinuten ?? 0);
        $minutes = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function confirmationDeadline(string $datum, ?string $von, int $deadlineHours): string
    {
        $time = ($von !== null && preg_match('/^\d{2}:\d{2}$/', $von)) ? $von : '00:00';
        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$datum} {$time}");
        if ($start === false) {
            $start = new \DateTimeImmutable("{$datum} 00:00");
        }

        return $start->sub(new \DateInterval('PT' . $deadlineHours . 'H'))->format('Y-m-d H:i');
    }
}
