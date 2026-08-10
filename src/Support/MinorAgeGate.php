<?php

namespace Platform\Recruiting\Support;

/**
 * Altersprüfung für den Bewerbungs-Funnel (Jugendschutz):
 *
 *  - unter 16        → VERDICT_REJECT  (zwingende Auto-Absage)
 *  - 16 bis unter 18 → VERDICT_REVIEW  (HR-Schreibtisch, Phase-Aufstieg erst nach Freigabe)
 *  - ab 18           → VERDICT_PASS
 *  - kein/kaputtes/unplausibles Datum → VERDICT_UNKNOWN (kein Auto-Verdikt, Backstop blockt Automatik)
 *
 * Pure PHP, keine Laravel-Abhängigkeit — Stichtag wird hereingereicht.
 * Die Grenzen sind gesetzliche Logik und bewusst NICHT konfigurierbar.
 */
final class MinorAgeGate
{
    public const REJECT_UNDER = 16;
    public const REVIEW_UNDER = 18;

    public const VERDICT_REJECT = 'reject';
    public const VERDICT_REVIEW = 'review';
    public const VERDICT_PASS = 'pass';
    public const VERDICT_UNKNOWN = 'unknown';

    public static function verdict(mixed $birthDate, \DateTimeImmutable $today): string
    {
        $parsed = self::parse($birthDate);
        if ($parsed === null || $parsed > $today) {
            return self::VERDICT_UNKNOWN;
        }

        $age = (int) $parsed->diff($today)->y;

        if ($age < self::REJECT_UNDER) {
            return self::VERDICT_REJECT;
        }
        if ($age < self::REVIEW_UNDER) {
            return self::VERDICT_REVIEW;
        }

        return self::VERDICT_PASS;
    }

    private static function parse(mixed $birthDate): ?\DateTimeImmutable
    {
        if (!is_string($birthDate) || trim($birthDate) === '') {
            return null;
        }

        $raw = trim($birthDate);
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($parsed !== false && $parsed->format($format) === $raw) {
                return $parsed;
            }
        }

        return null;
    }
}
