<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Aktive Tag-Pillen einer VA nach einer Zeitraum-Aenderung (Schritt 4).
 * Rein: weggefallene Tage raus, NEUE Tage aktiv dazu, eine Abwahl eines
 * weiterhin angebotenen Tages bleibt bestehen.
 */
final class DispoJobTage
{
    /**
     * @param list<string> $aktiv bisher aktive Tage
     * @param list<string> $verfuegbar jetzt angebotene Tage
     * @param list<string> $vorherVerfuegbar beim letzten Angleichen angebotene Tage (leer = erster Eintritt)
     * @return list<string> sortiert
     */
    public static function angleichen(array $aktiv, array $verfuegbar, array $vorherVerfuegbar): array
    {
        $behalten = array_intersect($aktiv, $verfuegbar);
        $neu = array_diff($verfuegbar, $vorherVerfuegbar);
        $out = array_values(array_unique(array_merge($behalten, $neu)));
        sort($out, SORT_STRING);

        return $out;
    }
}
