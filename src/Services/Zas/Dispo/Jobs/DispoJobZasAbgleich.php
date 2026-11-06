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
            ->with(['offer:id,rec_employee_id,anchor_employee_id', 'shift:id,rec_dispo_event_id,datum,von,bis,dispoposten_id'])
            ->whereNotNull('accepted_at')->whereNull('zas_assignment_id')->get();
        if ($offen->isEmpty()) {
            return 0;
        }

        $employeeIds = $offen->pluck('offer.rec_employee_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $groups = app(DispoIdentityResolver::class)->groupsFor($employeeIds);
        $varianten = self::zeitvarianten($offen->map(fn ($os) => $os->shift)->filter());
        $gesetzt = 0;
        foreach ($offen as $os) {
            if ($os->offer === null || $os->shift === null) {
                continue;
            }
            $ids = $groups[(int) $os->offer->rec_employee_id] ?? [(int) $os->offer->rec_employee_id];
            $zeiten = $varianten[(int) $os->shift->id] ?? [[$os->shift->von, $os->shift->bis]];
            $a = self::kandidaten($ids, (int) $os->shift->rec_dispo_event_id, $os->shift->datum->format('Y-m-d'))
                ->first(fn (RecDispoAssignment $a) => self::zeitPasstEine($a, $zeiten));
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
     * Zeitvarianten je Schicht: die eigene Zeit der Schicht plus alle Varianten des
     * ZAS-Postens (Bedarfsliste `zeiten`, z. B. 08:30 und 08:45 fuer denselben Posten —
     * Review 06.11.: ZAS bucht die Person in irgendeiner Variante, die Schicht traegt
     * nur die erste). Eine Query fuer alle Posten.
     *
     * @param iterable<\Platform\Recruiting\Models\RecDispoJobShift> $schichten
     * @return array<int, list<array{0:?string,1:?string}>> shift_id => [[von, bis], ...]
     */
    public static function zeitvarianten(iterable $schichten): array
    {
        $out = [];
        $posten = [];
        foreach ($schichten as $s) {
            $out[(int) $s->id] = [[$s->von, $s->bis]];
            $nr = trim((string) $s->dispoposten_id);
            if ($nr !== '') {
                $posten[$nr][] = $s;
            }
        }
        if ($posten === []) {
            return $out;
        }
        $bedarfe = \Platform\Recruiting\Models\RecDispoBedarf::query()
            ->whereIn('dispoposten_id', array_keys($posten))
            ->get(['dispoposten_id', 'datum', 'von', 'bis', 'zeiten']);
        foreach ($bedarfe as $b) {
            foreach ($posten[trim((string) $b->dispoposten_id)] ?? [] as $s) {
                if ($b->datum !== null && $b->datum->format('Y-m-d') !== $s->datum->format('Y-m-d')) {
                    continue;
                }
                $out[(int) $s->id][] = [$b->von, $b->bis];
                foreach ((array) $b->zeiten as $z) {
                    $out[(int) $s->id][] = [$z['von'] ?? null, $z['bis'] ?? null];
                }
            }
        }

        return $out;
    }

    /** @param list<array{0:?string,1:?string}> $zeiten */
    public static function zeitPasstEine(RecDispoAssignment $a, array $zeiten): bool
    {
        foreach ($zeiten as [$von, $bis]) {
            if (self::zeitPasst($a, $von, $bis)) {
                return true;
            }
        }

        return $zeiten === [];
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
