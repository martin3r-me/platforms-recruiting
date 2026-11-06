<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Je Zusage-Zeile: fuehrt ZAS die Person an dem Tag auf dieser VA in einer
 * ANDEREN Schicht? (Markus 06.11.: Sheran bucht in ZAS um, unsere Seite muss
 * das zeigen, sonst laufen beide Bilder auseinander.) Rein lesend, wenige Queries.
 */
final class DispoJobZasStand
{
    /**
     * @param Collection<int, RecDispoJobOfferShift> $zeilen mit geladenem offer (rec_employee_id)
     * @param Collection<int, \Platform\Recruiting\Models\RecDispoJobShift> $schichten
     * @return array<int, string> offer_shift_id => „17:00–03:00“ (nur Zeilen, bei denen ZAS eine andere Schicht fuehrt)
     */
    public static function andereSchicht(Collection $zeilen, Collection $schichten): array
    {
        $schichtenById = $schichten->keyBy('id');
        $employeeIds = $zeilen->map(fn ($z) => (int) ($z->offer?->rec_employee_id ?? 0))->filter()->unique()->values()->all();
        if ($employeeIds === []) {
            return [];
        }
        $groups = app(DispoIdentityResolver::class)->groupsFor($employeeIds);
        $alleIds = array_values(array_unique(array_merge($employeeIds, ...array_values($groups))));

        $tage = $schichten->map(fn ($s) => $s->datum->format('Y-m-d'))->unique()->values()->all();
        $events = $schichten->map(fn ($s) => (int) $s->rec_dispo_event_id)->unique()->values()->all();
        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $alleIds)
            ->whereIn('rec_dispo_event_id', $events)
            ->whereIn('datum', $tage)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')->whereNull('deletion_marked_at')->whereNull('declined_at')
            ->orderBy('id')
            ->get(['id', 'rec_employee_id', 'rec_dispo_event_id', 'datum', 'von', 'bis']);

        $out = [];
        foreach ($zeilen as $z) {
            $schicht = $schichtenById[(int) $z->shift_id] ?? null;
            $emp = (int) ($z->offer?->rec_employee_id ?? 0);
            if ($schicht === null || $emp === 0) {
                continue;
            }
            $ids = array_fill_keys($groups[$emp] ?? [$emp], true);
            $eigene = $assignments->filter(fn ($a) => isset($ids[(int) $a->rec_employee_id])
                && (int) $a->rec_dispo_event_id === (int) $schicht->rec_dispo_event_id
                && $a->datum->format('Y-m-d') === $schicht->datum->format('Y-m-d'));
            if ($eigene->isEmpty()) {
                continue;
            }
            // Steht die Person in ZAS auf DIESER Schicht, ist alles gut — auch wenn eine alte
            // Verknuepfung (vor dem Fix) auf eine andere Einbuchung zeigt. Sonst die verknuepfte
            // nennen, sonst irgendeine des Tages.
            $passend = $eigene->first(fn ($a) => DispoJobZasAbgleich::zeitPasst($a, $schicht->von, $schicht->bis));
            $verknuepft = $z->zas_assignment_id !== null ? $eigene->firstWhere('id', (int) $z->zas_assignment_id) : null;
            $massgeblich = $passend ?? $verknuepft ?? $eigene->first();
            if ($massgeblich !== null && !DispoJobZasAbgleich::zeitPasst($massgeblich, $schicht->von, $schicht->bis)) {
                $out[(int) $z->id] = DispoJobZeit::spanne($massgeblich->von, $massgeblich->bis);
            }
        }

        return $out;
    }
}
