<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Gilt der Erledigt-Stempel noch? Reiner Zeitvergleich, ohne Laravel —
 * damit unit-testbar wie ConversationEscalation.
 *
 * Ein Chat ist genau dann erledigt, wenn er gestempelt wurde UND seither
 * niemand mehr geschrieben hat. Schreibt die Person danach erneut, faellt
 * der Chat von selbst in die Eskalation zurueck; es braucht keinen Job,
 * der Stempel zurueckraeumt.
 */
final class ConversationHandledState
{
    /**
     * @param ?int $handledAt     Unix-TS des Stempels (null = nie abgehakt)
     * @param ?int $lastInboundAt Unix-TS der letzten eingehenden Nachricht
     */
    public static function isHandled(?int $handledAt, ?int $lastInboundAt): bool
    {
        if ($handledAt === null) {
            return false;
        }

        return $lastInboundAt === null || $lastInboundAt <= $handledAt;
    }
}
