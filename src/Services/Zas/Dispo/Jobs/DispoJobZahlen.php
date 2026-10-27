<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Services\Zas\ZasStatus;

/**
 * „Sendet an N Personen“ VOR dem Senden — dieselbe Rechnung wie beim Start
 * der Welle (DispoJobKandidaten + DispoJobRecipientPlanner), nur ohne Versand.
 * Beim Start wird neu gerechnet; diese Zahl ist eine Vorschau.
 */
final class DispoJobZahlen
{
    /** Wert der Checkbox „ohne Kostenstelle“ (Livewire-Checkboxen tragen Strings, nie null). */
    public const OHNE = 'ohne';

    /**
     * @param list<array{id:int, datum:string, taetigkeit:string, event?:int, von?:string}> $schichten
     * @param array<int,true> $schonAngeschrieben
     * @param array{gruppen:list<string>, bloecke:list<int|null>, nur_passend:bool, stati?:?list<string>} $auswahl
     * @return array{empfaenger:int, namen:list<string>, toepfe:list<array{key:string, label:string, anzahl:int, namen:list<string>}>, mit_passend:int, ohne_vertrag:int, je_status:array{GO:int, MA:int}, rest:int}
     */
    public static function berechnen(int $teamId, array $schichten, array $schonAngeschrieben, array $auswahl): array
    {
        if ($schichten === []) {
            return ['empfaenger' => 0, 'namen' => [], 'toepfe' => [], 'mit_passend' => 0, 'ohne_vertrag' => 0, 'je_status' => ['GO' => 0, 'MA' => 0], 'rest' => 0];
        }
        $tage = array_values(array_unique(array_map(fn ($s) => substr((string) $s['datum'], 0, 10), $schichten)));
        $daten = DispoJobKandidaten::laden($teamId, $tage, now()->format('Y-m'));

        return self::ausDaten($daten, $schichten, $schonAngeschrieben, $auswahl, DispoJobSchonZugesagt::laden($teamId));
    }

    /**
     * Rein: aus geladenen Kandidaten.
     *
     * @param array<int,list<string>> $schonZugesagt Anker => anderswo zugesagte Schichten (DispoJobSchonZugesagt)
     */
    public static function ausDaten(array $daten, array $schichten, array $schonAngeschrieben, array $auswahl, array $schonZugesagt = []): array
    {
        $plan = DispoJobRecipientPlanner::plan($daten['kandidaten'], $schichten, $daten['einsaetze'], $daten['gebucht'], $schonAngeschrieben, $auswahl, $schonZugesagt);
        $mitPassend = !empty($auswahl['nur_passend'])
            ? count($plan['recipients'])
            : count(DispoJobRecipientPlanner::plan($daten['kandidaten'], $schichten, $daten['einsaetze'], $daten['gebucht'], $schonAngeschrieben,
                ['nur_passend' => true] + $auswahl, $schonZugesagt)['recipients']);

        $namen = [];
        foreach ($daten['kandidaten'] as $k) {
            $namen[(int) $k['anchor']] = (string) ($k['name'] ?? $k['first_name'] ?? ('#' . $k['anchor']));
        }
        $ohneVertrag = array_column($daten['kandidaten'], 'ohne_vertrag', 'anchor');
        $toepfe = [];
        foreach (DispoJobRecipientPlanner::TOEPFE as $key => $label) {
            $anzahl = (int) ($plan['skipped'][$key] ?? 0);
            if ($anzahl === 0) {
                continue;
            }
            $toepfe[] = ['key' => $key, 'label' => $label, 'anzahl' => $anzahl,
                'namen' => self::sortiert(array_map(fn ($a) => $namen[(int) $a] ?? ('#' . $a), $plan['skipped_ids'][$key] ?? []))];
        }

        // Zahl am Status-Haekchen: Personen der Grundmenge (Filiale + Gruppe),
        // ohne Statusfilter und ohne Toepfe — haengt nie am eigenen Haekchen.
        $jeStatus = array_fill_keys(array_keys(DispoJobRecipientPlanner::STATI), 0);
        foreach (DispoJobRecipientPlanner::grundmenge($daten['kandidaten'], $daten['einsaetze'], $auswahl) as $k) {
            $s = (string) ($k['status'] ?? ZasStatus::GO);
            if (isset($jeStatus[$s])) {
                $jeStatus[$s]++;
            }
        }

        return [
            'empfaenger'  => count($plan['recipients']),
            'namen'       => self::sortiert(array_map(fn ($r) => $namen[(int) $r['anchor']] ?? $r['first_name'], $plan['recipients'])),
            'toepfe'      => $toepfe,
            'mit_passend' => $mitPassend,
            'ohne_vertrag' => count(array_filter($plan['recipients'], fn ($r) => !empty($ohneVertrag[(int) $r['anchor']]))),
            'je_status'   => $jeStatus,
            'rest'        => (int) ($plan['rest'] ?? 0),
        ];
    }

    /**
     * Checkbox-Werte → Bloecke des Planers ('ohne' → null, Ziffern → int, Rest weg).
     *
     * @param array<int|string,mixed> $werte
     * @return list<int|null>
     */
    public static function bloecke(array $werte): array
    {
        $out = [];
        foreach ($werte as $w) {
            if ($w === self::OHNE) {
                $out['ohne'] = null;
            } elseif ((is_string($w) && ctype_digit($w)) || is_int($w)) {
                $out['b' . (int) $w] = (int) $w;
            }
        }

        return array_values($out);
    }

    /** @return list<string> nur bekannte Gruppen-Schluessel */
    public static function gruppen(array $werte): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $werte),
            fn ($g) => array_key_exists($g, DispoEinsatzZaehler::BAENDER))));
    }

    /** @param list<string> $namen */
    private static function sortiert(array $namen): array
    {
        sort($namen, SORT_NATURAL | SORT_FLAG_CASE);

        return $namen;
    }
}
