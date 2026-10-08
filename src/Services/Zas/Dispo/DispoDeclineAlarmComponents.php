<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Parameter der Absage-Alarm-Vorlage (Spec 2026-10-08, Entscheidung 9).
 *
 * Vertrag: bis zu drei Platzhalter im Text, in Reihenfolge Name,
 * Veranstaltung, Datum(e). Positionale ({{1}}) und benannte ({{name}})
 * Platzhalter werden beide bedient. Ein URL-Knopf bekommt die
 * Veranstaltungs-id NUR, wenn seine URL einen Platzhalter traegt — Meta lehnt
 * Parameter fuer statische Knoepfe ab.
 */
final class DispoDeclineAlarmComponents
{
    /**
     * @param mixed $definition components der Vorlage (Array oder JSON)
     * @return list<array<string,mixed>>|null null = Vorlage passt nicht (mehr als drei Werte oder Platzhalter im Kopf)
     */
    public static function build(string $name, string $eventName, string $dates, int $eventId, mixed $definition): ?array
    {
        $components = self::toList($definition);
        $values = [$name, $eventName, $dates];

        $placeholders = [];
        foreach ($components as $component) {
            // Platzhalter in der Kopfzeile bedienen wir nicht — Meta wuerde den
            // Versand ohne Kopf-Parameter ablehnen; lieber gar kein Alarm (geloggt).
            if (strtoupper((string) ($component['type'] ?? '')) === 'HEADER' && str_contains((string) ($component['text'] ?? ''), '{{')) {
                return null;
            }
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                preg_match_all('/\{\{\s*([^}\s]+)\s*\}\}/', (string) ($component['text'] ?? ''), $m);
                $placeholders = array_values(array_unique($m[1]));
            }
        }
        if (count($placeholders) > count($values)) {
            return null;
        }
        // Positionale Platzhalter tragen ihre Stelle selbst ({{2}} vor {{1}}
        // im Text bleibt Veranstaltung) — Meta erwartet sie aufsteigend.
        $positional = $placeholders !== [] && array_filter($placeholders, 'ctype_digit') === $placeholders;
        if ($positional) {
            sort($placeholders, SORT_NUMERIC);
            if ((int) end($placeholders) > count($values)) {
                return null;
            }
        }

        $out = [];
        if ($placeholders !== []) {
            $params = [];
            foreach ($placeholders as $i => $placeholder) {
                $value = $positional ? $values[(int) $placeholder - 1] : $values[$i];
                $entry = ['type' => 'text', 'text' => $value !== '' ? $value : '-'];
                if (!ctype_digit($placeholder)) {
                    $entry['parameter_name'] = $placeholder;
                }
                $params[] = $entry;
            }
            $out[] = ['type' => 'body', 'parameters' => $params];
        }

        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }
            foreach (self::toList($component['buttons'] ?? []) as $index => $button) {
                if (strtoupper((string) ($button['type'] ?? '')) === 'URL' && str_contains((string) ($button['url'] ?? ''), '{{')) {
                    $out[] = [
                        'type'       => 'button',
                        'sub_type'   => 'url',
                        'index'      => $index,
                        'parameters' => [['type' => 'text', 'text' => (string) $eventId]],
                    ];
                }
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function toList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
