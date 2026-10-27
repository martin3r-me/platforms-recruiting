<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * „Personalbedarf gedeckt“ je Schicht (Spec 2026-10-27 §4): die Zusagen
 * reichen (Markus: „wenn die Zusagen ausreichen im HCM ist die VA gedeckt“)
 * ODER von Hand markiert. Gilt in beiden Modi fuer den Versand; die Seite
 * schliesst im Modus „zuerst“ zusaetzlich ueber closed_at wie bisher.
 */
final class DispoJobGedeckt
{
    /** @param object|array<string,mixed> $shift mit gesucht, gedeckt_manuell_at (und optional zugesagt) */
    public static function istGedeckt(object|array $shift, ?int $zugesagt = null): bool
    {
        $feld = fn (string $n) => is_array($shift) ? ($shift[$n] ?? null) : ($shift->{$n} ?? null);
        if ($feld('gedeckt_manuell_at') !== null) {
            return true;
        }
        $gesucht = (int) $feld('gesucht');
        $zugesagt ??= (int) $feld('zugesagt');

        return $gesucht > 0 && $zugesagt >= $gesucht;
    }

    /**
     * Schichten, die eine Welle noch anbietet: nicht gedeckt, Tag >= heute.
     *
     * @return list<array{id:int, datum:string, taetigkeit:string, event:int, von:string}>
     */
    public static function offeneSchichten(RecDispoJobRequest $request): array
    {
        return $request->shifts()
            ->withCount(['offerShifts as zugesagt' => fn ($q) => $q->whereNotNull('accepted_at')])
            ->whereDate('datum', '>=', now()->toDateString())
            ->orderBy('datum')->orderBy('von')
            ->get()
            ->reject(fn (RecDispoJobShift $s) => self::istGedeckt($s, (int) $s->zugesagt))
            ->map(fn (RecDispoJobShift $s) => ['id' => (int) $s->id, 'datum' => $s->datum->format('Y-m-d'), 'taetigkeit' => (string) $s->taetigkeit,
                'event' => (int) $s->rec_dispo_event_id, 'von' => (string) $s->von])
            ->values()->all();
    }

    public static function alleGedeckt(RecDispoJobRequest $request): bool
    {
        return self::offeneSchichten($request) === [];
    }

    /** Von Hand gedeckt: Seite zeigt „schon voll“, Wellen lassen die Schicht aus. */
    public static function markieren(RecDispoJobRequest $request, int $shiftId): bool
    {
        return RecDispoJobShift::query()->whereKey($shiftId)->where('request_id', $request->id)
            ->whereNull('gedeckt_manuell_at')
            ->update(['gedeckt_manuell_at' => now(), 'closed_at' => now()]) === 1;
    }

    /** Wieder oeffnen: beide Sperren weg; im Modus „zuerst“ schliesst die naechste volle Zusage erneut. */
    public static function oeffnen(RecDispoJobRequest $request, int $shiftId): bool
    {
        return RecDispoJobShift::query()->whereKey($shiftId)->where('request_id', $request->id)
            ->update(['gedeckt_manuell_at' => null, 'closed_at' => null]) === 1;
    }
}
