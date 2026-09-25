<?php

namespace Platform\Recruiting\Support;

/**
 * "Tage erlaubt" — der Startwert, ab dem ZAS das Tageskonto herunterzaehlt
 * (Markus 24.09.2026: 20 bereits gearbeitet, Grenze 70, also 50).
 *
 * Grundlage ist die §15-Erklaerung aus dem Arbeitsvertrag, die bei jedem
 * Arbeitsvertrag (Praefix AV-) ohnehin eingesammelt wird und bisher
 * ausschliesslich im Vertrags-PDF landete.
 *
 * WAS AUF DEM SPIEL STEHT: Eine zu hohe Zahl heisst, dass jemand mehr Tage
 * arbeitet als erlaubt — die kurzfristige Beschaeftigung verliert dann
 * rueckwirkend ihren Status, mit Nachzahlungen. Deshalb ist diese Klasse
 * durchgehend streng: im Zweifel KEIN Wert statt eines geratenen. Ein leeres
 * Feld faellt in der Akte auf, eine falsche Zahl nicht.
 *
 * DREIWERTIG: null heisst "keine Grundlage", 0 heisst "Grenze
 * ausgeschoepft". Ein Vertrag ohne §15-Erklaerung darf nicht als "nichts
 * gearbeitet, also volle Grenze" durchgehen.
 *
 * NUR DAS LAUFENDE KALENDERJAHR. Die Frage im Vertrag lautet "in den letzten
 * 12 Monaten", die gesetzliche Grenze gilt aber pro Kalenderjahr (§8 Abs. 1
 * Nr. 2 SGB IV). Das 12-Monats-Fenster enthaelt das laufende Kalenderjahr
 * IMMER vollstaendig — wir koennen also filtern, statt den Vertragstext zu
 * aendern. Ohne den Filter waere eine Unterschrift im Februar mit erklaerten
 * Tagen aus dem Vorjahr falsch: die zaehlen auf das alte Kontingent.
 *
 * ROLLENVERTEILUNG: Das ist der EINZIGE Wert dieses Kreislaufs, den wir
 * besitzen — und nur einmal, beim Start. Danach fuehrt ZAS das Konto und
 * liefert uns den Stand zurueck (Entscheidung 25.09.2026: "ZAS hat die
 * Hoheit ueber die Tage, weil da eingebucht wird").
 *
 * Reine Logik (kein Framework/DB) -> pure-unit-testbar.
 */
final class ShortTermDayBudget
{
    /**
     * @param  array $preSigningData rec_contracts.pre_signing_data
     * @param  int   $limit          Gesamtgrenze, aus den Team-Einstellungen
     * @param  ?\DateTimeInterface $asOf Bezugspunkt fuer das Kalenderjahr
     * @return int|null              Startwert, oder null wenn es keine Grundlage gibt
     */
    public static function allowedFrom(array $preSigningData, int $limit, ?\DateTimeInterface $asOf = null): ?int
    {
        // Eine unsinnige Grenze darf keinen Startwert erzeugen. Bei 0 bekaeme
        // sonst jeder neue Mitarbeiter "Grenze ausgeschoepft" — geschrieben,
        // nicht ausgelassen.
        if ($limit <= 0) {
            return null;
        }

        if (!array_key_exists('par15_has_previous', $preSigningData)) {
            return null;
        }

        $antwort = self::antwort($preSigningData['par15_has_previous']);
        if ($antwort === null) {
            // Schluessel da, aber nichts Eindeutiges. Keine Erklaerung.
            return null;
        }
        if ($antwort === false) {
            // Ausdrueckliches "nein" — echte Null, volle Grenze.
            return $limit;
        }

        $entries = $preSigningData['par15_entries'] ?? null;
        if (!is_array($entries) || $entries === []) {
            // "Ja" ohne Zeilen ist unschluessig; die Maske verlangt
            // mindestens einen Eintrag.
            return null;
        }

        $jahr = self::yearOf($asOf);
        $sum  = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return null;
            }

            $tage = self::tage($entry['tage'] ?? null);
            if ($tage === null) {
                // Eine Zeile, deren Tageszahl wir nicht verlaesslich lesen
                // koennen, entwertet die ganze Erklaerung. Sie einfach mit 0
                // mitzuzaehlen hiesse, aus "20 Tage weg" ein "volle Grenze
                // frei" zu machen — eine geratene Zahl an genau der Stelle,
                // ab der ZAS herunterzaehlt.
                return null;
            }

            if (!self::faelltInsJahr($entry, $jahr)) {
                continue;
            }

            $sum += $tage;
        }

        return max(0, $limit - $sum);
    }

    /** Kalenderjahr des Bezugspunkts. */
    public static function yearOf(?\DateTimeInterface $asOf = null): int
    {
        return (int) ($asOf ?? new \DateTimeImmutable())->format('Y');
    }

    /**
     * Ist der gespeicherte Startwert noch der des laufenden Jahres?
     *
     * Das Kontingent gilt je Kalenderjahr, der Startwert ist deshalb
     * jahresgebunden. Ein Waechter "nur wenn leer" waere INNERHALB eines
     * Jahres richtig und ueber den Jahreswechsel falsch: Unterschreibt
     * jemand im neuen Jahr einen neuen Arbeitsvertrag und erklaert dabei
     * bereits geleistete Tage, muss neu gerechnet werden.
     */
    public static function isCurrentYear(?int $storedYear, ?\DateTimeInterface $asOf = null): bool
    {
        return $storedYear !== null
            && $storedYear > 0
            && $storedYear === self::yearOf($asOf);
    }

    /** true / false / null (= keine eindeutige Antwort). */
    private static function antwort(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === 1 || $raw === '1') {
            return true;
        }
        if ($raw === 0 || $raw === '0') {
            return false;
        }

        return null;
    }

    /**
     * Verlaessliche Tageszahl oder null. Erlaubt ist genau, was die Maske
     * zulaesst: eine ganze Zahl ab 1. Alles andere — Kommazahlen, "+20",
     * "7,5", Wahrheitswerte, Arrays — ist keine Grundlage.
     */
    private static function tage(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 1 ? $raw : null;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            $value = (int) $raw;

            return $value >= 1 ? $value : null;
        }

        return null;
    }

    /**
     * Beruehrt der Eintrag das laufende Kalenderjahr?
     *
     * Ein Eintrag, der VOR dem Jahreswechsel endet, zaehlt auf das alte
     * Kontingent und wird uebersprungen. Ein Eintrag ueber den Jahreswechsel
     * laesst sich nicht aufteilen — die Tageszahl gilt fuer den ganzen
     * Zeitraum — und wird deshalb VOLL mitgezaehlt: zu wenig erlaubte Tage
     * ist eine Unbequemlichkeit, zu viele kosten den Status.
     *
     * Ohne lesbares Datum wird aus demselben Grund mitgezaehlt.
     */
    private static function faelltInsJahr(array $entry, int $jahr): bool
    {
        $ende = self::datum($entry['ende'] ?? null) ?? self::datum($entry['beginn'] ?? null);
        if ($ende === null) {
            return true;
        }

        return (int) $ende->format('Y') >= $jahr;
    }

    private static function datum(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($raw));
        } catch (\Throwable) {
            return null;
        }
    }
}
