<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Einstellung „Vorlagen fuer Jobangebote“ (Disposition → Einstellungen).
 */
final class DispoJobTemplateSettings
{
    public const KEY = 'dispo_job_offer_template_ids';

    /**
     * Nur Ziffern-Strings/ints > 0, dedupliziert, aufsteigend sortiert.
     *
     * @param  array<int|string, mixed> $raw
     * @return list<int>
     */
    public static function normalisieren(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && ctype_digit($value)) {
                $id = (int) $value;
            } else {
                continue;
            }
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    public static function lesen(RecApplicantSettings $s): array
    {
        return self::normalisieren((array) $s->getSetting(self::KEY));
    }
}
