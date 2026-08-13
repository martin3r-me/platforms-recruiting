<?php

namespace Platform\Recruiting\Support;

/**
 * Liest die Body-Variablen eines Meta-Templates — also die Frage „kann dieses
 * Template den Wert ueberhaupt tragen, den ich mitgeben will?".
 *
 * WOZU: HoldingTemplateComponents::build() fuellt eine Variable, die NICHT in
 * $namedValues vorkommt, mit dem BEISPIELTEXT des Templates (`:45`). Wer einen
 * Link als Body-Variable mitgibt und ein Template erwischt, das diese Variable
 * nicht hat, verschickt also eine Nachricht mit dem Beispieltext statt dem Link
 * — erfolgreich, ohne Fehler, ohne Logzeile. Bei einem Zertifikat-Link geht die
 * an einen abgelehnten Bewerber. Deshalb wird vorher gefragt.
 *
 * REGEX UND FILTER SIND ABSICHTLICH IDENTISCH zu
 * HoldingTemplateComponents::build(): dieselbe `/\{\{(\w+)\}\}/`, derselbe
 * BODY-Filter. Wer eine der beiden Stellen aendert, muss die andere mitaendern
 * — WhatsAppTemplateBodyVariablesTest prueft die Faelle gegen BEIDE Klassen und
 * wird rot, wenn sie auseinanderlaufen. Zusammenlegen waere der naheliegende
 * Reflex und faellt aus, weil build() im Sendepfad von Holding, Auto-Reply und
 * Voice-Note-Antworten liegt: eine Leseoperation gehoert dort nicht hinein.
 *
 * Dependency-frei -> unit-testbar (Modul-Test-Konvention).
 */
final class WhatsAppTemplateBodyVariables
{
    /**
     * Alle Variablennamen aus allen BODY-Komponenten, in Vorkommens-Reihenfolge.
     *
     * @param  array<int, mixed>|null  $templateComponents  Meta-Komponenten (JSON-decodiert)
     * @return list<string>
     */
    public static function names(?array $templateComponents): array
    {
        $names = [];

        foreach ($templateComponents ?? [] as $component) {
            if (!is_array($component) || ($component['type'] ?? '') !== 'BODY') {
                continue;
            }

            preg_match_all('/\{\{(\w+)\}\}/', (string) ($component['text'] ?? ''), $matches);
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Traegt dieses Template die Variable — exakt so geschrieben?
     *
     * EXAKT, nicht case-insensitive: build() vergleicht mit array_key_exists
     * auf $namedValues (`:42`). Ein tolerantes has() wuerde „passt" sagen und
     * der Wert kaeme trotzdem nicht an.
     *
     * @param  array<int, mixed>|null  $templateComponents
     */
    public static function has(?array $templateComponents, string $variable): bool
    {
        return in_array($variable, self::names($templateComponents), true);
    }
}
