<?php

namespace Platform\Recruiting\Services\Comms;

use Carbon\Carbon;

/**
 * Aufloesung der Team-Timezone fuer datumsbasierte Features (z.B. OOO-
 * Abwesenheitsmodus). Quelle: RecApplicantSettings-Key 'comms_timezone'
 * (vom Aufrufer gereicht, default null), Fallback Europe/Berlin.
 *
 * Der Fallback lebt AUSSCHLIESSLICH hier — Call-Sites kennen keine
 * Timezone-Literale. Ein spaeterer Wechsel auf eine Core-Team-Timezone
 * ist damit ein Ein-Zeilen-Fix in dieser Klasse.
 */
final class TeamClock
{
    public const FALLBACK_TIMEZONE = 'Europe/Berlin';

    /** Loest die konfigurierte Timezone auf (null/leer → Fallback). */
    public static function resolveTimezone(?string $timezone): string
    {
        return ($timezone !== null && trim($timezone) !== '') ? $timezone : self::FALLBACK_TIMEZONE;
    }

    /** Heutiges Datum ('Y-m-d') in der Team-Timezone. */
    public static function today(?string $timezone): string
    {
        return Carbon::now(self::resolveTimezone($timezone))->format('Y-m-d');
    }
}
