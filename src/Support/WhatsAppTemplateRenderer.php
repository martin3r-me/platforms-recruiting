<?php

namespace Platform\Recruiting\Support;

/**
 * Baut aus einer WhatsApp-Vorlage und den beim Versand mitgeschickten Werten
 * den Text, den der Mitarbeiter tatsaechlich gelesen hat (Kunde 23.09.: im
 * Chat stand bisher nur der technische Vorlagen-Name, z. B. "t_wo_bist").
 *
 * Rein rechnend, kein DB-Zugriff: Vorlagen-Definition und gesendete Werte
 * reicht der Aufrufer herein.
 *
 * Meta-Strukturen:
 *   Vorlage  components: [{type: HEADER, format: TEXT, text: "..."},
 *                         {type: BODY,   text: "Hallo {{1}}, am {{2}} ..."},
 *                         {type: FOOTER, text: "..."},
 *                         {type: BUTTONS, buttons: [{type: URL, text: "Einsatz ansehen"}]}]
 *   Versand  template_params: [{type: body, parameters: [{type: text, text: "Tristan"}, ...]},
 *                              {type: button, sub_type: url, index: 0, parameters: [...]}]
 */
final class WhatsAppTemplateRenderer
{
    /**
     * Der gelesene Text: Kopfzeile (nur Text-Header), Rumpf und Fusszeile, mit
     * eingesetzten Werten. Platzhalter ohne Wert bleiben stehen — lieber ein
     * sichtbares "{{3}}" als ein still verschluckter Teil.
     *
     * @param mixed $components  Vorlagen-Definition (integrations_whatsapp_templates.components)
     * @param mixed $sentParams  comms_whatsapp_messages.template_params
     */
    public static function render(mixed $components, mixed $sentParams): ?string
    {
        $parts = [];
        foreach (self::toList($components) as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            $text = trim((string) ($component['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if ($type === 'HEADER' && strtoupper((string) ($component['format'] ?? 'TEXT')) !== 'TEXT') {
                continue; // Bild-/Dokument-Header hat keinen lesbaren Text
            }
            if (!in_array($type, ['HEADER', 'BODY', 'FOOTER'], true)) {
                continue;
            }
            $parts[] = self::substitute($text, self::parametersFor($sentParams, $type));
        }

        $out = trim(implode("\n\n", $parts));

        return $out !== '' ? $out : null;
    }

    /**
     * Beschriftungen der Knoepfe — im Chat als eigene Zeile, damit erkennbar
     * bleibt, dass die Nachricht einen Link/Button trug.
     *
     * @return list<string>
     */
    public static function buttonLabels(mixed $components): array
    {
        $out = [];
        foreach (self::toList($components) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }
            foreach (self::toList($component['buttons'] ?? []) as $button) {
                $text = trim((string) ($button['text'] ?? ''));
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * Gesendete Werte eines Abschnitts (HEADER/BODY) in Reihenfolge.
     *
     * @return list<array<string, mixed>>
     */
    private static function parametersFor(mixed $sentParams, string $type): array
    {
        foreach (self::toList($sentParams) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === $type) {
                return self::toList($component['parameters'] ?? []);
            }
        }

        return [];
    }

    /** @param list<array<string, mixed>> $parameters */
    private static function substitute(string $text, array $parameters): string
    {
        $position = 0;
        foreach ($parameters as $parameter) {
            $position++;
            $value = (string) ($parameter['text'] ?? '');
            $named = trim((string) ($parameter['parameter_name'] ?? ''));
            $text = $named !== ''
                ? str_replace('{{' . $named . '}}', $value, $text)
                : str_replace('{{' . $position . '}}', $value, $text);
        }

        return $text;
    }

    /** @return list<array<string, mixed>> */
    private static function toList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
