<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Antwortfenster-Status fuer die Anzeige (pur): open (mit Restzeit) | closed |
 * none (der MA hat noch nie geschrieben — es gibt kein Fenster, nur Vorlagen).
 * Genutzt von der Kommunikation und dem VA-Chat-Panel (Runde 4, #1).
 */
final class DispoReplyWindow
{
    /** @return array{state: string, left: ?string} */
    public static function info(?\DateTimeInterface $lastInboundAt, \DateTimeInterface $now): array
    {
        if ($lastInboundAt === null) {
            return ['state' => 'none', 'left' => null];
        }
        if (!DispoTimeCalculator::isReplyWindowOpen($lastInboundAt, $now)) {
            return ['state' => 'closed', 'left' => null];
        }
        $deadline = \DateTimeImmutable::createFromInterface($lastInboundAt)->modify('+24 hours');
        $mins = max(0, intdiv($deadline->getTimestamp() - $now->getTimestamp(), 60));

        return ['state' => 'open', 'left' => $mins >= 60 ? intdiv($mins, 60) . ' h' : $mins . ' min'];
    }
}
