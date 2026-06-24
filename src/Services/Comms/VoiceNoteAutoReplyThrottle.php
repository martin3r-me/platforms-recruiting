<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Reine Drossel-Entscheidung für die Sprachnachricht-Auto-Antwort: pro
 * Konversation höchstens einmal innerhalb des Fensters (Default 24h).
 *
 * Dependency-frei (Unix-Timestamps) → unit-testbar (Modul-Test-Konvention).
 */
final class VoiceNoteAutoReplyThrottle
{
    /**
     * @param ?int $lastSentAt Unix-TS der letzten bereits gesendeten Auto-Antwort (null = nie)
     * @param int  $now        Unix-TS "jetzt"
     * @param int  $windowHours Drossel-Fenster in Stunden
     */
    public static function shouldSkip(?int $lastSentAt, int $now, int $windowHours = 24): bool
    {
        if ($lastSentAt === null) {
            return false;
        }

        return $lastSentAt > $now - $windowHours * 3600;
    }
}
