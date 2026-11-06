<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Verknuepft zugesagte Angebots-Schichten mit der aus ZAS angekommenen
 * Einbuchung (gleiche Person-Gruppe, gleiche VA, gleicher Tag, Status Auftrag, nicht
 * verschwunden/zur Loeschung gemeldet/abgesagt — dieselbe Zeilenregel wie ueberall).
 */
class DispoJobZasAbgleich
{
    /** @return int Anzahl neu gesetzter zas_assignment_id */
    public function run(): int
    {
        $offen = RecDispoJobOfferShift::query()
            ->with(['offer:id,rec_employee_id,anchor_employee_id', 'shift:id,rec_dispo_event_id,datum,von,bis'])
            ->whereNotNull('accepted_at')->whereNull('zas_assignment_id')->get();
        if ($offen->isEmpty()) {
            return 0;
        }

        $employeeIds = $offen->pluck('offer.rec_employee_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $groups = app(DispoIdentityResolver::class)->groupsFor($employeeIds);
        $gesetzt = 0;
        foreach ($offen as $os) {
            if ($os->offer === null || $os->shift === null) {
                continue;
            }
            $ids = $groups[(int) $os->offer->rec_employee_id] ?? [(int) $os->offer->rec_employee_id];
            $a = self::kandidaten($ids, (int) $os->shift->rec_dispo_event_id, $os->shift->datum->format('Y-m-d'))
                ->first(fn (RecDispoAssignment $a) => self::zeitPasst($a, $os->shift->von, $os->shift->bis));
            if ($a !== null) {
                $os->update(['zas_assignment_id' => (int) $a->id]);
                $gesetzt++;
            }
        }

        return $gesetzt;
    }

    /**
     * Einbuchungen der Person-Gruppe auf dieser VA an diesem Tag (Auftrag, nicht
     * verschwunden/zur Loeschung gemeldet/abgesagt), aelteste zuerst.
     *
     * @param list<int> $employeeIds
     * @return \Illuminate\Database\Eloquent\Collection<int, RecDispoAssignment>
     */
    public static function kandidaten(array $employeeIds, int $eventId, string $datum): \Illuminate\Database\Eloquent\Collection
    {
        return RecDispoAssignment::query()->whereIn('rec_employee_id', $employeeIds)
            ->where('rec_dispo_event_id', $eventId)
            ->whereDate('datum', $datum)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')->whereNull('deletion_marked_at')->whereNull('declined_at')
            ->orderBy('id')->get();
    }

    /**
     * Gleiche Schicht (Markus 06.11.): Beginn und Ende muessen passen, sofern beide
     * Seiten einen Wert haben. Vorher reichte „Person ist an dem Tag auf der VA“ —
     * dann stand der Haken auf 17:30–04:00, obwohl ZAS die Person auf 17–03 fuehrt.
     */
    public static function zeitPasst(RecDispoAssignment $a, ?string $von, ?string $bis): bool
    {
        foreach ([[$a->von, $von], [$a->bis, $bis]] as [$zas, $schicht]) {
            $zas = self::uhr($zas);
            $schicht = self::uhr($schicht);
            if ($zas !== '' && $schicht !== '' && $zas !== $schicht) {
                return false;
            }
        }

        return true;
    }

    /** „17:00:00“, „17:00“, „7:00“ → „17:00“ bzw. „07:00“; leer bleibt leer. */
    public static function uhr(?string $v): string
    {
        $v = trim((string) $v);
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $v, $m)) {
            return '';
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }
}
