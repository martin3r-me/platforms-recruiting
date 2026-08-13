<?php

namespace Platform\Recruiting\Support;

/**
 * Liest die Buttons eines Meta-Templates — also die Frage „darf ich hier einen
 * URL-Parameter mitschicken, und an welcher Position sitzt der Button?".
 *
 * WOZU: der Sendepfad setzt den Button-Parameter auf index 0. Das ist an allen
 * sechs Sendestellen des Moduls hartkodiert (Spec H3), nicht ermittelt. Zwei
 * Faelle gehen davon still schief, und beide fuehren zu einer Nachricht ohne
 * brauchbaren Link an einen abgelehnten Bewerber:
 *
 *  1. Der dynamische URL-Button sitzt NICHT an Position 0 (z.B. Quick-Reply an
 *     0). Der Parameter landet dann an der falschen Komponente.
 *  2. Der URL-Button ist STATISCH — seine URL traegt keine Variable. Dann ist
 *     jeder Parameter einer zu viel.
 *
 * DAS KRITERIUM IST ABSICHTLICH DAS STRENGERE der beiden im Modul vorhandenen:
 * `type === 'URL'` UND `{{` in der URL, wie RecInterview.php:162 und
 * InterviewSchedule/Index.php:145. Die anderen fuenf Erkennungsstellen (Spec
 * H1) pruefen nur den Typ; ihre laschere Fassung wird hier NICHT kopiert, sie
 * ist der Defekt 2 aus Spec H4.
 *
 * POSITIONEN STATT bool, und das ist der Punkt: mit einem bool lautete die
 * Fehlermeldung „kein URL-Button gefunden" auch dann, wenn es einen gibt und er
 * nur an der falschen Stelle sitzt. Die richtige Anweisung ist dann „Button an
 * die erste Position verschieben" — die kann nur eine Positionsliste hergeben.
 *
 * Schwesterklasse fuer den Body: WhatsAppTemplateBodyVariables. Beide sind
 * dependency-frei -> unit-testbar (Modul-Test-Konvention).
 */
final class WhatsAppTemplateUrlButtons
{
    /**
     * Positionen der URL-Buttons MIT Variable, in Vorkommens-Reihenfolge.
     *
     * Gezaehlt wird ueber alle Buttons aller BUTTONS-Komponenten hinweg, weil
     * Meta sie so indiziert. Meta erlaubt heute nur eine BUTTONS-Komponente;
     * die Schleife ueber mehrere kostet nichts und macht die Zaehlung
     * unabhaengig von dieser Zusage.
     *
     * @param  array<int, mixed>|null  $templateComponents  Meta-Komponenten (JSON-decodiert)
     * @return list<int>
     */
    public static function dynamicIndexes(?array $templateComponents): array
    {
        $indexes = [];
        $position = 0;

        foreach ($templateComponents ?? [] as $component) {
            if (!is_array($component) || ($component['type'] ?? '') !== 'BUTTONS') {
                continue;
            }

            $buttons = $component['buttons'] ?? [];
            if (!is_array($buttons)) {
                continue;
            }

            foreach ($buttons as $button) {
                if (is_array($button) && self::istDynamisch($button)) {
                    $indexes[] = $position;
                }
                $position++;
            }
        }

        return $indexes;
    }

    /** Sitzt an genau dieser Position ein dynamischer URL-Button? */
    public static function hasDynamicAt(?array $templateComponents, int $index): bool
    {
        return in_array($index, self::dynamicIndexes($templateComponents), true);
    }

    /**
     * Je Button eine Klartextzeile mit Typ und Position — das Material fuer die
     * Fehlermeldung an HR.
     *
     * Der Text steht hier und nicht in der Meldung selbst, damit die Meldung
     * ohne Container testbar bleibt und beide Zweige (kein Button / falsche
     * Position) dieselbe Aufzaehlung benutzen.
     *
     * @param  array<int, mixed>|null  $templateComponents
     * @return list<string>
     */
    public static function describe(?array $templateComponents): array
    {
        $zeilen = [];
        $position = 0;

        foreach ($templateComponents ?? [] as $component) {
            if (!is_array($component) || ($component['type'] ?? '') !== 'BUTTONS') {
                continue;
            }

            $buttons = $component['buttons'] ?? [];
            if (!is_array($buttons)) {
                continue;
            }

            foreach ($buttons as $button) {
                $typ = is_array($button) ? (string) ($button['type'] ?? '?') : '?';

                if ($typ === 'URL') {
                    $typ .= self::istDynamisch(is_array($button) ? $button : [])
                        ? ' mit Variable'
                        : ' ohne Variable';
                }

                $zeilen[] = sprintf('Position %d: %s', $position, $typ);
                $position++;
            }
        }

        return $zeilen;
    }

    /**
     * URL-Button mit Variable in der URL.
     *
     * `str_contains(…, '{{')` und nicht eine Regex auf {{1}}: Meta erlaubt in
     * der Button-URL genau einen Parameter und schreibt ihn als {{1}}, aber die
     * Schreibweise ist nicht unsere und ein toleranter Test ist hier der
     * sicherere — wir wollen wissen, OB die URL variabel ist, nicht wie sie
     * heisst.
     *
     * @param  array<string, mixed>  $button
     */
    private static function istDynamisch(array $button): bool
    {
        return ($button['type'] ?? '') === 'URL'
            && str_contains((string) ($button['url'] ?? ''), '{{');
    }
}
