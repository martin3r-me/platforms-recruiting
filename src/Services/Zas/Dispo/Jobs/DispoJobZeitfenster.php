<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Zeitraum-Vorbelegung je Anlass (Markus 29./30.10.2026, Spec 2026-10-30 §1):
 * Dringend = ein Tag (morgen), Naechste Woche = Montag bis Sonntag der
 * kommenden Woche, Vier Wochen = naechster Montag bis zum Ende des Monats, in
 * den der 28. Tag faellt (nie kuerzer als vier Wochen). Rein, ohne Uhr.
 */
final class DispoJobZeitfenster
{
    public const VIER_WOCHEN_TAGE = 28;

    private const WOCHENTAGE = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    /** @return ?array{von: string, bis: string} Y-m-d; null = unbekannter Anlass (Aufrufer behaelt seinen Standard) */
    public static function fuer(string $anlass, DateTimeInterface $heute): ?array
    {
        $h = CarbonImmutable::instance($heute)->startOfDay();
        $montag = $h->next(CarbonInterface::MONDAY);

        return match ($anlass) {
            'dringend'       => ['von' => $h->addDay()->toDateString(), 'bis' => $h->addDay()->toDateString()],
            'naechste_woche' => ['von' => $montag->toDateString(), 'bis' => $montag->addDays(6)->toDateString()],
            'vier_wochen'    => ['von' => $montag->toDateString(), 'bis' => $montag->addDays(self::VIER_WOCHEN_TAGE - 1)->endOfMonth()->toDateString()],
            default          => null,
        };
    }

    /** Hinweis unter dem Zeitraum, z. B. „Vorgeschlagen für „Nächste Woche“: Mo 02.11. bis So 08.11.“; null bei unbekanntem Anlass. */
    public static function hinweis(string $anlass, DateTimeInterface $heute): ?string
    {
        $f = self::fuer($anlass, $heute);
        if ($f === null) {
            return null;
        }
        $zeitraum = $f['von'] === $f['bis']
            ? self::tag($f['von'])
            : self::tag($f['von']) . ' bis ' . self::tag($f['bis']);

        return 'Vorgeschlagen für „' . DispoJobAnlass::label($anlass) . '“: ' . $zeitraum . ' Du kannst den Zeitraum ändern.';
    }

    private static function tag(string $ymd): string
    {
        $d = CarbonImmutable::parse($ymd);

        return self::WOCHENTAGE[$d->dayOfWeekIso] . ' ' . $d->format('d.m.');
    }
}
