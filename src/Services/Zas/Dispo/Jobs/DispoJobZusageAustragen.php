<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * Zusage austragen (Spec 2026-11-20, Markus 20.11.2026 „so bitte umsetzen“):
 * jemand hat zugesagt, kann aber doch nicht. Der Platz wird wieder frei, die
 * naechste Welle bietet ihn wieder an, die Person bekommt die Schicht nicht
 * noch einmal.
 *
 * Gespeichert als Absage mit Grund „ausgetragen“: accepted_at wird geleert
 * (alter Zeitpunkt in ausgetragen_zusage_at). Damit sehen Zaehler, Export und
 * Abgleich die Zusage nicht mehr, ohne dass sie angefasst werden muessen, und
 * die Zusage-Logik lehnt abgesagte Zeilen schon heute ab. Im Absagen-Zaehler
 * (60 Tage) erscheint sie als eigene Zeile „ausgetragen durch Dispo“.
 *
 * Schwestern (dieselbe Schicht in einer anderen laufenden Anfrage mitzugesagt,
 * §9) werden mit ausgetragen — sonst ginge die Buchung ueber die Schwester doch
 * an ZAS. Sperren wie bei der Zusage: erst die Schicht-Zeilen in aufsteigender
 * ID, danach die Angebots-Zeilen sperrend frisch lesen.
 */
final class DispoJobZusageAustragen
{
    public const GRUND = 'ausgetragen';
    public const GRUND_LABEL = 'ausgetragen durch Dispo';
    public const NOTIZ_ABSAGE = 'Absage auf der Veranstaltungsseite';

    public const OK = 'ok';
    public const WEG = 'weg';
    public const VOLL = 'voll';
    public const TAG = 'tag';

    public const TEXTE = [
        self::OK   => 'Zusage ausgetragen. Der Platz ist wieder frei.',
        self::WEG  => 'Diese Zusage gibt es nicht mehr.',
        self::VOLL => 'Die Schicht ist inzwischen voll oder als gedeckt markiert — erst wieder öffnen.',
        self::TAG  => 'Die Person hat an dem Tag inzwischen eine andere Schicht zugesagt.',
    ];

    public const IN_ZAS_TEXT = 'Die Zusage steht schon in ZAS (übergeben oder eingetragen) — bitte dort auch austragen.';

    /**
     * @return array{ergebnis: string, in_zas: bool, anzahl: int}
     */
    public static function ausfuehren(RecDispoJobRequest $request, int $offerShiftId, ?int $userId, string $notiz = ''): array
    {
        return DB::transaction(function () use ($request, $offerShiftId, $userId, $notiz) {
            $vorab = RecDispoJobOfferShift::query()->whereKey($offerShiftId)
                ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
                ->with(['shift', 'offer'])->first();
            if ($vorab === null || $vorab->shift === null || $vorab->offer === null) {
                return ['ergebnis' => self::WEG, 'in_zas' => false, 'anzahl' => 0];
            }
            $schwestern = self::schwestern($vorab, fn ($q) => $q->whereNotNull('accepted_at')->whereNull('declined_at'));
            self::schichtenSperren(array_merge([$vorab], $schwestern));

            $os = RecDispoJobOfferShift::query()->whereKey($offerShiftId)->lockForUpdate()->first();
            if ($os === null || $os->accepted_at === null || $os->declined_at !== null) {
                return ['ergebnis' => self::WEG, 'in_zas' => false, 'anzahl' => 0];
            }

            $jetzt = now();
            $inZas = false;
            $anzahl = 0;
            $schichten = [];
            foreach (array_merge([$os], $schwestern) as $zeile) {
                $frisch = (int) $zeile->id === (int) $os->id ? $os : RecDispoJobOfferShift::query()->whereKey($zeile->id)->lockForUpdate()->first();
                if ($frisch === null || $frisch->accepted_at === null || $frisch->declined_at !== null) {
                    continue;
                }
                $inZas = $inZas || $frisch->zas_assignment_id !== null || $frisch->zas_exported_at !== null || $frisch->zas_entered_at !== null;
                $frisch->update([
                    'ausgetragen_zusage_at'  => $frisch->accepted_at,
                    'accepted_at'            => null,
                    'declined_at'            => $jetzt,
                    'declined_reason'        => self::GRUND,
                    'declined_note'          => mb_substr(trim($notiz), 0, 200),
                    'ausgetragen_by_user_id' => $userId,
                ]);
                $anzahl++;
                $schichten[(int) $frisch->shift_id] = true;
            }
            self::nachzaehlen(array_keys($schichten));

            return ['ergebnis' => self::OK, 'in_zas' => $inZas, 'anzahl' => $anzahl];
        }, 3);
    }

    /**
     * Austragen zuruecknehmen, solange der Platz frei ist. Schwestern mit
     * demselben Austrage-Zeitpunkt kommen mit, wenn ihr Platz auch frei ist.
     *
     * @return self::OK|self::WEG|self::VOLL|self::TAG
     */
    public static function zuruecknehmen(RecDispoJobRequest $request, int $offerShiftId): string
    {
        return DB::transaction(function () use ($request, $offerShiftId) {
            $vorab = RecDispoJobOfferShift::query()->whereKey($offerShiftId)
                ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
                ->with(['shift', 'offer'])->first();
            if ($vorab === null || $vorab->shift === null || $vorab->offer === null || $vorab->declined_reason !== self::GRUND) {
                return self::WEG;
            }
            $am = $vorab->declined_at;
            $schwestern = self::schwestern($vorab, fn ($q) => $q->where('declined_reason', self::GRUND)->where('declined_at', $am));
            $shifts = self::schichtenSperren(array_merge([$vorab], $schwestern));

            $ergebnis = null;
            $schichten = [];
            foreach (array_merge([$vorab], $schwestern) as $i => $zeile) {
                $frisch = RecDispoJobOfferShift::query()->whereKey($zeile->id)->with('offer')->lockForUpdate()->first();
                $shift = $shifts[(int) $zeile->shift_id] ?? null;
                $grund = ($frisch === null || $shift === null || $frisch->declined_reason !== self::GRUND || $frisch->offer === null)
                    ? self::WEG
                    : self::platzFrei($shift, $frisch);
                if ($grund !== self::OK) {
                    // Hauptzeile scheitert → nichts anfassen (Review 20.11. I1): sonst kaeme die
                    // Schwester still zurueck und ginge ueber die andere Anfrage doch an ZAS.
                    if ($i === 0) {
                        return $grund;
                    }
                    continue;
                }
                $frisch->update([
                    'accepted_at'            => $frisch->ausgetragen_zusage_at ?? now(),
                    'ausgetragen_zusage_at'  => null,
                    'ausgetragen_by_user_id' => null,
                    'declined_at'            => null,
                    'declined_reason'        => null,
                    'declined_note'          => null,
                ]);
                $ergebnis ??= self::OK;
                $schichten[(int) $shift->id] = true;
            }
            self::nachzaehlen(array_keys($schichten));

            return $ergebnis ?? self::WEG;
        }, 3);
    }

    /**
     * Absage auf der Veranstaltungsseite (Punkt 3): Zusagen aus Wellen, die mit
     * diesen Einbuchungen verknuepft sind, werden ausgetragen. Wirft nie — die
     * Absage selbst ist dann schon gespeichert.
     *
     * @param list<int> $assignmentIds abgesagte Einbuchungen
     * @return int Anzahl ausgetragener Zusagen
     */
    public static function wegenAbsage(array $assignmentIds, ?int $userId): int
    {
        if ($assignmentIds === []) {
            return 0;
        }
        $n = 0;
        try {
            $zeilen = RecDispoJobOfferShift::query()
                ->whereIn('zas_assignment_id', $assignmentIds)
                ->whereNotNull('accepted_at')->whereNull('declined_at')
                ->with('offer.request')
                ->get();
        } catch (\Throwable $e) {
            self::warnen($assignmentIds, $e);

            return 0;
        }
        // Je Zeile eigener Versuch (Review 20.11. M5): ein Fehler laesst die uebrigen nicht zugesagt stehen.
        foreach ($zeilen as $z) {
            $request = $z->offer?->request;
            if ($request === null) {
                continue;
            }
            try {
                $n += self::ausfuehren($request, (int) $z->id, $userId, self::NOTIZ_ABSAGE)['anzahl'];
            } catch (\Throwable $e) {
                self::warnen($assignmentIds, $e);
            }
        }

        return $n;
    }

    /** @param list<int> $assignmentIds */
    private static function warnen(array $assignmentIds, \Throwable $e): void
    {
        try {
            Log::warning('[DispoJobZusageAustragen] Austragen nach Absage fehlgeschlagen', ['assignments' => $assignmentIds, 'error' => $e->getMessage()]);
        } catch (\Throwable) {
            // Ohne Logger (Tests) bleibt es still — die Absage selbst ist gespeichert.
        }
    }

    /**
     * Schicht-Schluessel, aus denen die Person ausgetragen wurde — ueber alle
     * Anfragen des Teams (Review 20.11. I2, Mail: „Wer ausgetragen wurde, bekommt
     * diese Schicht nicht noch einmal angeboten.“). Nur kuenftige Tage.
     *
     * @param list<int> $personIds rec_employee_id/anchor_employee_id der Person; leer = alle Personen
     * @return array<int, list<string>> Anker => schichtSchluessel()
     */
    public static function schluessel(?int $teamId, array $personIds = []): array
    {
        $heute = now()->toDateString();
        $out = [];
        RecDispoJobOfferShift::query()
            ->where('declined_reason', self::GRUND)
            ->when($personIds !== [], fn ($q) => $q->whereHas('offer', fn ($o) => $o->whereIn('rec_employee_id', $personIds)->orWhereIn('anchor_employee_id', $personIds)))
            ->whereHas('shift', fn ($q) => $q->whereDate('datum', '>=', $heute)
                ->when($teamId !== null, fn ($s) => $s->whereHas('request', fn ($r) => $r->where('team_id', $teamId))))
            ->with(['offer:id,anchor_employee_id', 'shift:id,rec_dispo_event_id,datum,von,bis,taetigkeit'])
            ->get(['id', 'offer_id', 'shift_id'])
            ->each(function ($os) use (&$out) {
                if ($os->offer === null || $os->shift === null) {
                    return;
                }
                $out[(int) $os->offer->anchor_employee_id][] = DispoJobRecipientPlanner::schichtSchluessel(
                    $os->shift->rec_dispo_event_id, $os->shift->datum->format('Y-m-d'), $os->shift->von, $os->shift->bis, $os->shift->taetigkeit
                );
            });

        return array_map(fn ($keys) => array_values(array_unique($keys)), $out);
    }

    /** @return self::OK|self::VOLL|self::TAG */
    private static function platzFrei(RecDispoJobShift $shift, RecDispoJobOfferShift $zeile): string
    {
        if ($shift->gedeckt_manuell_at !== null) {
            return self::VOLL;
        }
        $request = RecDispoJobRequest::query()->whereKey($shift->request_id)->first();
        if ($request !== null && $request->modus === RecDispoJobRequest::MODUS_ZUERST && (int) $shift->gesucht > 0) {
            $zusagen = RecDispoJobOfferShift::query()->where('shift_id', $shift->id)->whereNotNull('accepted_at')->lockForUpdate()->count();
            if ($zusagen >= (int) $shift->gesucht) {
                return self::VOLL;
            }
        }
        $personIds = self::personIds($zeile);
        if (JobOffersZusage::tagBelegt($shift, $personIds)) {
            return self::TAG;
        }

        return self::OK;
    }

    /**
     * Angebots-Zeilen derselben Person fuer DIESELBE Schicht (Schluessel) in
     * anderen Anfragen, die laufen.
     *
     * @return list<RecDispoJobOfferShift>
     */
    private static function schwestern(RecDispoJobOfferShift $zeile, \Closure $zustand): array
    {
        $s = $zeile->shift;
        $schluessel = DispoJobRecipientPlanner::schichtSchluessel($s->rec_dispo_event_id, $s->datum->format('Y-m-d'), $s->von, $s->bis, $s->taetigkeit);
        $personIds = self::personIds($zeile);

        $q = RecDispoJobOfferShift::query()
            ->with(['shift', 'offer'])
            ->where('id', '!=', $zeile->id)
            ->whereHas('offer', fn ($o) => $o->where('request_id', '!=', $zeile->offer->request_id)
                ->where(fn ($p) => $p->whereIn('rec_employee_id', $personIds)->orWhereIn('anchor_employee_id', $personIds)))
            ->whereHas('shift', fn ($q) => $q->where('rec_dispo_event_id', $s->rec_dispo_event_id)
                ->whereDate('datum', $s->datum->format('Y-m-d'))
                ->whereHas('request', fn ($r) => $r->where('status', RecDispoJobRequest::STATUS_LAEUFT)->whereNull('closed_at')));
        $zustand($q);

        return $q->get()
            ->filter(fn ($os) => $os->shift !== null && DispoJobRecipientPlanner::schichtSchluessel(
                $os->shift->rec_dispo_event_id, $os->shift->datum->format('Y-m-d'), $os->shift->von, $os->shift->bis, $os->shift->taetigkeit
            ) === $schluessel)
            ->values()->all();
    }

    /** @return list<int> */
    private static function personIds(RecDispoJobOfferShift $zeile): array
    {
        $offer = $zeile->offer;

        return array_values(array_unique(array_filter([(int) ($offer?->rec_employee_id ?? 0), (int) ($offer?->anchor_employee_id ?? 0)])));
    }

    /**
     * @param list<RecDispoJobOfferShift> $zeilen
     * @return array<int, RecDispoJobShift>
     */
    private static function schichtenSperren(array $zeilen): array
    {
        $ids = array_values(array_unique(array_map(fn ($z) => (int) $z->shift_id, $zeilen)));
        sort($ids);
        $out = [];
        foreach ($ids as $id) {
            $shift = RecDispoJobShift::query()->whereKey($id)->lockForUpdate()->first();
            if ($shift !== null) {
                $out[$id] = $shift;
            }
        }

        return $out;
    }

    /** @param list<int> $shiftIds */
    private static function nachzaehlen(array $shiftIds): void
    {
        foreach ($shiftIds as $id) {
            $shift = RecDispoJobShift::query()->find($id);
            $request = $shift !== null ? RecDispoJobRequest::query()->find($shift->request_id) : null;
            if ($shift !== null && $request !== null) {
                DispoJobGedeckt::nachzaehlen($request, $shift);
            }
        }
    }
}
