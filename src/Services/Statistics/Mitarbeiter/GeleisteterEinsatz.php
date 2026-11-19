<?php

namespace Platform\Recruiting\Services\Statistics\Mitarbeiter;

use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * DIE Regel, welche Einbuchung für die Mitarbeiter-Statistik zählt — an genau einer Stelle.
 *
 * Markus 19.11.2026: „Bitte auch die disponierten, noch nicht beendeten Termine mit beachten.
 * Dann ist der Mitarbeiter produktiv, kommuniziert mit uns und bucht Einsätze.“ ZAS setzt
 * „beendet“ von Hand (Olaf 19.11.2026), rund 10 % alter Einbuchungen bleiben deshalb dauerhaft
 * auf „Auftrag“ — beide Stati zählen.
 *
 * Zählt NICHT: Angebot (nicht disponiert), Storno, in ZAS verschwunden, zur Löschung gemeldet,
 * abgesagt.
 *
 * Kommt die Zeiterfassung, ändert sich hier nichts: wer gestempelt hat, war ohnehin eingebucht.
 */
final class GeleisteterEinsatz
{
    /** @var list<int> */
    public const STATI = [RecDispoAssignment::STATUS_AUFTRAG, RecDispoAssignment::STATUS_BEENDET];

    public static function zaehlt(?int $statusId, mixed $missingSince, mixed $deletionMarkedAt, mixed $declinedAt): bool
    {
        return $statusId !== null
            && in_array($statusId, self::STATI, true)
            && $missingSince === null
            && $deletionMarkedAt === null
            && $declinedAt === null;
    }

    /**
     * Dieselbe Regel als Abfrage-Einschränkung (für große Mengen). Ein Test hält beide Formen
     * deckungsgleich.
     *
     * @template T of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     * @param T $query
     * @return T
     */
    public static function einschraenken($query, string $tabelle = 'rec_dispo_assignments')
    {
        return $query
            ->whereIn($tabelle . '.status_id', self::STATI)
            ->whereNull($tabelle . '.missing_since')
            ->whereNull($tabelle . '.deletion_marked_at')
            ->whereNull($tabelle . '.declined_at');
    }
}
