<?php

namespace Platform\Recruiting\Support;

/**
 * Wann ein Termin als "vorbei" gilt — die Grenze, an der Termin- und
 * Schulungsliste ihren eingeklappten Altbestand abtrennen.
 *
 * Vorbei ist ein Termin, wenn sein ENDE hinter uns liegt, nicht schon sein
 * Start. Ein Termin, der gerade laeuft, waere sonst genau in dem Moment
 * eingeklappt, in dem man ihn braucht. Fehlt das Ende, bleibt nur der Start.
 */
final class InterviewPastness
{
    public static function isPast(
        \DateTimeInterface $startsAt,
        ?\DateTimeInterface $endsAt,
        \DateTimeInterface $now,
    ): bool {
        return ($endsAt ?? $startsAt) < $now;
    }
}
