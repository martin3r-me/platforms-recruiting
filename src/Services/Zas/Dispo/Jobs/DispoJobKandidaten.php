<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Support\Filialen;

/**
 * Baut die Eingaben des Empfaengerplaners aus der Datenbank: eine Zeile je
 * PERSON (Anker der Identitaetsgruppe), der Datensatz mit Handynummer gewinnt,
 * Taetigkeiten und Sperren werden ueber die ganze Gruppe vereinigt.
 */
final class DispoJobKandidaten
{
    public const OHNE_KOSTENSTELLE = 'ohne Kostenstelle';

    /**
     * @param list<string> $tage Y-m-d der angebotenen Schichten
     * @return array{kandidaten: list<array<string,mixed>>, einsaetze: array<int,int>, gebucht: array<int,array<string,true>>}
     */
    public static function laden(int $teamId, array $tage, string $monat): array
    {
        $employees = RecEmployee::query()
            ->with(['position:id,cost_center', 'hrData:id,rec_employee_id,dispo_taetigkeiten'])
            ->where('team_id', $teamId)->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'rec_position_id', 'first_name', 'last_name', 'phone', 'portal_token', 'portal_locked_at', 'cost_center']);
        if ($employees->isEmpty()) {
            return ['kandidaten' => [], 'einsaetze' => [], 'gebucht' => []];
        }

        $ids = $employees->pluck('id')->map(fn ($v) => (int) $v)->all();
        $groups = app(DispoIdentityResolver::class)->groupsFor($ids);
        $anchorByEmployee = [];
        foreach ($groups as $id => $group) {
            $anchorByEmployee[$id] = min($group);
        }
        $contacts = app(DispoEmployeeGateway::class)->contacts($ids);

        $byAnchor = [];
        foreach ($employees as $e) {
            $id = (int) $e->id;
            $anchor = $anchorByEmployee[$id] ?? $id;
            $contact = $contacts[$id] ?? null;
            $phone = $contact['phone'] ?? null;
            $taet = array_values(array_filter(array_map('strval', (array) ($e->hrData?->dispo_taetigkeiten ?? []))));
            $row = &$byAnchor[$anchor];
            $row ??= ['anchor' => $anchor, 'employee_id' => $id, 'phone' => null, 'first_name' => (string) $e->first_name,
                'name' => trim($e->first_name . ' ' . $e->last_name), 'portal_token' => (string) $e->portal_token,
                'block' => KostenstellenBloecke::block($e->cost_center, $e->position?->cost_center), 'portal_locked' => false, 'taetigkeiten' => []];
            if ($row['phone'] === null && $phone !== null && trim($phone) !== '') {
                // Der Datensatz MIT Nummer bekommt die Nachricht (und seinen Token).
                $row['employee_id'] = $id;
                $row['phone'] = $phone;
                $row['portal_token'] = (string) $e->portal_token;
                $row['first_name'] = (string) $e->first_name;
            }
            $row['portal_locked'] = $row['portal_locked'] || $e->portal_locked_at !== null;
            $row['block'] ??= KostenstellenBloecke::block($e->cost_center, $e->position?->cost_center);
            $row['taetigkeiten'] = array_values(array_unique(array_merge($row['taetigkeiten'], $taet)));
            unset($row);
        }
        ksort($byAnchor);

        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $ids)
            ->where(fn ($q) => $q->whereBetween('datum', [$monat . '-01', $monat . '-31'])->orWhereIn('datum', $tage))
            ->get(['rec_employee_id', 'datum', 'status_id', 'missing_since', 'deletion_marked_at', 'declined_at'])
            ->map(fn ($a) => ['employee_id' => (int) $a->rec_employee_id, 'datum' => $a->datum?->format('Y-m-d') ?? '',
                'status_id' => (int) $a->status_id, 'missing_since' => $a->missing_since, 'deletion_marked_at' => $a->deletion_marked_at, 'declined_at' => $a->declined_at])
            ->all();

        $gebucht = DispoTagesPruefung::gebuchteTage($assignments, $anchorByEmployee);
        $tageSet = array_fill_keys($tage, true);
        foreach ($gebucht as $anchor => $days) {
            $gebucht[$anchor] = array_intersect_key($days, $tageSet);
            if ($gebucht[$anchor] === []) {
                unset($gebucht[$anchor]);
            }
        }

        return [
            'kandidaten' => array_values($byAnchor),
            'einsaetze'  => DispoEinsatzZaehler::proMonat($assignments, $anchorByEmployee, $monat),
            'gebucht'    => $gebucht,
        ];
    }

    /** @return list<array{block:?int, label:string, nummern:list<string>, count:int}> */
    public static function blockOptionen(int $teamId): array
    {
        $rows = RecEmployee::query()->with('position:id,cost_center')
            ->where('team_id', $teamId)->where('is_active', true)
            ->get(['id', 'rec_position_id', 'cost_center'])
            ->map(fn ($e) => ['cost_center' => $e->cost_center, 'position_cost_center' => $e->position?->cost_center !== null ? (string) $e->position->cost_center : null])
            ->all();

        return array_map(fn ($o) => $o + ['label' => self::blockLabel($o['block'])], KostenstellenBloecke::optionen($rows));
    }

    public static function blockLabel(?int $block): string
    {
        if ($block === null) {
            return self::OHNE_KOSTENSTELLE;
        }

        return Filialen::code($block) ?? ('#' . $block);
    }
}
