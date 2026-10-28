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

    /**
     * Zweite Nachricht zur Lohn-Liste (28.10.2026, Review I2 Variante b): der
     * geparkte Chat ist fuer HR unsichtbar — wer eigentlich etwas anderes will,
     * kommt hierueber zur Dispo bzw. zu HR. Meta erlaubt Link- ODER
     * Antwortknoepfe je Nachricht, daher eine eigene Nachricht.
     */
    public static function andereThemen(bool $duzen): string
    {
        return $duzen
            ? 'Geht es um etwas anderes? Dann wähl hier aus.'
            : 'Geht es um etwas anderes? Dann wählen Sie hier aus.';
    }

    /** @return list<array{id:string, title:string}> */
    public static function andereKnoepfe(): array
    {
        return array_values(array_filter(self::knoepfe(), static fn (array $k) => $k['id'] !== 'topic_lohn'));
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

    /**
     * Akte vollstaendig, aber laut Agenda-Liste kein Zugang zum Lohnportal
     * (Markus 28.10.2026: Nachricht an HR „nur dann“). „Laut unseren
     * Unterlagen“, weil die Liste veralten kann — dafuer „Hab ich schon“.
     */
    public static function agendaFrage(bool $duzen): string
    {
        return $duzen
            ? 'Deine Abrechnungen findest du im Agenda-Lohnportal. Laut unseren Unterlagen hast du dort noch keinen Zugang. Sollen wir dir die Zugangsdaten schicken?'
            : 'Ihre Abrechnungen finden Sie im Agenda-Lohnportal. Laut unseren Unterlagen haben Sie dort noch keinen Zugang. Sollen wir Ihnen die Zugangsdaten schicken?';
    }

    /** @return list<array{id:string, title:string}> */
    public static function agendaKnoepfe(): array
    {
        return [
            ['id' => 'topic_agenda_anfordern', 'title' => 'Zugang anfordern'],
            ['id' => 'topic_agenda_ok',        'title' => 'Hab ich schon'],
        ];
    }

    /** Pruefschritt nach der Anleitung (Markus 28.10.2026): erst danach sieht HR den Lohn-Chat. */
    public static function lohnPruefen(bool $duzen): string
    {
        return $duzen
            ? 'Hast du deine Stundenübersicht und Abrechnung geprüft und es passt trotzdem etwas nicht?'
            : 'Haben Sie Ihre Stundenübersicht und Abrechnung geprüft und es passt trotzdem etwas nicht?';
    }

    /** @return list<array{id:string, title:string}> */
    public static function pruefenKnoepfe(): array
    {
        return [
            ['id' => 'topic_lohn_frage',    'title' => 'Ich habe eine Frage'],
            ['id' => 'topic_lohn_erledigt', 'title' => 'Hat sich erledigt'],
            ['id' => 'topic_dispo',         'title' => 'Disposition'],
        ];
    }

    public static function lohnFrageOffen(bool $duzen): string
    {
        return $duzen
            ? 'Alles klar! Schreib uns genau, was nicht passt, z. B. Datum und fehlende Stunden.'
            : 'Alles klar! Schreiben Sie uns genau, was nicht passt, z. B. Datum und fehlende Stunden.';
    }

    public static function lohnErledigt(bool $duzen): string
    {
        return 'Super, danke!';
    }

    public static function agendaAngefordert(bool $duzen): string
    {
        return $duzen
            ? 'Alles klar, wir schicken dir deine Zugangsdaten für das Agenda-Lohnportal.'
            : 'Alles klar, wir schicken Ihnen Ihre Zugangsdaten für das Agenda-Lohnportal.';
    }

    /** Akte nachgereicht, der geparkte Lohn-Chat geht an HR (Variante B, 26.10.2026). */
    public static function lohnFrei(bool $duzen, ?string $teamText): string
    {
        return self::oderVorgabe($teamText, $duzen
            ? 'Danke, deine Akte ist jetzt vollständig!'
            : 'Danke, Ihre Akte ist jetzt vollständig!');
    }

    /**
     * Knopf einer Filiale. Das Praefix „Dispo“ faellt weg — die Frage sagt
     * schon „Disposition“, und mit Praefix schnitt Meta „Dispo Düsseldorf & …“
     * ab (26.10.2026).
     */
    public static function filialKnopf(string $label): string
    {
        $label = self::ohneDispoPraefix($label);

        return mb_strlen($label) <= self::MAX_BUTTON ? $label : mb_substr($label, 0, self::MAX_BUTTON - 1) . '…';
    }

    /** Listenzeile einer Filiale (ab 4 Filialen), ebenfalls ohne „Dispo“. */
    public static function filialTitel(string $label): string
    {
        return self::listenTitel(self::ohneDispoPraefix($label));
    }

    private static function ohneDispoPraefix(string $label): string
    {
        $kurz = trim((string) preg_replace('/^\s*(Disposition|Dispo)\s+/iu', '', $label));

        return $kurz === '' ? trim($label) : $kurz;
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
