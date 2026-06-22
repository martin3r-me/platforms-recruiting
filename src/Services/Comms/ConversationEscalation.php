<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Reine, dependency-freie Berechnung des Eskalations-Zustands einer
 * WhatsApp-Konversation anhand des EINEN 24h-Service-Windows (Meta).
 *
 * Es gibt pro Konversation nur ein 24h-Fenster: Es startet/erneuert sich mit
 * jeder eingehenden Kundennachricht (last_inbound_at) und ist 24h offen.
 * "Grün/Gelb/Rot" ist daher der Countdown dieses einen Fensters, NICHT
 * "1./2./3. Fenster". Außerhalb des offenen Fensters erlaubt Meta nur Templates.
 *
 * Arbeitet mit Unix-Timestamps (Sekunden) statt Carbon, damit die Klasse ohne
 * Laravel/vendor-Autoload unit-testbar bleibt (Modul-Test-Konvention).
 */
final class ConversationEscalation
{
    public const LEVEL_NONE = 'none';
    public const LEVEL_GREEN = 'green';
    public const LEVEL_YELLOW = 'yellow';
    public const LEVEL_RED = 'red';
    public const LEVEL_MISSED = 'missed';

    /** Länge des Meta-Service-Windows in Stunden. */
    public const WINDOW_HOURS = 24;

    public function __construct(
        public readonly string $level,
        public readonly bool $isUnanswered,
        public readonly bool $windowOpen,
        public readonly ?int $windowExpiresAt,
        public readonly float $hoursLeftInWindow,
    ) {}

    /**
     * @param ?int  $lastInboundAt   Unix-TS der letzten eingehenden Nachricht
     * @param ?int  $lastOutboundAt  Unix-TS der letzten ausgehenden Nachricht
     * @param int   $now             Unix-TS "jetzt"
     * @param float $yellowHoursLeft Restzeit-Schwelle (Std.) für Gelb
     * @param float $redHoursLeft    Restzeit-Schwelle (Std.) für Rot
     */
    public static function compute(
        ?int $lastInboundAt,
        ?int $lastOutboundAt,
        int $now,
        float $yellowHoursLeft = 12.0,
        float $redHoursLeft = 3.0,
    ): self {
        $isUnanswered = $lastInboundAt !== null
            && ($lastOutboundAt === null || $lastInboundAt > $lastOutboundAt);

        if (!$isUnanswered) {
            return new self(self::LEVEL_NONE, false, false, null, 0.0);
        }

        $windowExpiresAt = $lastInboundAt + self::WINDOW_HOURS * 3600;
        $hoursLeft = ($windowExpiresAt - $now) / 3600;
        $windowOpen = ($windowExpiresAt - $now) > 0;

        if (!$windowOpen) {
            $level = self::LEVEL_MISSED;
        } elseif ($hoursLeft <= $redHoursLeft) {
            $level = self::LEVEL_RED;
        } elseif ($hoursLeft <= $yellowHoursLeft) {
            $level = self::LEVEL_YELLOW;
        } else {
            $level = self::LEVEL_GREEN;
        }

        return new self($level, true, $windowOpen, $windowExpiresAt, $hoursLeft);
    }
}
