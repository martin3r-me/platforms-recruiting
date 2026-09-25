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
 * WARUM DREIWERTIG: null heisst "keine Grundlage", nicht "null Tage".
 * Ein Vertrag ohne §15-Erklaerung — Altvertrag, IFSG, Zusatzvertrag — darf
 * nicht als "nichts gearbeitet, also volle Grenze" durchgehen. Das waere
 * eine erfundene Zahl an einer Stelle, ab der ZAS herunterzaehlt. Wer
 * dagegen ausdruecklich "nein, ich war nicht kurzfristig beschaeftigt"
 * erklaert hat, liefert eine echte Null und bekommt die volle Grenze.
 *
 * ROLLENVERTEILUNG: Das ist der EINZIGE Wert dieses Kreislaufs, den wir
 * besitzen — und nur einmal, beim Start. Danach fuehrt ZAS das Konto und
 * liefert uns den Stand zurueck (Entscheidung 25.09.2026: "ZAS hat die
 * Hoheit ueber die Tage, weil da eingebucht wird").
 *
 * OFFENE ANNAHME: Die §15-Frage im Vertrag lautet heute "in den letzten 12
 * Monaten", Markus rechnet mit dem laufenden KALENDERJAHR. Solange der
 * Vertragstext nicht umgestellt ist, summiert diese Klasse, was erklaert
 * wurde — bei einem Eintrag ueber den Jahreswechsel kann das mehr sein, als
 * ins Kalenderjahr faellt. Die Umstellung ist eine Aenderung an einer
 * Erklaerung im Arbeitsvertrag und braucht Mitzeichnung.
 *
 * Reine Logik (kein Framework/DB) -> pure-unit-testbar.
 */
final class ShortTermDayBudget
{
    /**
     * @param  array $preSigningData rec_contracts.pre_signing_data
     * @param  int   $limit          Gesamtgrenze, aus den Team-Einstellungen
     * @return int|null              Startwert, oder null wenn es keine Grundlage gibt
     */
    public static function allowedFrom(array $preSigningData, int $limit): ?int
    {
        if (!array_key_exists('par15_has_previous', $preSigningData)) {
            return null;
        }

        $entries = $preSigningData['par15_entries'] ?? [];

        if (empty($preSigningData['par15_has_previous'])) {
            // Ausdrueckliches "nein" — echte Null, volle Grenze.
            return $limit;
        }

        if (!is_array($entries) || $entries === []) {
            // "Ja" ohne Zeilen ist unschluessig; die Maske verlangt mindestens
            // einen Eintrag. Lieber kein Wert als ein geratener.
            return null;
        }

        $sum = 0;
        foreach ($entries as $entry) {
            $raw = is_array($entry) ? ($entry['tage'] ?? null) : null;
            // Defensiv: die Daten stehen in einem JSON-Feld und koennen aus
            // einer Zeit stammen, in der die Maske anders validierte.
            if (is_int($raw) || (is_string($raw) && ctype_digit(trim($raw)))) {
                $sum += (int) $raw;
            }
        }

        // Nie negativ: eine Minuszahl koennte auf der Gegenseite als
        // Guthaben durchgehen.
        return max(0, $limit - $sum);
    }
}
