<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Vorfilter der Absage-Erkennung (Spec 2026-10-08, Entscheidung 5): erkennt
 * offensichtliche Zusagen/Quittungen OHNE Sprachmodell.
 *
 * Bewusst konservativ: eine Nachricht gilt nur dann als Quittung, wenn JEDES
 * Wort aus dem Zusage-Wortschatz stammt. Ein einziges fremdes Wort ("nicht",
 * "krank", "leider") schickt sie ans Modell — lieber einmal zu viel pruefen
 * als eine Absage verschlucken.
 */
final class DispoDeclinePrefilter
{
    /** Laengere Nachrichten sind nie "offensichtlich". */
    private const MAX_LENGTH = 60;

    private const WORDS = [
        'ja', 'jo', 'jap', 'jup', 'yes', 'jaa', 'jaaa', 'ok', 'okay', 'oki', 'okey', 'okee',
        'passt', 'super', 'top', 'perfekt', 'prima', 'klasse', 'cool', 'gut', 'alles', 'klar',
        'danke', 'dankeschön', 'dankeschoen', 'vielen', 'dank', 'lieben', 'dir', 'euch', 'ihnen', 'schön', 'schoen', 'sehr',
        'gerne', 'gern', 'bin', 'ich', 'dabei', 'geht', 'bestätigt', 'bestaetigt', 'erledigt',
        'mach', 'wird', 'gemacht', 'komme', 'komm', 'bestätige', 'bestaetige', 'bestätigen', 'bestaetigen', 'zugesagt',
        'bis', 'dann', 'wir', 'sehen', 'uns', 'und', 'auch', 'ebenso', 'gleichfalls', 'lg', 'vg', 'mfg', 'gruß', 'gruss', 'grüße', 'gruesse',
    ];

    /** Zeichen, die als positive Quittung zaehlen (Daumen, Haken, Herzen, Haende). */
    private const POSITIVE_SYMBOLS = '/^[\x{1F44D}\x{1F44C}\x{2705}\x{2714}\x{2764}\x{1F64F}\x{1F60A}\x{1F642}\x{1F600}\x{1F601}\x{1F44F}\x{1F4AA}\x{FE0F}\x{1F3FB}-\x{1F3FF}\s]+$/u';

    public static function isObviousAck(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return false;
        }

        if (preg_match(self::POSITIVE_SYMBOLS, $text) === 1) {
            return true;
        }

        // Positive Symbole am Rand ("Passt 👍") stoeren nicht; alles andere
        // Nicht-Buchstaben-Zeug wird Trenner.
        $stripped = preg_replace('/[\x{1F44D}\x{1F44C}\x{2705}\x{2714}\x{2764}\x{1F64F}\x{1F60A}\x{1F642}\x{1F600}\x{1F601}\x{1F44F}\x{1F4AA}\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u', ' ', $text) ?? $text;
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($stripped), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return false; // nur fremde Symbole (z. B. 😢) — das Modell entscheidet
        }

        foreach ($words as $word) {
            if (!in_array($word, self::WORDS, true)) {
                return false;
            }
        }

        return true;
    }
}
