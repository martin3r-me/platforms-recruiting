<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * „Ich bin dabei“ — Zusage zu einer Schicht, in einer Transaktion. Prueft zum
 * Klickzeitpunkt neu (Anfrage laeuft, Schicht offen, Tag nicht vorbei); im
 * Modus zuerst zaehlt sie die Zusagen gegen „gesucht“ und schliesst bei voll.
 *
 * Sperren: Die SCHICHT-Zeilen sind die Serialisierungspunkte, immer in
 * aufsteigender Schicht-ID gesperrt (kein Deadlock zwischen zwei Klicks).
 * Alles Veraenderliche wird NACH der Sperre sperrend gelesen: unter InnoDB
 * REPEATABLE READ sieht eine gewoehnliche Lesung den Schnappschuss der ersten
 * Lesung der Transaktion (also von VOR der Sperre) — nur sperrende Lesungen
 * sehen den letzten Stand. Darum: Angebots-Schicht, Anfrage und Zaehlung
 * lockForUpdate/sharedLock.
 *
 * Spec §9: Hat die Person dieselbe Schicht (VA, Tag, Beginn, Taetigkeit) auch
 * aus einer anderen laufenden Anfrage, sagt der Klick dort mit zu — nach den
 * Regeln jener Schicht (volle Schicht im Modus zuerst wird uebersprungen).
 */
final class JobOffersZusage
{
    /**
     * @param list<int> $employeeIds Identitaets-Gruppe der Person
     * @return 'ok'|'voll'|'weg'
     */
    public static function ausfuehren(int $offerShiftId, array $employeeIds): string
    {
        return DB::transaction(function () use ($offerShiftId, $employeeIds) {
            // Vorab nur Unveraenderliches (Schicht der Angebots-Zeile, Schicht-Merkmale).
            $vorab = RecDispoJobOfferShift::query()->with('shift')->find($offerShiftId);
            if ($vorab === null || $vorab->shift === null) {
                return 'weg';
            }
            $schwestern = JobOffersSchwestern::finden($vorab->shift, $employeeIds, (int) $vorab->id);

            $shiftIds = array_values(array_unique(array_merge([(int) $vorab->shift_id], array_map(fn ($s) => (int) $s->shift_id, $schwestern))));
            sort($shiftIds);
            $shifts = [];
            foreach ($shiftIds as $id) {
                $shift = RecDispoJobShift::query()->whereKey($id)->lockForUpdate()->first();
                if ($shift !== null) {
                    $shifts[$id] = $shift;
                }
            }

            $shift = $shifts[(int) $vorab->shift_id] ?? null;
            if ($shift === null) {
                return 'weg';
            }
            // Frisch und sperrend NACH der Schicht-Sperre: Besitz + Idempotenz auf aktuellem Stand.
            $os = RecDispoJobOfferShift::query()->whereKey($offerShiftId)->with('offer')->lockForUpdate()->first();
            if ($os === null || $os->offer === null || !in_array((int) $os->offer->rec_employee_id, $employeeIds, true)) {
                return 'weg';
            }

            $ergebnis = $os->accepted_at !== null ? 'ok' : self::annehmen($shift, $os, $employeeIds);
            if ($ergebnis !== 'ok') {
                return $ergebnis;
            }

            foreach ($schwestern as $s) {
                $schwesterShift = $shifts[(int) $s->shift_id] ?? null;
                $frisch = RecDispoJobOfferShift::query()->whereKey($s->id)->lockForUpdate()->first();
                if ($schwesterShift !== null && $frisch !== null && $frisch->accepted_at === null) {
                    self::annehmen($schwesterShift, $frisch, $employeeIds);
                }
            }

            return 'ok';
        }, 3);
    }

    /** Regeln einer (gesperrten) Schicht anwenden. @return 'ok'|'voll'|'weg'|'tag_belegt' */
    private static function annehmen(RecDispoJobShift $shift, RecDispoJobOfferShift $os, array $employeeIds): string
    {
        $request = RecDispoJobRequest::query()->whereKey($shift->request_id)->sharedLock()->first();
        if ($request === null) {
            return 'weg';
        }
        if (!$request->laeuft() || $shift->closed_at !== null || $shift->datum->lt(now()->startOfDay())) {
            return $request->laeuft() && $shift->closed_at !== null ? 'voll' : 'weg';
        }
        if (self::tagBelegt($shift, $employeeIds)) {
            return 'tag_belegt';
        }
        if ($request->modus === RecDispoJobRequest::MODUS_ZUERST && $shift->gesucht > 0) {
            // Sperrende Zaehlung: sieht Zusagen, die nach Beginn dieser Transaktion committet wurden.
            $zusagen = RecDispoJobOfferShift::query()->where('shift_id', $shift->id)->whereNotNull('accepted_at')->lockForUpdate()->count();
            if ($zusagen >= $shift->gesucht) {
                $shift->update(['closed_at' => now()]);

                return 'voll';
            }
            if ($zusagen + 1 >= $shift->gesucht) {
                $shift->update(['closed_at' => now()]);
            }
        }
        $os->update(['accepted_at' => now()]);

        return 'ok';
    }

    /**
     * Einmal pro Tag (Spec 2026-10-27 §6, Markus: „nur 1x fuer einen Tag“): hat
     * die Person an diesem Tag schon eine andere Schicht zugesagt (beliebige
     * Anfrage, nicht abgesagt), ist der Tag belegt. Dieselbe Schicht aus einer
     * anderen Anfrage (Schwester, gleicher Schluessel) zaehlt nicht — der Klick
     * sagt ihr gerade mit zu. Sperrend gelesen wie die Vollzaehlung.
     */
    private static function tagBelegt(RecDispoJobShift $shift, array $employeeIds): bool
    {
        $schluessel = DispoJobRecipientPlanner::schichtSchluessel($shift->rec_dispo_event_id, $shift->datum->format('Y-m-d'), $shift->von, $shift->taetigkeit);
        $andere = RecDispoJobOfferShift::query()
            ->whereNotNull('accepted_at')->whereNull('declined_at')
            ->where('shift_id', '!=', $shift->id)
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $employeeIds))
            ->whereHas('shift', fn ($q) => $q->whereDate('datum', $shift->datum->format('Y-m-d')))
            ->with('shift')
            ->lockForUpdate()
            ->get();

        return $andere->contains(fn ($os) => $os->shift !== null && DispoJobRecipientPlanner::schichtSchluessel(
            $os->shift->rec_dispo_event_id, $os->shift->datum->format('Y-m-d'), $os->shift->von, $os->shift->taetigkeit
        ) !== $schluessel);
    }
}
