<?php

namespace Platform\Recruiting\Support;

/**
 * Deterministische Kontaktwahl und einheitliches Namensformat fuer die
 * Terminseite (Buchungs- UND Nachbereitungs-Modus).
 *
 * Warum deterministisch: crmContactLinks ist ein morphMany ohne Ordering
 * (Spec F11), ->first() liefert also keine stabile Reihenfolge. Ohne feste
 * Wahl (kleinste contact_id — gleiches Prinzip wie
 * EmployeeContactListSyncService::resolveDesired) kann sich die Sortierung
 * der Liste zwischen zwei Renderings aendern.
 *
 * Warum Anzeige und Sortierschluessel aus derselben Funktion: sortiert man
 * nach Nachname, zeigt aber "Vorname Nachname", sieht die Liste fuer den
 * Nutzer unsortiert aus.
 *
 * $candidates: Liste von
 *   ['contact_id' => int, 'first_name' => ?string, 'last_name' => ?string, 'full_name' => ?string]
 */
final class ApplicantContactName
{
    public const UNKNOWN = 'Unbekannt';

    /** Sortiert Namenlose hinter alle benannten Eintraege. */
    private const SORT_LAST = "\xff";

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    public static function pick(array $candidates): ?array
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (!isset($candidate['contact_id'])) {
                continue;
            }
            if ($best === null || (int) $candidate['contact_id'] < (int) $best['contact_id']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @param  array<int, array<string, mixed>>  $candidates */
    public static function display(array $candidates): string
    {
        $contact = self::pick($candidates);
        if ($contact === null) {
            return self::UNKNOWN;
        }

        $last  = trim((string) ($contact['last_name'] ?? ''));
        $first = trim((string) ($contact['first_name'] ?? ''));

        if ($last !== '' && $first !== '') {
            return $last . ', ' . $first;
        }
        if ($last !== '') {
            return $last;
        }
        if ($first !== '') {
            return $first;
        }

        $full = trim((string) ($contact['full_name'] ?? ''));

        return $full !== '' ? $full : self::UNKNOWN;
    }

    /** @param  array<int, array<string, mixed>>  $candidates */
    public static function sortKey(array $candidates): string
    {
        $display = self::display($candidates);

        return $display === self::UNKNOWN
            ? self::SORT_LAST
            : mb_strtolower($display);
    }
}
