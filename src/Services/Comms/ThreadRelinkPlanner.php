<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Recruiting\Support\DuplicateApplicantGuard;

/**
 * Pure Entscheidungslogik für das Umhängen fehlgelinkter WhatsApp-Threads
 * (Kontext = nackter CrmContact statt Bewerber) auf ihre Bewerber.
 *
 * Nummern-Vergleich über die kanonische Digit-Form des DuplicateApplicantGuard
 * (ländercode-präfixiert, formattolerant) — EINE kanonische Telefonform im
 * Modul, keine zweite Suffix-Heuristik daneben.
 */
final class ThreadRelinkPlanner
{
    private const MIN_DIGITS = 8;

    /**
     * Kanonischer Vergleichsschlüssel einer Nummer (z. B. '491637742867').
     * Null bei fehlender/zu kurzer Nummer — Kurz-Fragmente wären als
     * Match-Schlüssel nicht mehr eindeutig.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $canonical = DuplicateApplicantGuard::canonicalDigits($phone);
        if ($canonical === null || strlen($canonical) < self::MIN_DIGITS) {
            return null;
        }

        return $canonical;
    }

    /**
     * Wählt aus mehreren Bewerber-Kandidaten (gleiche Nummer/gleicher Kontakt,
     * z.B. Mehrfach-Bewerbung) den Ziel-Bewerber: aktive vor inaktiven, dann
     * der Senior (kleinste ID) — dieselbe Eigentümer-Konvention wie
     * DuplicateApplicantGuard, damit Chat-Kontext und Dedup-/Reminder-Logik
     * auf denselben Bewerber zeigen. Null bei leerer Liste.
     *
     * @param array<array{id: int, is_active: bool}> $candidates
     * @return array{id: int, is_active: bool}|null
     */
    public static function chooseApplicant(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            return [(int) !$a['is_active'], $a['id']] <=> [(int) !$b['is_active'], $b['id']];
        });

        return $candidates[0];
    }
}
