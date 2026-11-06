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
 * Zusage noch nicht in ZAS ist (nicht uebergeben, kein Haken, nicht
 * angekommen); sonst muss die Umbuchung in ZAS passieren und kommt mit der
 * naechsten Lieferung.
 *
 * Die Zusage wandert mitsamt Zeitpunkt. Hatte die Person die Zielschicht
 * nicht im Angebot (z. B. an dem Tag schon gebucht), wird die Angebots-Zeile
 * angelegt — der Disponent entscheidet das bewusst. Verweigert wird (Review
 * 06.11.): Zielschicht voll/gedeckt, von der Person abgesagt, schon zugesagt,
 * oder dieselbe Schicht ist in einer anderen laufenden Anfrage mitzugesagt
 * (§9) — sonst haette die Person zwei Zusagen an einem Tag (§6).
 * Vollzaehlung im Modus „zuerst“ wird an Quelle und Ziel nachgezogen.
 */
final class DispoJobZusageVerschieben
{
    public const OK = 'ok';
    public const WEG = 'weg';
    public const IN_ZAS = 'in_zas';
    public const ZIEL = 'ziel';
    public const SCHON = 'schon';
    public const VOLL = 'voll';
    public const ABGESAGT = 'abgesagt';
    public const SCHWESTER = 'schwester';

    public const TEXTE = [
        self::OK        => 'Zusage verschoben.',
        self::WEG       => 'Diese Zusage gibt es nicht mehr.',
        self::IN_ZAS    => 'Die Zusage steht schon in ZAS (übergeben oder eingetragen) — bitte dort umbuchen. Ist der Haken falsch, erst den Haken entfernen.',
        self::ZIEL      => 'Die Zielschicht passt nicht (andere Veranstaltung oder anderer Tag).',
        self::SCHON     => 'Die Person hat die Zielschicht schon zugesagt.',
        self::VOLL      => 'Die Zielschicht ist schon voll oder als gedeckt markiert — erst wieder öffnen.',
        self::ABGESAGT  => 'Die Person hat für die Zielschicht abgesagt („Kann nicht“).',
        self::SCHWESTER => 'Dieselbe Schicht ist in einer anderen laufenden Anfrage mitzugesagt — dort zuerst klären.',
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

    /** Noch nicht in ZAS: weder uebergeben noch von Hand eingetragen noch angekommen. */
    public static function erlaubt(RecDispoJobOfferShift $zeile): bool
    {
        return $zeile->accepted_at !== null && $zeile->declined_at === null
            && $zeile->zas_assignment_id === null && $zeile->zas_exported_at === null && $zeile->zas_entered_at === null;
    }

    /** @return self::OK|self::WEG|self::IN_ZAS|self::ZIEL|self::SCHON|self::VOLL|self::ABGESAGT|self::SCHWESTER */
    public static function ausfuehren(RecDispoJobRequest $request, int $offerShiftId, int $zielShiftId): string
    {
        return DB::transaction(function () use ($request, $offerShiftId, $zielShiftId) {
            $quelle = RecDispoJobOfferShift::query()
                ->whereKey($offerShiftId)
                ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
                ->with(['shift', 'offer'])
                ->lockForUpdate()
                ->first();
            if ($quelle === null || $quelle->shift === null || $quelle->offer === null || $quelle->accepted_at === null || $quelle->declined_at !== null) {
                return self::WEG;
            }
            if (!self::erlaubt($quelle)) {
                return self::IN_ZAS;
            }
            $ziel = RecDispoJobShift::query()->whereKey($zielShiftId)->where('request_id', $request->id)->lockForUpdate()->first();
            if ($ziel === null || (int) $ziel->id === (int) $quelle->shift_id || !self::gleicherTag($quelle->shift, $ziel)) {
                return self::ZIEL;
            }
            if ($ziel->closed_at !== null || $ziel->gedeckt_manuell_at !== null) {
                return self::VOLL;
            }
            if (self::schwesterZugesagt($quelle)) {
                return self::SCHWESTER;
            }

            $zielZeile = RecDispoJobOfferShift::query()
                ->where('offer_id', $quelle->offer_id)->where('shift_id', $ziel->id)
                ->lockForUpdate()->first();
            if ($zielZeile !== null && $zielZeile->declined_at !== null) {
                return self::ABGESAGT;
            }
            if ($zielZeile !== null && $zielZeile->accepted_at !== null) {
                return self::SCHON;
            }

            if ($zielZeile === null) {
                RecDispoJobOfferShift::query()->create(['offer_id' => $quelle->offer_id, 'shift_id' => $ziel->id, 'accepted_at' => $quelle->accepted_at]);
            } else {
                $zielZeile->update(['accepted_at' => $quelle->accepted_at]);
            }
            $quelle->update(['accepted_at' => null]);

            DispoJobGedeckt::nachzaehlen($request, $ziel->fresh());
            DispoJobGedeckt::nachzaehlen($request, $quelle->shift->fresh());

            return self::OK;
        }, 3);
    }

    /**
     * Dieselbe Schicht (Schluessel inkl. Ende) in einer ANDEREN laufenden Anfrage
     * von derselben Person zugesagt (Mit-Zusage nach §9)?
     */
    private static function schwesterZugesagt(RecDispoJobOfferShift $quelle): bool
    {
        $s = $quelle->shift;
        $schluessel = DispoJobRecipientPlanner::schichtSchluessel($s->rec_dispo_event_id, $s->datum->format('Y-m-d'), $s->von, $s->bis, $s->taetigkeit);
        $personIds = array_values(array_unique(array_filter([(int) $quelle->offer->rec_employee_id, (int) $quelle->offer->anchor_employee_id])));

        return RecDispoJobOfferShift::query()
            ->whereNotNull('accepted_at')->whereNull('declined_at')
            ->whereHas('offer', fn ($q) => $q->where('request_id', '!=', $quelle->offer->request_id)
                ->where(fn ($p) => $p->whereIn('rec_employee_id', $personIds)->orWhereIn('anchor_employee_id', $personIds)))
            ->whereHas('shift', fn ($q) => $q->where('rec_dispo_event_id', $s->rec_dispo_event_id)
                ->whereDate('datum', $s->datum->format('Y-m-d'))
                ->whereHas('request', fn ($r) => $r->where('status', RecDispoJobRequest::STATUS_LAEUFT)->whereNull('closed_at')))
            ->with('shift')
            ->get()
            ->contains(fn ($os) => $os->shift !== null && DispoJobRecipientPlanner::schichtSchluessel(
                $os->shift->rec_dispo_event_id, $os->shift->datum->format('Y-m-d'), $os->shift->von, $os->shift->bis, $os->shift->taetigkeit
            ) === $schluessel);
    }

    private static function gleicherTag(RecDispoJobShift $a, RecDispoJobShift $b): bool
    {
        return (int) $a->rec_dispo_event_id === (int) $b->rec_dispo_event_id
            && $a->datum->format('Y-m-d') === $b->datum->format('Y-m-d');
    }
}
