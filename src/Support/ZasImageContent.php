<?php

namespace Platform\Recruiting\Support;

/**
 * Inhaltspruefung fuer Bilder, die ZAS an den Datei-Eingang schickt.
 *
 * Entschieden wird an der Datei-SIGNATUR, nie an der Endung: in der
 * Testlieferung vom 03.09. stand `PlanHalle18.jpg` im Selfie-Feld — ein
 * Hallenplan. Eine Endungspruefung haette ihn als Gesicht ins Crew-Kaertchen
 * gelassen, wo ein Teamleiter eine Person daran erkennen soll.
 *
 * Reine Logik (kein Framework, kein Storage) → pure-unit-testbar.
 */
final class ZasImageContent
{
    /**
     * Obergrenze pro Datei. Selfies liegen bei 50-200 KB; 10 MB laesst
     * unkomprimierte Handy-Aufnahmen durch und deckelt trotzdem. Gleiche
     * Grenze wie bei den Dispo-Anhaengen.
     */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Angenommene Formate. GIF und SVG sind absichtlich NICHT dabei: als
     * Personenfoto sinnlos, und SVG kann Skripte tragen.
     */
    private const ALLOWED = ['image/jpeg', 'image/png'];

    /**
     * Erkannter MIME-Typ, sofern es ein akzeptiertes Bild ist — sonst null.
     *
     * Das @ ist beabsichtigt: getimagesizefromstring warnt bei Muell, und die
     * Suite laeuft mit failOnWarning. Der Rueckgabewert traegt die Information.
     */
    public static function mimeOf(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        $mime = (string) ($info['mime'] ?? '');

        return in_array($mime, self::ALLOWED, true) ? $mime : null;
    }

    public static function exceedsLimit(string $bytes): bool
    {
        return strlen($bytes) > self::MAX_BYTES;
    }
}
