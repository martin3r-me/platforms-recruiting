<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Entscheidungshilfe je Zusage (Runde 2, Spec §4): Einsaetze im laufenden
 * Monat, ZAS-Taetigkeiten und die fuenf Termin-Bewertungen je Person — fuer
 * den Disponenten, der im Modus „sammeln“ aussucht. Ein Durchlauf fuer alle
 * Zeilen, nie pro Zeile. Fakten, keine Empfehlung: keine Sortierung, kein Schnitt.
 */
final class DispoJobZusagenInfo
{
    /**
     * @param list<int> $employeeIds rec_employee_id der Angebots-Zeilen
     * @return array<int, array{name:string, personnel_number:string, phone:string, einsaetze:int, taetigkeiten:list<string>, ratings:array<string,int>}>
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

        $ende = \Carbon\Carbon::parse($monat . '-01')->endOfMonth()->toDateString();
        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $alle)
            ->whereBetween('datum', [$monat . '-01', $ende])
            ->get(['rec_employee_id', 'datum', 'status_id', 'missing_since', 'deletion_marked_at', 'declined_at'])
            ->map(fn ($a) => ['employee_id' => (int) $a->rec_employee_id, 'datum' => $a->datum?->format('Y-m-d') ?? '',
                'status_id' => (int) $a->status_id, 'missing_since' => $a->missing_since, 'deletion_marked_at' => $a->deletion_marked_at, 'declined_at' => $a->declined_at])
            ->all();
        $einsaetze = DispoEinsatzZaehler::proMonat($assignments, $anchorByEmployee, $monat);

        $out = [];
        foreach ($ids as $id) {
            if (!isset($cards[$id]) && !isset($contacts[$id])) {
                continue;
            }
            $group = $groups[$id] ?? [$id];
            $ratings = [];
            $taetigkeiten = [];
            foreach (array_merge([$id], $group) as $member) {
                $card = $cards[(int) $member] ?? null;
                if ($card === null) {
                    continue;
                }
                if ($ratings === [] && $card['ratings'] !== []) {
                    $ratings = $card['ratings'];
                }
                $taetigkeiten = array_merge($taetigkeiten, $card['qualifications']);
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

    /** @param array<string,int> $ratings @return array{text:string, title:string} */
    public static function bewertung(array $ratings): array
    {
        if ($ratings === []) {
            return ['text' => '–', 'title' => 'keine Bewertung'];
        }
        $title = implode(', ', array_map(fn ($label, $wert) => $label . ' ' . $wert, array_keys($ratings), $ratings));
        if (count($ratings) === 1 && str_starts_with((string) array_key_first($ratings), 'Sternebewertung')) {
            return ['text' => '★ ' . reset($ratings), 'title' => $title];
        }

        return ['text' => implode(' · ', array_values($ratings)), 'title' => $title];
    }
}
