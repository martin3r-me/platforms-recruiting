<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * Zusage auf eine andere Schicht derselben VA am selben Tag ziehen (Markus
 * 06.11.: „viele melden sich auf 23 Uhr, mehr als wir brauchen — die auf 01
 * ziehen koennen“). Nur innerhalb derselben Anfrage und nur, solange die
 * Zusage noch nicht in ZAS ist (nicht uebergeben, nicht angekommen); sonst
 * muss die Umbuchung in ZAS passieren und kommt mit der naechsten Lieferung.
 *
 * Die Zusage wandert mitsamt Zeitpunkt; der Haken „in ZAS eingetragen“ wandert
 * nicht (die neue Schicht ist in ZAS noch nicht eingetragen). Hatte die Person
 * die Zielschicht nicht im Angebot (z. B. an dem Tag schon gebucht), wird die
 * Angebots-Zeile angelegt — der Disponent entscheidet das bewusst.
 */
final class DispoJobZusageVerschieben
{
    public const OK = 'ok';
    public const WEG = 'weg';
    public const IN_ZAS = 'in_zas';
    public const ZIEL = 'ziel';
    public const SCHON = 'schon';

    public const TEXTE = [
        self::OK     => 'Zusage verschoben.',
        self::WEG    => 'Diese Zusage gibt es nicht mehr.',
        self::IN_ZAS => 'Die Zusage ist schon an ZAS übergeben — bitte in ZAS umbuchen.',
        self::ZIEL   => 'Die Zielschicht passt nicht (andere Veranstaltung oder anderer Tag).',
        self::SCHON  => 'Die Person hat die Zielschicht schon zugesagt.',
    ];

    /**
     * Zielschichten fuer eine Zusage-Zeile: andere Schichten der Anfrage auf derselben VA am selben Tag.
     *
     * @param iterable<RecDispoJobShift> $schichten alle Schichten der Anfrage
     * @return list<array{id:int, label:string}>
     */
    public static function ziele(RecDispoJobShift $quelle, iterable $schichten): array
    {
        $out = [];
        foreach ($schichten as $s) {
            if ((int) $s->id === (int) $quelle->id || !self::gleicherTag($quelle, $s)) {
                continue;
            }
            $out[] = ['id' => (int) $s->id, 'label' => DispoJobZeit::spanne($s->von, $s->bis) . ' ' . $s->taetigkeit];
        }

        return $out;
    }

    public static function erlaubt(RecDispoJobOfferShift $zeile): bool
    {
        return $zeile->accepted_at !== null && $zeile->declined_at === null
            && $zeile->zas_assignment_id === null && $zeile->zas_exported_at === null;
    }

    /** @return self::OK|self::WEG|self::IN_ZAS|self::ZIEL|self::SCHON */
    public static function ausfuehren(RecDispoJobRequest $request, int $offerShiftId, int $zielShiftId): string
    {
        return DB::transaction(function () use ($request, $offerShiftId, $zielShiftId) {
            $quelle = RecDispoJobOfferShift::query()
                ->whereKey($offerShiftId)
                ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
                ->with('shift')
                ->lockForUpdate()
                ->first();
            if ($quelle === null || $quelle->shift === null || $quelle->accepted_at === null || $quelle->declined_at !== null) {
                return self::WEG;
            }
            if (!self::erlaubt($quelle)) {
                return self::IN_ZAS;
            }
            $ziel = RecDispoJobShift::query()->whereKey($zielShiftId)->where('request_id', $request->id)->first();
            if ($ziel === null || (int) $ziel->id === (int) $quelle->shift_id || !self::gleicherTag($quelle->shift, $ziel)) {
                return self::ZIEL;
            }

            $zielZeile = RecDispoJobOfferShift::query()
                ->where('offer_id', $quelle->offer_id)->where('shift_id', $ziel->id)
                ->lockForUpdate()->first();
            if ($zielZeile !== null && $zielZeile->accepted_at !== null && $zielZeile->declined_at === null) {
                return self::SCHON;
            }

            $werte = ['accepted_at' => $quelle->accepted_at, 'declined_at' => null, 'declined_reason' => null, 'declined_note' => null];
            if ($zielZeile === null) {
                RecDispoJobOfferShift::query()->create(['offer_id' => $quelle->offer_id, 'shift_id' => $ziel->id] + $werte);
            } else {
                $zielZeile->update($werte);
            }
            $quelle->update(['accepted_at' => null, 'zas_entered_at' => null, 'zas_entered_by_user_id' => null]);

            return self::OK;
        }, 3);
    }

    private static function gleicherTag(RecDispoJobShift $a, RecDispoJobShift $b): bool
    {
        return (int) $a->rec_dispo_event_id === (int) $b->rec_dispo_event_id
            && $a->datum->format('Y-m-d') === $b->datum->format('Y-m-d');
    }
}
