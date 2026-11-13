<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Merkt die zuletzt von Hand angepasste Eskalation fuer die Dauer der Sitzung
 * (Sheran 13.11.2026: „bis HCM wieder neu geöffnet wurde — für mehrere
 * Projekte die man bestätigt ein Vorteil“). Rein rechnend; gelesen und
 * geschrieben wird die Sitzung in Events\Show.
 *
 * Angeboten wird er nur in einer VA OHNE eigenen Plan — ein gespeicherter Plan
 * einer VA gewinnt immer — und uebernommen erst per Klick, nie automatisch. Die Standard-Eskalation wird nicht gemerkt: wer auf
 * Standard zuruecksetzt und sendet, loescht den Merker damit.
 */
final class DispoEskalationsMerker
{
    public const SESSION_KEY = 'recruiting.dispo.eskalation_merker';

    /**
     * Formularwerte → Merker; null = Standard oder nicht merkbar.
     *
     * @return ?array{day:string, date:string, t1:string, t2:string, t3:string}
     */
    public static function aus(string $day, string $date, string $t1, string $t2, string $t3): ?array
    {
        if (!in_array($day, [DispoEscalationConfig::DAY_VORTAG, DispoEscalationConfig::DAY_EINSATZTAG, DispoEscalationConfig::DAY_DATUM], true)) {
            return null;
        }
        $zeiten = [$t1, $t2, $t3];
        $hatZeiten = $t1 !== '';
        if ($hatZeiten) {
            foreach ($zeiten as $zeit) {
                if (!DispoEscalationConfig::isTime($zeit)) {
                    return null;
                }
            }
        } else {
            $zeiten = ['', '', ''];
        }
        if ($day === DispoEscalationConfig::DAY_DATUM) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                return null;
            }
        } else {
            $date = '';
        }
        if ($day === DispoEscalationConfig::DAY_VORTAG && !$hatZeiten) {
            return null; // Team-Standard
        }

        return ['day' => $day, 'date' => $date, 't1' => $zeiten[0], 't2' => $zeiten[1], 't3' => $zeiten[2]];
    }

    /**
     * Lesbar fuer das Angebot im Sende-Fenster: „am 13.11. · 09:00 / 10:00 / 11:00“.
     *
     * @param array{day:string, date:string, t1:string, t2:string, t3:string} $merker
     * @param array<int, string> $standard Team-Standardzeiten [1=>,2=>,3=>]
     */
    public static function label(array $merker, array $standard): string
    {
        $tag = match ($merker['day']) {
            DispoEscalationConfig::DAY_EINSATZTAG => 'Einsatztag',
            DispoEscalationConfig::DAY_DATUM => 'am ' . substr($merker['date'], 8, 2) . '.' . substr($merker['date'], 5, 2) . '.',
            default => 'Vortag',
        };
        $zeiten = $merker['t1'] !== ''
            ? $merker['t1'] . ' / ' . $merker['t2'] . ' / ' . $merker['t3']
            : 'Standard ' . ($standard[1] ?? '') . ' / ' . ($standard[2] ?? '') . ' / ' . ($standard[3] ?? '');

        return $tag . ' · ' . $zeiten;
    }

    /**
     * Darf der Merker in das Sende-Fenster dieser VA? Was aus der Sitzung
     * kommt, wird neu geprueft; ein Datum von gestern wird nicht uebernommen
     * (die Pruefung beim Senden wuerde es ohnehin ablehnen).
     *
     * @return ?array{day:string, date:string, t1:string, t2:string, t3:string}
     */
    public static function anwendbar(mixed $merker, bool $vaHatEigenenPlan, string $heute): ?array
    {
        if ($vaHatEigenenPlan || !is_array($merker)) {
            return null;
        }
        foreach (['day', 'date', 't1', 't2', 't3'] as $feld) {
            if (!is_string($merker[$feld] ?? null)) {
                return null;
            }
        }
        $sauber = self::aus($merker['day'], $merker['date'], $merker['t1'], $merker['t2'], $merker['t3']);
        if ($sauber === null) {
            return null;
        }
        if ($sauber['day'] === DispoEscalationConfig::DAY_DATUM && $sauber['date'] < $heute) {
            return null;
        }

        return $sauber;
    }
}
