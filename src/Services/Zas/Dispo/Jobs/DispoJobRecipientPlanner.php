<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Services\Zas\ZasStatus;

/**
 * Wer bekommt in dieser Welle ein Angebot — und welche Schichten stehen drin.
 * Rein rechnend (Muster DispoRecipientPlanner): nichts wird still uebersprungen,
 * jeder Ausschluss landet in einem Topf mit den Anker-IDs dahinter.
 *
 * Block- und Gruppenfilter sind die GRUNDMENGE (so hat der Disponent gewaehlt),
 * kein Topf. Die Toepfe erklaeren, warum jemand aus der Grundmenge nichts
 * bekommt. Reihenfolge: schon angeschrieben, Portal gesperrt, ohne Nummer,
 * ohne passende Taetigkeit, an allen Tagen gebucht, schon anderswo zugesagt.
 *
 * Spec §9: Hat die Person dieselbe Schicht (VA, Tag, Beginn, Taetigkeit) schon
 * in einer anderen Anfrage zugesagt, faellt sie aus ihrem Angebot heraus.
 */
final class DispoJobRecipientPlanner
{
    public const TOEPFE = [
        'schon_angeschrieben' => 'in dieser Anfrage schon angeschrieben',
        'portal_gesperrt'     => 'Portal gesperrt',
        'ohne_nummer'         => 'ohne Handynummer',
        'ohne_taetigkeit'     => 'ohne passende Tätigkeit',
        'alle_tage_gebucht'   => 'an allen gewählten Tagen schon gebucht',
        'schon_zugesagt'      => 'hat diese Schicht schon zugesagt',
    ];

    /** Statusfilter (Spec 2026-10-20): ZAS-Status je Person, NICHT die Gesellschaft. */
    public const STATI = [
        ZasStatus::GO => 'GO – aktiver Vertrag',
        ZasStatus::MA => 'MA – braucht neuen Vertrag',
    ];

    /**
     * Nur bekannte Status-Schluessel, feste Reihenfolge GO, MA.
     *
     * @param array<int|string,mixed> $werte
     * @return list<string>
     */
    public static function stati(array $werte): array
    {
        $gewaehlt = array_fill_keys(array_map('strval', array_filter($werte, 'is_scalar')), true);

        return array_values(array_filter(array_keys(self::STATI), fn ($s) => isset($gewaehlt[$s])));
    }

    /** Beschriftung einer Welle: null (alte Welle) = beide. */
    public static function statiLabel(?array $stati): string
    {
        if ($stati === null) {
            return 'GO + MA';
        }
        $stati = self::stati($stati);

        return match (count($stati)) {
            0 => 'kein Status',
            1 => 'nur ' . $stati[0],
            default => implode(' + ', $stati),
        };
    }

    /**
     * Grundmenge aus Filiale und Gruppe — OHNE Statusfilter und ohne Toepfe.
     * Daraus zaehlt die Oberflaeche die Personen je Status, damit die Zahl am
     * Haekchen nicht vom eigenen Haekchen abhaengt.
     *
     * @return list<array<string,mixed>>
     */
    public static function grundmenge(array $kandidaten, array $einsaetze, array $auswahl): array
    {
        $bloecke = $auswahl['bloecke'] ?? [];
        $gruppen = $auswahl['gruppen'] ?? [];
        $ohneKostenstelle = in_array(null, $bloecke, true);
        $blockSet = array_fill_keys(array_map('intval', array_filter($bloecke, fn ($b) => $b !== null)), true);

        return array_values(array_filter($kandidaten, function ($k) use ($einsaetze, $gruppen, $ohneKostenstelle, $blockSet) {
            $block = $k['block'];
            $imBlock = $block === null ? $ohneKostenstelle : isset($blockSet[(int) $block]);

            return $imBlock && DispoEinsatzZaehler::trifft((int) ($einsaetze[(int) $k['anchor']] ?? 0), $gruppen);
        }));
    }

    /** Gleiche Schicht ueber Anfragen hinweg: VA | Tag | Beginn | Taetigkeit (case-/trim-unempfindlich). */
    public static function schichtSchluessel(int|string|null $event, string $datum, ?string $von, ?string $taetigkeit): string
    {
        return (int) $event . '|' . substr($datum, 0, 10) . '|' . trim((string) $von) . '|' . mb_strtolower(trim((string) $taetigkeit));
    }

    /**
     * @param list<array{anchor:int, employee_id:int, phone:?string, first_name:string, portal_token:string, block:?int, portal_locked:bool, taetigkeiten:list<string>}> $kandidaten
     * @param list<array{id:int, datum:string, taetigkeit:string, event?:int, von?:string}> $schichten
     * @param array<int,int> $einsaetze Anker => Einsaetze im Monat
     * @param array<int,array<string,true>> $gebucht Anker => gebuchte Tage
     * @param array<int,true> $schonAngeschrieben Anker => true
     * @param array{gruppen:list<string>, bloecke:list<int|null>, nur_passend:bool, stati?:?list<string>} $auswahl
     * @param array<int,list<string>> $schonZugesagt Anker => schichtSchluessel() der anderswo zugesagten Schichten
     * @return array{recipients: list<array{anchor:int, employee_id:int, phone:string, first_name:string, portal_token:string, shift_ids:list<int>}>, skipped: array<string,int>, skipped_ids: array<string,list<int>>}
     */
    public static function plan(array $kandidaten, array $schichten, array $einsaetze, array $gebucht, array $schonAngeschrieben, array $auswahl, array $schonZugesagt = []): array
    {
        $skipped = array_fill_keys(array_keys(self::TOEPFE), 0);
        $skippedIds = array_fill_keys(array_keys(self::TOEPFE), []);
        $recipients = [];

        $nurPassend = (bool) ($auswahl['nur_passend'] ?? false);
        // null/fehlend = alte Welle ohne Statusauswahl = GO + MA; [] = niemand.
        $stati = ($auswahl['stati'] ?? null) === null ? null : array_fill_keys(self::stati((array) $auswahl['stati']), true);

        foreach (self::grundmenge($kandidaten, $einsaetze, $auswahl) as $k) {
            $anchor = (int) $k['anchor'];
            if ($stati !== null && !isset($stati[(string) ($k['status'] ?? ZasStatus::GO)])) {
                continue;
            }
            if (isset($schonAngeschrieben[$anchor])) {
                self::topf($skipped, $skippedIds, 'schon_angeschrieben', $anchor);
                continue;
            }
            if (!empty($k['portal_locked'])) {
                self::topf($skipped, $skippedIds, 'portal_gesperrt', $anchor);
                continue;
            }
            $phone = trim((string) ($k['phone'] ?? ''));
            if ($phone === '') {
                self::topf($skipped, $skippedIds, 'ohne_nummer', $anchor);
                continue;
            }

            $eigene = array_fill_keys(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $k['taetigkeiten'] ?? []), true);
            $passend = $nurPassend
                ? array_values(array_filter($schichten, fn ($s) => isset($eigene[mb_strtolower(trim((string) $s['taetigkeit']))])))
                : $schichten;
            if ($passend === []) {
                self::topf($skipped, $skippedIds, 'ohne_taetigkeit', $anchor);
                continue;
            }

            $tage = $gebucht[$anchor] ?? [];
            $frei = array_values(array_filter($passend, fn ($s) => !isset($tage[substr((string) $s['datum'], 0, 10)])));
            if ($frei === []) {
                self::topf($skipped, $skippedIds, 'alle_tage_gebucht', $anchor);
                continue;
            }

            $zugesagt = array_fill_keys($schonZugesagt[$anchor] ?? [], true);
            if ($zugesagt !== []) {
                $frei = array_values(array_filter($frei, fn ($s) => !isset($zugesagt[self::schichtSchluessel(
                    $s['event'] ?? 0, (string) $s['datum'], $s['von'] ?? '', (string) $s['taetigkeit']
                )])));
                if ($frei === []) {
                    self::topf($skipped, $skippedIds, 'schon_zugesagt', $anchor);
                    continue;
                }
            }

            $recipients[] = [
                'anchor'       => $anchor,
                'employee_id'  => (int) $k['employee_id'],
                'phone'        => $phone,
                'first_name'   => (string) $k['first_name'],
                'portal_token' => (string) $k['portal_token'],
                'shift_ids'    => array_map(fn ($s) => (int) $s['id'], $frei),
            ];
        }

        return ['recipients' => $recipients, 'skipped' => $skipped, 'skipped_ids' => $skippedIds];
    }

    private static function topf(array &$skipped, array &$ids, string $topf, int $anchor): void
    {
        $skipped[$topf]++;
        $ids[$topf][] = $anchor;
    }
}
