<?php

namespace Platform\Recruiting\Support;

use FontLib\Font;

/**
 * Welche Zeichen eines Textes fehlen in einer TTF-Datei?
 *
 * Hintergrund: DomPDF macht bei einer per @font-face eingebundenen Schrift
 * KEINEN Glyph-Fallback. Jedes Zeichen, das die Schrift nicht kennt, landet
 * als "?" im PDF — ohne Warnung. Gemessen an Oswald-SemiBold: ★ (U+2605)
 * fehlt, waehrend –, €, Ü, ä vorhanden sind.
 *
 * Geprueft wird am EINGANG, nicht am fertigen PDF: dort ist der Text
 * FlateDecode-komprimiert und UTF-16BE-kodiert (CID-Font, Identity-H), eine
 * Pruefung waere teuer und indirekt.
 *
 * Dependency: FontLib liegt als dompdf/php-font-lib immer dort, wo DomPDF
 * liegt — keine neue Abhaengigkeit.
 */
final class FontGlyphCoverage
{
    /**
     * @return list<string> fehlende Zeichen als UTF-8-Strings, dedupliziert,
     *                      in Reihenfolge des ersten Auftretens
     */
    public static function missing(string $content, string $fontPath): array
    {
        $text = self::plainText($content);
        if ($text === '') {
            return [];
        }

        $map = self::charMap($fontPath);
        if ($map === null) {
            // Nicht lesbare Fontdatei blockiert die Pruefung nicht — sie ist
            // eine Hilfe, kein Gate. Das fehlende Asset faellt beim Rendern auf.
            return [];
        }

        $missing = [];
        foreach (self::codepoints($text) as $codepoint => $char) {
            if (!isset($map[$codepoint]) && !isset($missing[$codepoint])) {
                $missing[$codepoint] = $char;
            }
        }

        return array_values($missing);
    }

    /** HTML-Markup entfernen — im PDF steht nur der Textinhalt. */
    private static function plainText(string $content): string
    {
        $withoutTags = strip_tags($content);
        $decoded = html_entity_decode($withoutTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($decoded);
    }

    /** @return array<int,int>|null Unicode-Codepoint => Glyph-Index */
    private static function charMap(string $fontPath): ?array
    {
        if (!is_file($fontPath) || !is_readable($fontPath)) {
            return null;
        }

        try {
            $font = Font::load($fontPath);
            if ($font === null) {
                return null;
            }
            $font->parse();
            $map = $font->getUnicodeCharMap();
            $font->close();
        } catch (\Throwable) {
            return null;
        }

        return is_array($map) ? $map : null;
    }

    /**
     * @return array<int,string> Codepoint => Zeichen, in Textreihenfolge.
     *         Whitespace wird uebersprungen (steht in jeder Schrift und
     *         wuerde nur Rauschen erzeugen).
     */
    private static function codepoints(string $text): array
    {
        $out = [];
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            if (trim($char) === '') {
                continue;
            }
            $codepoint = mb_ord($char, 'UTF-8');
            if ($codepoint === false) {
                continue;
            }
            $out[] = [$codepoint, $char];
        }

        // Reihenfolge des ersten Auftretens erhalten, Duplikate spaeter
        // in missing() gefiltert.
        $ordered = [];
        foreach ($out as [$codepoint, $char]) {
            $ordered[$codepoint] ??= $char;
        }

        return $ordered;
    }
}
