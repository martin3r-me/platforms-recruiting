<?php

namespace Platform\Recruiting\Support;

/**
 * Formatiert Lookup-Feldwerte zu ihren menschenlesbaren Labels.
 *
 * Lookup-Extrafelder speichern den Maschinenwert ("tr"), Dokumente und
 * Exporte brauchen das Label ("Türkei"). Diese Klasse macht nur die
 * Formatierung — das Laden der value=>label-Map bleibt beim Aufrufer
 * (siehe ZasLookupResolver). Bewusst ohne Framework-Import, damit sie
 * im Unit-Test ohne Laravel-Bootstrap ladbar ist.
 */
final class LookupLabelFormatter
{
    /**
     * @param  mixed  $value     Roher Feldwert (String, Zahl oder Array bei Multi-Select)
     * @param  array<string, string>  $labelMap  value => label
     * @return string|null  Klartext, oder null wenn nichts aufzulösen war
     */
    public static function format(mixed $value, array $labelMap): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $labels = [];
            foreach ($value as $entry) {
                $label = self::format($entry, $labelMap);
                if ($label !== null && $label !== '') {
                    $labels[] = $label;
                }
            }

            return $labels === [] ? null : implode(', ', $labels);
        }

        $stringValue = (string) $value;

        return $labelMap[$stringValue] ?? $stringValue;
    }
}
