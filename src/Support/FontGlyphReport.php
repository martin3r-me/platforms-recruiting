<?php

namespace Platform\Recruiting\Support;

/**
 * Ergebnis einer Glyph-Pruefung — drei Zustaende, klar getrennt:
 *
 *   nichts fehlt      checkable = true,  missing = []
 *   Zeichen fehlen    checkable = true,  missing = ['★', ...]
 *   nicht pruefbar    checkable = false, missing = []
 *
 * Warum kein nullbares Array (`?array`, null = nicht pruefbar)? Weil
 * `if (empty($x))` und `if (!$x)` null und [] gleich behandeln: ein Aufrufer,
 * der `if ($missing) { warnen }` schreibt, fuehrt "nicht pruefbar" still als
 * "alles in Ordnung" — genau der Fehler, den diese Klasse verhindern soll.
 * hasWarning() ist der Mechanismus dagegen: EIN Aufruf, der in BEIDEN
 * Problemzustaenden true ist. Wer die Unterscheidung fuer den Meldungstext
 * braucht, liest checkable und missing.
 *
 * Der dritte Zustand ist eine Warnung, kein Gate: nichts hier wirft.
 *
 * Keine Abhaengigkeiten — auch nicht auf Laravel oder FontLib.
 */
final class FontGlyphReport
{
    /** @param list<string> $missing */
    private function __construct(
        public readonly bool $checkable,
        public readonly array $missing,
    ) {
    }

    /** Schrift fehlt, ist unlesbar oder nicht parsbar — es wurde nichts geprueft. */
    public static function notCheckable(): self
    {
        return new self(false, []);
    }

    /** @param list<string> $missing fehlende Zeichen, moeglicherweise keine */
    public static function checked(array $missing): self
    {
        return new self(true, array_values($missing));
    }

    /** true, wenn es etwas zu melden gibt: fehlende Zeichen ODER nicht pruefbar. */
    public function hasWarning(): bool
    {
        return !$this->checkable || $this->missing !== [];
    }
}
