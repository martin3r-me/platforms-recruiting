<?php

namespace Platform\Recruiting\Support;

/**
 * Einmalige Uebernahme der Bewertungsfelder vom Bewerber auf die frische
 * hrData-Row bei der Mitarbeiter-Erst-Anlage (Spec §4).
 *
 * Nur in leere Ziel-Felder schreiben: im Aufrufpfad ist die hrData-Row
 * definitionsgemaess neu (ensureHrData() ist ein firstOrCreate auf einem
 * gerade erzeugten Employee, Spec F8), ein "spaeterer HR-Edit" existiert dort
 * also nicht. Die Pruefung ist Absicherung gegen kuenftige Aufrufer, nicht
 * gegen einen heute erreichbaren Fall — und macht die Uebernahme
 * doppellauf-sicher.
 */
final class EvaluationTransfer
{
    /**
     * @param  array<string, mixed>  $applicantValues
     * @param  array<string, mixed>  $hrDataValues
     * @return array<string, mixed>  nur die zu schreibenden Felder
     */
    public static function valuesToCopy(array $applicantValues, array $hrDataValues): array
    {
        $copy = [];

        foreach (EvaluationValues::FIELDS as $field) {
            if (($hrDataValues[$field] ?? null) !== null) {
                continue;
            }

            $value = self::normalize($field, $applicantValues[$field] ?? null);
            if ($value === null) {
                continue;
            }

            $copy[$field] = $value;
        }

        return $copy;
    }

    private static function normalize(string $field, mixed $value): mixed
    {
        if (RatingCriteria::isColumn($field)) {
            return EvaluationValues::normalizeStar($value);
        }

        if (in_array($field, EvaluationValues::LIST_FIELDS, true)) {
            return EvaluationValues::normalizeList($value);
        }

        // evaluation_note
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
