<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Support\RatingCriteria;

/**
 * Entscheidungshilfe je Zusage (Runde 2, Spec §4): Einsaetze im laufenden
 * Monat, ZAS-Taetigkeiten und die fuenf Termin-Bewertungen je Person — fuer
 * den Disponenten, der im Modus „sammeln“ aussucht. Ein Durchlauf fuer alle
 * Zeilen, nie pro Zeile. Fakten, keine Empfehlung: keine Sortierung, kein Schnitt.
 */
final class DispoJobZusagenInfo
{
    private const STERNE = 'Sternebewertung (Altbestand)';

    /**
     * @param list<int> $employeeIds rec_employee_id der Angebots-Zeilen
     * @return array<int, array{name:string, personnel_number:string, phone:string, einsaetze:int, taetigkeiten:list<string>, ratings:array<string,int>, ohne_vertrag:bool, absagen_60:int}>
     */
    public static function laden(array $employeeIds, string $monat): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), fn ($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }

        $groups = app(DispoIdentityResolver::class)->groupsFor($ids);
        $anchorByEmployee = [];
        foreach ($groups as $id => $group) {
            foreach ($group as $member) {
                $anchorByEmployee[(int) $member] = min($group);
            }
        }
        $alle = array_values(array_unique(array_merge($ids, array_keys($anchorByEmployee))));

        $gateway = app(DispoEmployeeGateway::class);
        $contacts = $gateway->contacts($ids);
        $cards = $gateway->profileCards($alle);

        $stati = [];
        foreach (RecEmployeeHrData::query()->whereIn('rec_employee_id', $alle)->get(['rec_employee_id', 'export_status']) as $hr) {
            $stati[(int) $hr->rec_employee_id] = $hr->export_status;
        }

        $ende = \Carbon\Carbon::parse($monat . '-01')->endOfMonth()->toDateString();
        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $alle)
            ->whereBetween('datum', [$monat . '-01', $ende])
            ->get(['rec_employee_id', 'datum', 'status_id', 'missing_since', 'deletion_marked_at', 'declined_at'])
            ->map(fn ($a) => ['employee_id' => (int) $a->rec_employee_id, 'datum' => $a->datum?->format('Y-m-d') ?? '',
                'status_id' => (int) $a->status_id, 'missing_since' => $a->missing_since, 'deletion_marked_at' => $a->deletion_marked_at, 'declined_at' => $a->declined_at])
            ->all();
        $einsaetze = DispoEinsatzZaehler::proMonat($assignments, $anchorByEmployee, $monat);

        // Absagen „Kann nicht“ der letzten 60 Tage je Anstellung, unten je Person summiert.
        $absagen = RecDispoJobOfferShift::query()
            ->whereNotNull('declined_at')->where('declined_at', '>=', DispoJobAbsagen::seit())
            ->join('rec_dispo_job_offers', 'rec_dispo_job_offers.id', '=', 'rec_dispo_job_offer_shifts.offer_id')
            ->whereIn('rec_dispo_job_offers.rec_employee_id', $alle)
            ->groupBy('rec_dispo_job_offers.rec_employee_id')
            ->selectRaw('rec_dispo_job_offers.rec_employee_id as mid, count(*) as n')
            ->pluck('n', 'mid');

        $out = [];
        foreach ($ids as $id) {
            if (!isset($cards[$id]) && !isset($contacts[$id])) {
                continue;
            }
            $group = $groups[$id] ?? [$id];
            $ratings = [];
            $sterne = null;
            $taetigkeiten = [];
            foreach (array_merge([$id], $group) as $member) {
                $card = $cards[(int) $member] ?? null;
                if ($card === null) {
                    continue;
                }
                // Je Kriterium das erste Gruppenmitglied, das es hat (Spec §4).
                foreach (RatingCriteria::labels() as $label) {
                    if (!isset($ratings[$label]) && isset($card['ratings'][$label])) {
                        $ratings[$label] = (int) $card['ratings'][$label];
                    }
                }
                if ($sterne === null && isset($card['ratings'][self::STERNE])) {
                    $sterne = (int) $card['ratings'][self::STERNE];
                }
                $taetigkeiten = array_merge($taetigkeiten, $card['qualifications']);
            }
            // Feste Reihenfolge der fuenf Kriterien; Altbestand nur ohne jede Termin-Bewertung.
            $ratings = self::geordnet($ratings);
            if ($ratings === [] && $sterne !== null) {
                $ratings = [self::STERNE => $sterne];
            }
            $taetigkeiten = array_values(array_unique(array_map('strval', $taetigkeiten)));
            sort($taetigkeiten, SORT_NATURAL | SORT_FLAG_CASE);

            $out[$id] = [
                'name'             => (string) ($contacts[$id]['name'] ?? $cards[$id]['name'] ?? ('#' . $id)),
                'personnel_number' => (string) ($cards[$id]['personnel_number'] ?? ''),
                'phone'            => (string) ($contacts[$id]['phone'] ?? ''),
                'einsaetze'        => (int) ($einsaetze[$anchorByEmployee[$id] ?? $id] ?? 0),
                'taetigkeiten'     => $taetigkeiten,
                'ratings'          => $ratings,
                'ohne_vertrag'     => DispoJobKandidaten::ohneVertrag(array_map(fn ($m) => $stati[(int) $m] ?? null, array_unique(array_merge([$id], $group)))),
                'absagen_60'       => (int) array_sum(array_map(fn ($m) => (int) ($absagen[(int) $m] ?? 0), array_unique(array_merge([$id], $group)))),
            ];
        }

        return $out;
    }

    /** Vergleich wie DispoJobRecipientPlanner: kleingeschrieben, getrimmt. */
    public static function passt(string $taetigkeit, array $taetigkeiten): bool
    {
        $eigene = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $taetigkeiten);

        return in_array(mb_strtolower(trim($taetigkeit)), $eigene, true);
    }

    /**
     * Fuenf Positionen in fester Reihenfolge, „–“ fuer fehlende; der Tooltip
     * nennt nur die vorhandenen. Altbestand: „★ N“.
     *
     * @param array<string,int> $ratings @return array{text:string, title:string}
     */
    public static function bewertung(array $ratings): array
    {
        if ($ratings === []) {
            return ['text' => '–', 'title' => 'keine Bewertung'];
        }
        $titel = fn (array $r) => implode(', ', array_map(fn ($label, $wert) => $label . ' ' . $wert, array_keys($r), $r));
        $vorhanden = self::geordnet($ratings);
        if ($vorhanden !== []) {
            return [
                'text'  => implode(' · ', array_map(fn ($label) => isset($ratings[$label]) ? (string) $ratings[$label] : '–', RatingCriteria::labels())),
                'title' => $titel($vorhanden),
            ];
        }
        if (count($ratings) === 1 && str_starts_with((string) array_key_first($ratings), 'Sternebewertung')) {
            return ['text' => '★ ' . reset($ratings), 'title' => $titel($ratings)];
        }

        return ['text' => implode(' · ', array_values($ratings)), 'title' => $titel($ratings)];
    }

    /** Nur die fuenf Kriterien, in fester Reihenfolge. @param array<string,int> $ratings @return array<string,int> */
    private static function geordnet(array $ratings): array
    {
        $out = [];
        foreach (RatingCriteria::labels() as $label) {
            if (isset($ratings[$label])) {
                $out[$label] = $ratings[$label];
            }
        }

        return $out;
    }
}
