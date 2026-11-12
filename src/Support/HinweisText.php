<?php

namespace Platform\Recruiting\Support;

/**
 * Hinweis-Text (individual_note) fuer die Einsatz-Seite: reiner Text, aber
 * Links werden anklickbar (Markus 12.11.: Ablauf mit Ticket und VIDEO zur
 * Messe — ein Video laesst sich nicht hochladen, der Link im Hinweis war nur
 * abtippbar).
 *
 * Bewusst KEIN Markdown: der Hinweis ist Freitext aus dem Dispo-Alltag
 * („*bitte* 15 Min frueher“), Sternchen und Unterstriche bleiben, wie getippt.
 * Sicherheit: der Rohtext wird an den Adressen geteilt, JEDES Stueck einzeln
 * escapt (Text wie Adresse) — rohes HTML kommt nie durch, und eine Adresse
 * endet an Leerraum, < > und Anfuehrungszeichen.
 * Zeilenumbrueche bleiben Text (die Seite rendert mit white-space:pre-line).
 */
final class HinweisText
{
    private const LINK = '~\b(?:https?://|www\.)[^\s<>"\']+~iu';

    /** Satzzeichen am Ende gehoeren meist zum Satz, nicht zur Adresse. */
    private const ENDE = '.,;:!?)]}';

    public static function html(?string $text): string
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return '';
        }

        $out = '';
        $pos = 0;
        preg_match_all(self::LINK, $text, $treffer, PREG_OFFSET_CAPTURE);
        foreach ($treffer[0] as [$roh, $offset]) {
            [$adresse, $rest] = self::kappen($roh);
            $out .= e(substr($text, $pos, $offset - $pos));
            if ($adresse === '' || preg_match('~^(?:https?://|www\.)$~i', $adresse)) {
                $out .= e($roh);
            } else {
                $href = str_starts_with(strtolower($adresse), 'www.') ? 'https://' . $adresse : $adresse;
                $out .= '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer" class="hint-link">' . e($adresse) . '</a>' . e($rest);
            }
            $pos = $offset + strlen($roh);
        }

        return $out . e(substr($text, $pos));
    }

    /** @return array{0:string,1:string} Adresse ohne Satzzeichen am Ende, abgeschnittener Rest */
    private static function kappen(string $roh): array
    {
        $rest = '';
        while ($roh !== '' && str_contains(self::ENDE, substr($roh, -1))) {
            // Schliessende Klammer gehoert zur Adresse, wenn sie eine oeffnende schliesst (Wikipedia-Links).
            if (substr($roh, -1) === ')' && substr_count($roh, '(') >= substr_count($roh, ')')) {
                break;
            }
            $rest = substr($roh, -1) . $rest;
            $roh = substr($roh, 0, -1);
        }

        return [$roh, $rest];
    }
}
