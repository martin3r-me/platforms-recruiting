<?php

namespace Platform\Recruiting\Support;

/**
 * R28 — bedingte Pflicht (required_if) als reine, geteilte Regel.
 *
 * Vorher stand das nur in RecEmployee::fieldIsRelevant() und wurde ueber
 * $this->getAttribute() ausgewertet. Task 5 (Vollstaendigkeitsring) braucht
 * dieselbe Regel ohne Modellinstanz — deshalb geloest in reine Logik, kein
 * Framework, keine DB.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PortalFieldRelevance
{
    /**
     * R28 — bedingte Pflicht, strikter Vergleich gegen den DATENSATZ.
     *
     * Bewusst nicht gegen den Formularwert: die Pflicht beschreibt den
     * gespeicherten Zustand, nicht die gerade getippte Absicht.
     *
     * Strikter Vergleich (!==), bewusst: is_first_aider und Co sind boolean
     * gecastet und dreiwertig (true/false/null). Ein lockerer Vergleich
     * wuerde "unbeantwortet" (null) mit "Nein" (false) verwechseln — dann
     * haette jeder Nicht-Ersthelfer dauerhaft zwei rote Felder (E9).
     *
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $datensatz gecastete Attributwerte
     */
    public static function istRelevant(array $meta, array $datensatz): bool
    {
        foreach (($meta['required_if'] ?? []) as $feld => $erwartet) {
            if (($datensatz[$feld] ?? null) !== $erwartet) {
                return false;
            }
        }

        return true;
    }
}
