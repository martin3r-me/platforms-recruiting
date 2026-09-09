<?php

namespace Platform\Recruiting\Support;

/**
 * Personen-Paarung fuer Mitarbeiter-Datensaetze (Chaieb-Befund 10.09.2026):
 * ZAS bedient zwei Firmen (RG und MA) — eine Person kann zwei
 * rec_employees-Datensaetze mit zwei Personalnummern haben. Diese Regel
 * sagt, wann zwei Datensaetze OHNE menschliche Bestaetigung sicher derselbe
 * Mensch sind: voller Name UND Geburtsdatum exakt gleich.
 *
 * Bewusst NUR Gross/Klein und Rand-Whitespace normalisiert: die Warnung vor
 * Namens-Automatik (report-signed-without-employee) galt Namens-VARIANTEN
 * („Leni Runtenberg" vs. „Leni Emily Runtenberg") — die matchen hier gerade
 * nicht und bleiben Handarbeit ueber den Audit-Report.
 */
final class PersonPairing
{
    public static function nameKey(?string $firstName, ?string $lastName): ?string
    {
        $first = mb_strtolower(trim((string) $firstName));
        $last = mb_strtolower(trim((string) $lastName));
        if ($first === '' || $last === '') {
            return null;
        }

        return $first . '|' . $last;
    }

    /** @param array{first_name: ?string, last_name: ?string, birth_date: ?string} $a */
    public static function isExactMatch(array $a, array $b): bool
    {
        $keyA = self::nameKey($a['first_name'] ?? null, $a['last_name'] ?? null);
        $keyB = self::nameKey($b['first_name'] ?? null, $b['last_name'] ?? null);
        $geburtA = trim((string) ($a['birth_date'] ?? ''));

        return $keyA !== null
            && $keyA === $keyB
            && $geburtA !== ''
            && $geburtA === trim((string) ($b['birth_date'] ?? ''));
    }
}
