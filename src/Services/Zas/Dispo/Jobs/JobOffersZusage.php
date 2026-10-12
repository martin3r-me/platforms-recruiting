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
            $schwestern = self::schwestern($vorab->shift, $employeeIds, (int) $vorab->id);

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

            $ergebnis = $os->accepted_at !== null ? 'ok' : self::annehmen($shift, $os);
            if ($ergebnis !== 'ok') {
                return $ergebnis;
            }

            foreach ($schwestern as $s) {
                $schwesterShift = $shifts[(int) $s->shift_id] ?? null;
                $frisch = RecDispoJobOfferShift::query()->whereKey($s->id)->lockForUpdate()->first();
                if ($schwesterShift !== null && $frisch !== null && $frisch->accepted_at === null) {
                    self::annehmen($schwesterShift, $frisch);
                }
            }

            return 'ok';
        }, 3);
    }

    /**
     * Offene Angebots-Schichten derselben Person fuer dieselbe Schicht in anderen laufenden Anfragen.
     *
     * @return list<RecDispoJobOfferShift>
     */
    private static function schwestern(RecDispoJobShift $shift, array $employeeIds, int $ohneId): array
    {
        $schluessel = DispoJobRecipientPlanner::schichtSchluessel($shift->rec_dispo_event_id, $shift->datum->format('Y-m-d'), $shift->von, $shift->taetigkeit);

        return RecDispoJobOfferShift::query()
            ->with('shift')
            ->where('id', '!=', $ohneId)
            ->whereNull('accepted_at')
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $employeeIds))
            ->whereHas('shift', fn ($q) => $q->where('rec_dispo_event_id', $shift->rec_dispo_event_id)
                ->whereDate('datum', $shift->datum->format('Y-m-d'))
                ->where('id', '!=', $shift->id)
                ->whereHas('request', fn ($r) => $r->where('status', RecDispoJobRequest::STATUS_LAEUFT)->whereNull('closed_at')))
            ->get()
            // Beginn/Taetigkeit wie auf der Seite vergleichen (trim, Gross-/Kleinschreibung).
            ->filter(fn ($s) => $s->shift !== null && DispoJobRecipientPlanner::schichtSchluessel(
                $s->shift->rec_dispo_event_id, $s->shift->datum->format('Y-m-d'), $s->shift->von, $s->shift->taetigkeit
            ) === $schluessel)
            ->values()->all();
    }

    /** Regeln einer (gesperrten) Schicht anwenden. @return 'ok'|'voll'|'weg' */
    private static function annehmen(RecDispoJobShift $shift, RecDispoJobOfferShift $os): string
    {
        $request = RecDispoJobRequest::query()->whereKey($shift->request_id)->sharedLock()->first();
        if ($request === null) {
            return 'weg';
        }
        if (!$request->laeuft() || $shift->closed_at !== null || $shift->datum->lt(now()->startOfDay())) {
            return $request->laeuft() && $shift->closed_at !== null ? 'voll' : 'weg';
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
}
