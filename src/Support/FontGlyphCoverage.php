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
     * Prueft den Text gegen die Schrift und liefert einen Bericht mit drei
     * moeglichen Zustaenden: nichts fehlt / diese Zeichen fehlen / nicht
     * pruefbar. Wirft nie — die Pruefung ist eine Hilfe, kein Gate.
     *
     * Es gibt absichtlich KEINE Methode, die nur die fehlenden Zeichen als
     * Array liefert: ihr leeres Array bedeutete "nichts fehlt" UND "Schrift
     * nicht pruefbar", und eine kaputte Schrift bekaeme damit ein besseres
     * Zeugnis als eine intakte.
     */
    public static function inspect(string $content, string $fontPath): FontGlyphReport
    {
        $text = self::plainText($content);
        if ($text === '') {
            // Kein Text, also kann kein Zeichen fehlen. Die Schrift wird dafuer
            // nicht gebraucht und deshalb auch nicht bewertet.
            return FontGlyphReport::checked([]);
        }

        $map = self::charMap($fontPath);
        if ($map === null) {
            // Nicht lesbare Fontdatei blockiert nicht, wird aber auch nicht
            // als "nichts fehlt" verkauft: der Aufrufer erfaehrt, dass hier
            // nichts geprueft wurde. Das fehlende Asset faellt beim Rendern auf.
            return FontGlyphReport::notCheckable();
        }

        $missing = [];
        foreach (self::codepoints($text) as $codepoint => $char) {
            if (!isset($map[$codepoint])) {
                $missing[$codepoint] = $char;
            }
        }

        return FontGlyphReport::checked(array_values($missing));
    }

    /** HTML-Markup entfernen — im PDF steht nur der Textinhalt. */
    private static function plainText(string $content): string
    {
        $withoutTags = strip_tags($content);
        $decoded = html_entity_decode($withoutTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($decoded);
    }

    /**
     * @return array<int,int>|null Unicode-Codepoint => Glyph-Index,
     *                             null = nicht pruefbar
     *
     * Gemessen an Oswald-SemiBold (109 120 B) ueber fuenf Beschaedigungsstufen:
     * bei 40 % und 5 % der Datei parst FontLib weiter und liefert dieselben 737
     * Einträge wie die intakte Datei (der cmap-Table liegt im erhaltenen Kopf);
     * bei 3 Byte und 0 Byte gibt Font::load() null zurueck, ohne Exception. Ein
     * LEERES, aber gueltiges Array kam auf keiner Stufe vor — deshalb steht hier
     * kein Sonderfall dafuer.
     */
    private static function charMap(string $fontPath): ?array
    {
        if (!is_file($fontPath) || !is_readable($fontPath)) {
            return null;
        }

        // Vorbelegt, damit der finally-Block auch dann gueltig ist, wenn
        // Font::load() selbst wirft (FontNotFoundException, wenn die Datei
        // zwischen der Pruefung oben und hier verschwindet) und die Variable
        // nie zugewiesen wurde.
        $font = null;

        try {
            $font = Font::load($fontPath);
            if ($font === null) {
                return null;
            }
            $font->parse();
            $map = $font->getUnicodeCharMap();
        } catch (\Throwable) {
            return null;
        } finally {
            // close() gibt das Dateihandle frei und gehoert deshalb in den
            // finally-Block: parse() und getUnicodeCharMap() werfen genau bei
            // der beschaedigten Schrift, fuer die diese Klasse gebaut ist —
            // im catch-Zweig zu schliessen hiesse, es dort gar nicht zu tun.
            // Gemessen ueber einen zaehlenden Stream-Wrapper: ein Handle pro
            // Fehlerpfad-Aufruf blieb offen (siehe FontGlyphCoverageTest).
            //
            // Der innere try/catch ist Pflicht, nicht Vorsicht: FontLibs
            // close() ruft fclose() auf einem Feld auf, das bei
            // fehlgeschlagenem fopen() false ist (Font::load() wertet den
            // Rueckgabewert von BinaryStream::load() nicht aus). Ohne ihn
            // verliesse dieser TypeError die Methode aus dem finally heraus,
            // am catch oben vorbei — und inspect() wuerde werfen, obwohl es
            // das laut Vertrag nie tut.
            try {
                $font?->close();
            } catch (\Throwable) {
                // Handle ist entweder zu oder war nie offen. Beides ist hier
                // das Ziel; der Bericht steht schon fest.
            }
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

        // Codepoint als Schluessel: Duplikate fallen weg, die Reihenfolge des
        // ersten Auftretens bleibt.
        $ordered = [];
        foreach ($out as [$codepoint, $char]) {
            $ordered[$codepoint] ??= $char;
        }

        return $ordered;
    }
}
