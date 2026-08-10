<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Pure Entscheidungslogik für das Umhängen fehlgelinkter WhatsApp-Threads
 * (Kontext = nackter CrmContact statt Bewerber) auf ihre Bewerber.
 *
 * Telefonnummern-Vergleich über die letzten 10 Ziffern, weil dieselbe Nummer
 * in mehreren Formaten gespeichert ist (+49..., 0..., mit Leerzeichen/Slash).
 */
final class ThreadRelinkPlanner
{
    private const MIN_DIGITS = 8;
    private const SIGNIFICANT_DIGITS = 10;

    /**
     * Reduziert eine Nummer auf ihre letzten 10 Ziffern (Vergleichsschlüssel).
     * Null bei fehlender/zu kurzer Nummer — zu wenige Ziffern wären als
     * Suffix-Match nicht mehr eindeutig.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || strlen($digits) < self::MIN_DIGITS) {
            return null;
        }

        return substr($digits, -self::SIGNIFICANT_DIGITS);
    }

    public static function phonesMatch(?string $a, ?string $b): bool
    {
        $na = self::normalizePhone($a);
        $nb = self::normalizePhone($b);

        return $na !== null && $na === $nb;
    }

    /**
     * Wählt aus mehreren Bewerber-Kandidaten (gleiche Nummer, z.B. Mehrfach-
     * Bewerbung) den Ziel-Bewerber: aktive vor inaktiven, dann der neueste
     * (höchste ID). Null bei leerer Liste.
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
            return [(int) !$a['is_active'], -$a['id']] <=> [(int) !$b['is_active'], -$b['id']];
        });

        return $candidates[0];
    }
}
