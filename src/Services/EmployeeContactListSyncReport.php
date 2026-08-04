<?php

namespace Platform\Recruiting\Services;

/**
 * Ergebnis eines Kontaktbuch-Syncs. Felder sind Vertrag für Command/Panel —
 * siehe docs/superpowers/specs/2026-08-04-ma-kontaktbuch-design.md.
 */
final readonly class EmployeeContactListSyncReport
{
    public function __construct(
        public int $added,
        public int $removed,
        public int $normalized,
        public int $unchanged,
        public int $skipped_without_contact,
        public int $hidden_from_carddav,
        public int $ambiguous_multi_link,
        public bool $dry_run,
        public string $status, // ok | partial | not_configured | list_missing | guard_tripped
    ) {
    }
}
