<?php

namespace Platform\Recruiting\Zeit;

/** Reine Rechnerei ohne Datenbank. Alle Ergebnisse in ganzen Minuten, nie negativ. */
final class ZeitRechner
{
    public static function anwesendMinuten(?\DateTimeInterface $kommen, ?\DateTimeInterface $gehen): ?int
    {
        if ($kommen === null || $gehen === null) {
            return null;
        }

        return max(0, intdiv($gehen->getTimestamp() - $kommen->getTimestamp(), 60));
    }

    /** Gesetzter Wert (auch 0) gewinnt; sonst Regel; ohne Anwesenheit 0. */
    public static function pauseMinuten(?int $gesetzt, ?int $anwesend, ZeitRegeln $regeln): int
    {
        if ($gesetzt !== null) {
            return $gesetzt;
        }
        if ($anwesend === null) {
            return 0;
        }

        return $regeln->pauseMinuten($anwesend);
    }

    public static function arbeitsMinuten(?int $anwesend, int $pause): ?int
    {
        if ($anwesend === null) {
            return null;
        }

        return max(0, $anwesend - $pause);
    }

    public static function verspaetungMinuten(?\DateTimeInterface $kommen, ?\DateTimeInterface $sollVon): int
    {
        if ($kommen === null || $sollVon === null) {
            return 0;
        }

        return max(0, intdiv($kommen->getTimestamp() - $sollVon->getTimestamp(), 60));
    }

    /** 465 -> "7:45", 5 -> "0:05" */
    public static function alsStunden(int $minuten): string
    {
        $minuten = max(0, $minuten);

        return intdiv($minuten, 60) . ':' . str_pad((string) ($minuten % 60), 2, '0', STR_PAD_LEFT);
    }
}
