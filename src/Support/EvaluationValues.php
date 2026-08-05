<?php

namespace Platform\Recruiting\Support;

/**
 * Wertebehandlung der acht Bewertungsfelder: Normalisierung beim Speichern,
 * "ist ueberhaupt bewertet?" fuer die Tabellenzelle und die kompakte
 * Zahlenreihe fuer die Anzeige.
 *
 * Invariante (Spec F7): "leer" ist immer NULL — niemals [] und niemals ''.
 * Ein leeres Array wuerde die Uebernahme-Pruefung (=== null) als "schon
 * gefuellt" lesen und die Uebernahme auf hrData blockieren.
 */
final class EvaluationValues
{
    public const NOTE_FIELD = 'evaluation_note';

    public const LIST_FIELDS = ['linen_package_items', 'qualifications'];

    /** Alle acht Bewertungsfelder in fester Reihenfolge. */
    public const FIELDS = [
        'rating_erscheinungsbild',
        'rating_fachkompetenz',
        'rating_auffassungsgabe',
        'rating_auftreten',
        'rating_teamintegration',
        self::NOTE_FIELD,
        'linen_package_items',
        'qualifications',
    ];

    /** Trenner der kompakten Zahlenreihe. */
    private const GLUE = '·';

    /** Platzhalter fuer einen nicht gesetzten Stern. */
    private const EMPTY_MARK = '–';

    public static function normalizeStar(mixed $value): ?int
    {
        if (is_bool($value) || is_array($value) || $value === null || $value === '') {
            return null;
        }
        // Nur ganzzahlige Werte akzeptieren — "2.7" ist keine Sternebewertung.
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            return null;
        }
        $int = (int) $value;

        return ($int >= 1 && $int <= 5) ? $int : null;
    }

    /** @return array<int, string>|null */
    public static function normalizeList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $clean = array_values(array_filter(
            $value,
            fn ($v) => $v !== '' && $v !== null,
        ));

        return $clean === [] ? null : $clean;
    }

    /**
     * True, wenn mindestens ein Bewertungsfeld einen echten Wert traegt.
     * Steuert "Bewerten" vs. "Bewertung bearbeiten" und die Anzeige der
     * kompakten Zeile.
     *
     * @param  array<string, mixed>  $values
     */
    public static function hasAny(array $values): bool
    {
        foreach (RatingCriteria::columns() as $column) {
            if (self::normalizeStar($values[$column] ?? null) !== null) {
                return true;
            }
        }

        if (trim((string) ($values[self::NOTE_FIELD] ?? '')) !== '') {
            return true;
        }

        foreach (self::LIST_FIELDS as $field) {
            if (self::normalizeList($values[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kompakte Anzeige der fuenf Sterne, z.B. "4·3·5·4·4"; nicht gesetzte
     * Werte als Gedankenstrich. Reihenfolge = RatingCriteria::columns().
     *
     * @param  array<string, mixed>  $values
     */
    public static function compactLine(array $values): string
    {
        $parts = [];
        foreach (RatingCriteria::columns() as $column) {
            $star = self::normalizeStar($values[$column] ?? null);
            $parts[] = $star === null ? self::EMPTY_MARK : (string) $star;
        }

        return implode(self::GLUE, $parts);
    }
}
