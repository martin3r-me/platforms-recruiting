<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Zeit-Berechnungen des Bestaetigungs-Flows (pure):
 * „Sei um HH:MM da" (Einsatzbeginn − Vorlauf) und das WhatsApp-Antwortfenster.
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

    /** WhatsApp-Antwortfenster: offen solange $now STRIKT vor lastInbound+24h liegt. */
    public static function isReplyWindowOpen(?\DateTimeInterface $lastInboundAt, \DateTimeInterface $now): bool
    {
        if ($lastInboundAt === null) {
            return false;
        }

        $deadline = \DateTimeImmutable::createFromInterface($lastInboundAt)->add(new \DateInterval('PT24H'));

        return $now < $deadline;
    }
}
