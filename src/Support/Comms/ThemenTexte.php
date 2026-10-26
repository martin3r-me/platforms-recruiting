<?php

namespace Platform\Recruiting\Support\Comms;

/**
 * Texte und Knoepfe der Themen-Auswahl (S-Themen §2.2–2.4). Vorgaben in du/Sie,
 * ein Team-Text ueberschreibt die Vorgabe 1:1. Meta-Grenzen stehen hier als
 * Konstanten und werden beim Bauen erzwungen — ein zu langer Titel liesse Meta
 * den ganzen Send ablehnen.
 */
final class ThemenTexte
{
    public const MAX_BUTTON = 20;
    public const MAX_LIST = 24;

    /** @return list<array{id:string,title:string}> */
    public static function knoepfe(): array
    {
        return [
            ['id' => 'topic_dispo', 'title' => 'Disposition'],
            ['id' => 'topic_lohn',  'title' => 'Lohn & Gehalt'],
            ['id' => 'topic_hr',    'title' => 'HR allgemein'],
        ];
    }

    public static function frage(bool $duzen, ?string $teamText, ?string $oooVorspann): string
    {
        $text = self::oderVorgabe($teamText, $duzen
            ? 'Danke für deine Nachricht! Worum geht es? Wähl unten aus, dann landet dein Anliegen gleich dort, wo du richtig bist.'
            : 'Danke für Ihre Nachricht! Worum geht es? Wählen Sie unten aus, dann landet Ihr Anliegen gleich an der richtigen Stelle.');
        $vorspann = trim((string) $oooVorspann);

        return $vorspann === '' ? $text : $vorspann . "\n\n" . $text;
    }

    public static function filialFrage(bool $duzen): string
    {
        return $duzen ? 'Welche Disposition ist für dich zuständig?' : 'Welche Disposition ist für Sie zuständig?';
    }

    public static function dispoLink(bool $duzen, string $label): string
    {
        return $duzen
            ? "Alles klar. Mit dem Knopf unten schreibst du der {$label} direkt — deine Nachricht ist schon vorbereitet, du musst nur noch senden."
            : "Alles klar. Mit dem Knopf unten schreiben Sie der {$label} direkt — Ihre Nachricht ist schon vorbereitet, Sie müssen nur noch senden.";
    }

    /** @param list<string> $punkte */
    public static function lohnOffen(bool $duzen, ?string $teamText, array $punkte): string
    {
        $liste = '• ' . implode("\n• ", $punkte);
        $text = self::oderVorgabe($teamText, $duzen
            ? "Damit deine Abrechnung stimmen kann, fehlt in deiner Akte noch:\n{PUNKTE}\nBitte reich das im Mitarbeiterportal nach. Danach schauen wir uns deine Frage an."
            : "Damit Ihre Abrechnung stimmen kann, fehlt in Ihrer Akte noch:\n{PUNKTE}\nBitte reichen Sie das im Mitarbeiterportal nach. Danach schauen wir uns Ihre Frage an.");

        return str_replace('{PUNKTE}', $liste, $text);
    }

    /**
     * Ohne hinterlegte Anleitung-URL kommt kein Link-Knopf (nur „Alles klar"),
     * also verspricht der Vorgabetext ihn dann auch nicht (26.10.2026).
     */
    public static function lohnOk(bool $duzen, ?string $teamText, bool $mitAnleitung = true): string
    {
        $knopf = $mitAnleitung
            ? ($duzen ? ' Wie du dich einloggst, zeigt der Knopf unten.' : ' Wie Sie sich einloggen, zeigt der Knopf unten.')
            : '';

        return self::oderVorgabe($teamText, ($duzen
            ? 'Hast du deine Stundenübersicht und Abrechnung schon kontrolliert? Dann kannst du uns gezielt sagen, was nicht passt.'
            : 'Haben Sie Ihre Stundenübersicht und Abrechnung schon kontrolliert? Dann können Sie uns gezielt sagen, was nicht passt.') . $knopf);
    }

    public static function filialKnopf(string $label): string
    {
        return mb_strlen($label) <= self::MAX_BUTTON ? $label : mb_substr($label, 0, self::MAX_BUTTON - 1) . '…';
    }

    /** Listenzeilen-Titel: Meta erlaubt 24 Zeichen, ein längerer wird auf 23 + „…“ gekürzt. */
    public static function listenTitel(string $titel): string
    {
        return mb_strlen($titel) <= self::MAX_LIST ? $titel : mb_substr($titel, 0, self::MAX_LIST - 1) . '…';
    }

    private static function oderVorgabe(?string $teamText, string $vorgabe): string
    {
        $t = trim((string) $teamText);

        return $t === '' ? $vorgabe : $t;
    }
}
