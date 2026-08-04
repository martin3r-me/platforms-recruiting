<?php

namespace Platform\Recruiting\Services;

/**
 * Pures Diff-Ergebnis von EmployeeContactListSyncService::computeDiff().
 * Traegt die konkreten contact_id-Listen fuer die Write-Phase plus den
 * Guard-Zustand. Der nach aussen festgenagelte 9-Felder-SyncReport wird in
 * syncAll()/preview() aus DiffResult + Resolver-Zaehlern gebaut — die Zaehler
 * (skipped/hidden/multi-link) gehoeren bewusst NICHT hierher.
 */
final readonly class DiffResult
{
    public function __construct(
        public array $toAdd,        // contact_ids
        public array $toNormalize,  // contact_ids
        public array $toRemove,     // contact_ids
        public int $unchanged,
        public bool $guardTripped,
        public ?string $guardReason, // 'empty_soll' | 'threshold' | null
    ) {
    }
}
