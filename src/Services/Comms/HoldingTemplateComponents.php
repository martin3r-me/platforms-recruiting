<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Reiner Builder für die Meta-„components" eines einfachen Holding-/Re-Open-
 * Templates: füllt Body-Variablen mit dem Vornamen (name/vorname/{{1}}) bzw.
 * dem Template-Beispiel. Quick-Reply-Buttons brauchen keine Parameter und
 * werden ignoriert.
 *
 * Dependency-frei → unit-testbar (Modul-Test-Konvention).
 */
final class HoldingTemplateComponents
{
    /**
     * @param array $templateComponents Meta-Template-Komponenten (JSON-decodiert)
     * @return array<int, array{type: string, parameters: array}>
     */
    public static function build(array $templateComponents, string $firstName): array
    {
        $bodyParams = [];

        foreach ($templateComponents as $component) {
            if (($component['type'] ?? '') !== 'BODY') {
                continue;
            }

            $text = $component['text'] ?? '';
            $examplesByName = [];
            foreach ($component['example']['body_text_named_params'] ?? [] as $np) {
                $examplesByName[$np['param_name']] = $np['example'] ?? '';
            }
            $positionalExamples = $component['example']['body_text'][0] ?? [];

            preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);
            foreach ($matches[1] as $i => $paramName) {
                $isNameVar = in_array(strtolower($paramName), ['name', 'vorname', '1'], true);
                $example = $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '';

                $value = $isNameVar ? $firstName : ($example !== '' ? $example : $firstName);

                $entry = ['type' => 'text', 'text' => (string) $value];
                if (!is_numeric($paramName)) {
                    $entry['parameter_name'] = $paramName;
                }
                $bodyParams[] = $entry;
            }
        }

        if ($bodyParams === []) {
            return [];
        }

        return [['type' => 'body', 'parameters' => $bodyParams]];
    }

    /**
     * Prüft, ob die gebauten Komponenten einen leeren Text-Parameter enthalten.
     * WhatsApp/Meta lehnt leere Pflicht-Parameter ab (Fehler 131008) — solche
     * Sends sollten übersprungen statt fehlgeschlagen werden.
     *
     * @param array<int, array{type: string, parameters: array}> $components
     */
    public static function hasEmptyRequiredParam(array $components): bool
    {
        foreach ($components as $component) {
            foreach ($component['parameters'] ?? [] as $param) {
                if (($param['type'] ?? '') === 'text' && trim((string) ($param['text'] ?? '')) === '') {
                    return true;
                }
            }
        }

        return false;
    }
}
