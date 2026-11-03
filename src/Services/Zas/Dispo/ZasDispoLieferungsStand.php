<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Platform\Recruiting\Models\RecZasDispoInboundFile;

/**
 * „ZAS-Stand“ fuer die Disposition (Spec 2026-11-04): von wann die letzte
 * Lieferung mit Einbuchungen ist und ob eine neuere noch verarbeitet wird.
 *
 * Vorfall 03.11.: Olafs Export scheiterte dreimal in Access, HCM stand bis
 * 14:51 auf dem Stand vom Vortag 14:30, und niemand sah das — Sheran schickte
 * Bestaetigungen auf alter Grundlage. Dateien ohne Dispo-Zeilen (z. B. der
 * Block {Zeiten}) zaehlen hier bewusst nicht als Stand.
 */
final class ZasDispoLieferungsStand
{
    public const GELB_AB_STUNDEN = 4;
    public const ROT_AB_STUNDEN = 24;

    /** So weit zurueck wird nach einer Lieferung mit Einbuchungen gesucht. */
    private const SUCHTIEFE = 60;

    /**
     * @return ?array{datei_id:int, zeit:CarbonInterface, einbuchungen:int, stufe:'ok'|'gelb'|'rot', offen:?CarbonInterface}
     *         null = noch nie eine Lieferung mit Einbuchungen; offen = Eingang der neuesten Datei, die noch wartet
     */
    public static function aktuell(?CarbonInterface $jetzt = null): ?array
    {
        $jetzt = $jetzt ?? Carbon::now();

        $letzte = null;
        $kandidaten = RecZasDispoInboundFile::query()
            ->where('is_test', false)
            ->where('parse_status', 'processed')
            ->orderByDesc('id')
            ->limit(self::SUCHTIEFE)
            ->get();
        foreach ($kandidaten as $f) {
            if ($f->einbuchungen() !== null) {
                $letzte = $f;
                break;
            }
        }
        if ($letzte === null) {
            return null;
        }

        // Nur Dateien, die der Hintergrund-Job auch anfassen wird (neuer als
        // die letzte verarbeitete) — alte Liegengebliebene zaehlen nicht als „offen“.
        $offen = RecZasDispoInboundFile::query()
            ->where('is_test', false)
            ->whereNull('processed_at')
            ->where('id', '>', (int) RecZasDispoInboundFile::query()->where('is_test', false)->whereNotNull('processed_at')->max('id'))
            ->orderByDesc('id')
            ->first();

        $zeit = Carbon::parse($letzte->created_at);
        $stunden = $zeit->diffInSeconds($jetzt) / 3600;

        return [
            'datei_id'     => (int) $letzte->id,
            'zeit'         => $zeit,
            'einbuchungen' => (int) $letzte->einbuchungen(),
            'stufe'        => $stunden >= self::ROT_AB_STUNDEN ? 'rot' : ($stunden >= self::GELB_AB_STUNDEN ? 'gelb' : 'ok'),
            'offen'        => $offen ? Carbon::parse($offen->created_at) : null,
        ];
    }

    /**
     * Fertiger Text fuer die Oberfläche, z. B.
     * „ZAS-Stand: Di 03.11. 18:15 · 4.195 Einbuchungen · neue Lieferung von 19:24 wird verarbeitet“.
     *
     * @return array{text:string, stufe:'ok'|'gelb'|'rot'|'leer'}
     */
    public static function anzeige(?CarbonInterface $jetzt = null): array
    {
        $s = self::aktuell($jetzt);
        if ($s === null) {
            return ['text' => 'ZAS-Stand: noch keine Lieferung mit Einbuchungen', 'stufe' => 'leer'];
        }

        $text = 'ZAS-Stand: ' . self::wochentag($s['zeit']) . ' ' . $s['zeit']->format('d.m. H:i')
            . ' · ' . number_format($s['einbuchungen'], 0, ',', '.') . ' Einbuchungen';
        if ($s['stufe'] !== 'ok') {
            $text .= ' · ' . self::alter($s['zeit'], $jetzt ?? Carbon::now()) . ' alt';
        }
        if ($s['offen'] !== null) {
            $text .= ' · neue Lieferung von ' . $s['offen']->format('H:i') . ' wird verarbeitet';
        }

        return ['text' => $text, 'stufe' => $s['stufe']];
    }

    private static function wochentag(CarbonInterface $t): string
    {
        return ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][$t->dayOfWeekIso - 1];
    }

    private static function alter(CarbonInterface $zeit, CarbonInterface $jetzt): string
    {
        $stunden = (int) floor($zeit->diffInSeconds($jetzt) / 3600);
        if ($stunden < 48) {
            return $stunden . ' Std.';
        }

        return intdiv($stunden, 24) . ' Tage';
    }
}
