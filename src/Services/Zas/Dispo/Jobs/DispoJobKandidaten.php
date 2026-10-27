<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\ZasStatus;
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
            ->with(['position:id,cost_center', 'hrData:id,rec_employee_id,dispo_taetigkeiten,export_status'])
            ->where('team_id', $teamId)->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'rec_position_id', 'first_name', 'last_name', 'phone', 'portal_token', 'portal_locked_at', 'cost_center', 'company']);
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
                'block' => KostenstellenBloecke::block($e->cost_center, $e->position?->cost_center), 'portal_locked' => false, 'taetigkeiten' => [], 'stati' => [], 'firmen' => []];
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
            if ($e->hrData !== null) {
                $row['stati'][] = $e->hrData->export_status;
            }
            // Gesellschaften der Person (Spec 2026-10-27 §3): jede Anstellung bringt ihre Firma mit.
            $row['firmen'] = DispoGesellschaft::normalize(array_merge($row['firmen'], [DispoGesellschaft::ausCompany($e->company)]));
            unset($row);
        }
        foreach ($byAnchor as &$row) {
            $row['ohne_vertrag'] = self::ohneVertrag($row['stati']);
            // Personen-Status fuer den Statusfilter (Spec 2026-10-20): GO in einer
            // Anstellung gewinnt, egal welche Gesellschaft; ohne Status = GO.
            $row['status'] = $row['ohne_vertrag'] ? ZasStatus::MA : ZasStatus::GO;
            unset($row['stati']);
        }
        unset($row);
        ksort($byAnchor);

        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $ids)
            ->where(fn ($q) => $q->whereBetween('datum', [$monat . '-01', \Carbon\Carbon::parse($monat . '-01')->endOfMonth()->toDateString()])->orWhereIn('datum', $tage))
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

    /**
     * „Ohne Vertrag“ = ZAS kennt die Person nur noch als MA: kein Datensatz der Person mit GO,
     * mindestens einer mit MA. Datensaetze ohne hrData (null) zaehlen nicht mit.
     *
     * @param iterable<?string> $stati export_status je Datensatz der Person
     */
    public static function ohneVertrag(iterable $stati): bool
    {
        $ma = false;
        foreach ($stati as $status) {
            $status = strtoupper(trim((string) $status));
            if ($status === ZasStatus::GO) {
                return false;
            }
            $ma = $ma || $status === ZasStatus::MA;
        }

        return $ma;
    }

    /**
     * Je Block die Zahlen fuer die Filial-Auswahl. `count`/`inaktiv` sind Anstellungen
     * (Datensaetze, User-Entscheidung 20.10.), `firmen` teilt sie je Gesellschaft
     * (Kunde 27.10.: „je Filiale je Gesellschaft ausweisen“) — eine Person mit RG-
     * und MA-Anstellung steht in beiden Zahlen.
     *
     * @return list<array{block:?int, label:string, nummern:list<string>, count:int, inaktiv:int, firmen:array<string,array{aktiv:int,inaktiv:int}>}>
     */
    public static function blockOptionen(int $teamId): array
    {
        $rows = RecEmployee::query()->with('position:id,cost_center')
            ->where('team_id', $teamId)->where('is_active', true)
            ->get(['id', 'rec_position_id', 'cost_center', 'company'])
            ->map(fn ($e) => ['cost_center' => $e->cost_center, 'position_cost_center' => $e->position?->cost_center !== null ? (string) $e->position->cost_center : null,
                'firma' => DispoGesellschaft::ausCompany($e->company)])
            ->all();

        $leer = fn () => array_map(fn () => ['aktiv' => 0, 'inaktiv' => 0], DispoGesellschaft::ALLE);
        $firmen = [];
        foreach ($rows as $row) {
            $key = KostenstellenBloecke::block($row['cost_center'], $row['position_cost_center']) ?? 'ohne';
            $firmen[$key] ??= $leer();
            $firmen[$key][$row['firma']]['aktiv']++;
        }

        // Inaktive: eine gruppierte Zaehlung, nur zur Anzeige — sie sind nie Empfaenger.
        $inaktiv = [];
        $gruppiert = RecEmployee::query()
            ->leftJoin('rec_positions', 'rec_positions.id', '=', 'rec_employees.rec_position_id')
            ->where('rec_employees.team_id', $teamId)->where('rec_employees.is_active', false)
            ->groupBy('rec_employees.cost_center', 'rec_positions.cost_center', 'rec_employees.company')
            ->selectRaw('rec_employees.cost_center as ma_kst, rec_positions.cost_center as stelle_kst, rec_employees.company as firma, count(*) as anzahl')
            ->get();
        foreach ($gruppiert as $g) {
            $block = KostenstellenBloecke::block($g->ma_kst, $g->stelle_kst !== null ? (string) $g->stelle_kst : null);
            $key = $block ?? 'ohne';
            $inaktiv[$key] = ($inaktiv[$key] ?? 0) + (int) $g->anzahl;
            $firmen[$key] ??= $leer();
            $firmen[$key][DispoGesellschaft::ausCompany($g->firma)]['inaktiv'] += (int) $g->anzahl;
        }

        return array_map(fn ($o) => $o + ['label' => self::blockLabel($o['block']), 'inaktiv' => $inaktiv[$o['block'] ?? 'ohne'] ?? 0,
                'firmen' => $firmen[$o['block'] ?? 'ohne'] ?? $leer()],
            KostenstellenBloecke::optionen($rows));
    }

    public static function blockLabel(?int $block): string
    {
        if ($block === null) {
            return self::OHNE_KOSTENSTELLE;
        }

        return Filialen::code($block) ?? ('#' . $block);
    }
}
