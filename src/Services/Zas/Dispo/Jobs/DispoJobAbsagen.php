<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Absagen auf Jobanfragen („Kann nicht“), sichtbar fuer alle (Markus 27.10.:
 * „Auswirkungen auf Lohn, Punkteranking“): Zaehler fuer die Jobseite, Liste
 * fuer die Akte, Block je Schicht fuer die Anfrageseite.
 */
final class DispoJobAbsagen
{
    /** Zeitraum des Zaehlers in Tagen. */
    public const TAGE = 60;

    public static function seit(): string
    {
        return now()->subDays(self::TAGE)->toDateTimeString();
    }

    /** @param list<int> $employeeIds alle Anstellungen der Person */
    public static function anzahl(array $employeeIds, string $seit): int
    {
        return self::basis($employeeIds, $seit)->count();
    }

    /**
     * @param list<int> $employeeIds
     * @return list<array{datum:string, veranstaltung:string, schicht:string, grund:string, notiz:string, am:string}>
     */
    public static function liste(array $employeeIds, string $seit): array
    {
        return self::basis($employeeIds, $seit)
            ->with(['shift.event:id,name,einsatz_ref'])
            ->orderByDesc('declined_at')->orderByDesc('id')
            ->get()
            ->map(fn (RecDispoJobOfferShift $os) => [
                'datum'         => $os->shift?->datum?->format('Y-m-d') ?? '',
                'veranstaltung' => (string) ($os->shift?->event?->name ?? $os->shift?->event?->einsatz_ref ?? ''),
                'schicht'       => trim(DispoJobZeit::spanne((string) $os->shift?->von, (string) $os->shift?->bis) . ' ' . (string) $os->shift?->taetigkeit),
                'grund'         => self::grundLabel($os->declined_reason),
                'notiz'         => (string) $os->declined_note,
                'am'            => $os->declined_at?->format('d.m.Y H:i') ?? '',
            ])->all();
    }

    /**
     * Absagen je Schicht fuer die Anfrageseite, mit Namen.
     *
     * @param list<int> $shiftIds
     * @return array<int, list<array{employee_id:int, name:string, grund:string, notiz:string, am:string}>>
     */
    public static function jeSchichten(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }
        $rows = RecDispoJobOfferShift::query()->whereIn('shift_id', $shiftIds)->whereNotNull('declined_at')
            ->with('offer:id,rec_employee_id,request_id')->orderBy('declined_at')->get();
        $ids = $rows->map(fn ($r) => (int) ($r->offer?->rec_employee_id ?? 0))->filter()->unique()->values()->all();
        $namen = $ids === [] ? [] : RecEmployee::query()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($e) => [(int) $e->id => trim($e->first_name . ' ' . $e->last_name)])->all();
        $nutzer = self::nutzerNamen($rows->pluck('ausgetragen_by_user_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all());

        $out = [];
        foreach ($rows as $r) {
            $id = (int) ($r->offer?->rec_employee_id ?? 0);
            $ausgetragen = $r->declined_reason === DispoJobZusageAustragen::GRUND;
            $out[(int) $r->shift_id][] = [
                'offer_shift_id' => (int) $r->id,
                'request_id'     => (int) ($r->offer?->request_id ?? 0),
                'employee_id'    => $id,
                'name'           => $namen[$id] ?? ('#' . $id),
                'grund'          => self::grundLabel($r->declined_reason),
                'ausgetragen'    => $ausgetragen,
                'von'            => $ausgetragen && $r->ausgetragen_by_user_id !== null ? ($nutzer[(int) $r->ausgetragen_by_user_id] ?? '') : '',
                'notiz'          => (string) $r->declined_note,
                'am'             => $r->declined_at?->format('d.m. H:i') ?? '',
            ];
        }

        return $out;
    }

    /** „Kann nicht“-Gruende wie auf der Jobseite; Austragung durch die Dispo eigene Zeile (Spec 2026-11-20). */
    public static function grundLabel(?string $grund): string
    {
        if ($grund === DispoJobZusageAustragen::GRUND) {
            return DispoJobZusageAustragen::GRUND_LABEL;
        }

        return JobOffersAbsage::GRUENDE[$grund] ?? (string) $grund;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function nutzerNamen(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            return \Platform\Core\Models\User::query()->whereIn('id', $ids)->pluck('name', 'id')
                ->mapWithKeys(fn ($n, $id) => [(int) $id => (string) $n])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function basis(array $employeeIds, string $seit)
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        return RecDispoJobOfferShift::query()
            ->whereNotNull('declined_at')->where('declined_at', '>=', $seit)
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $ids === [] ? [0] : $ids));
    }
}
