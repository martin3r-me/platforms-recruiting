<?php

namespace Platform\Recruiting\Services;

/**
 * Pure Entscheidungslogik für den "Benachrichtige mich"-Klick auf der
 * öffentlichen Buchungsseite (Schulung-Warteliste).
 *
 * Regeln:
 *  - kein offener Eintrag + matchbare Orte  → create
 *  - kein offener Eintrag + keine Orte      → noop (kein Geister-Eintrag)
 *  - offener Eintrag, noch nicht benachrichtigt → noop (wartet bereits)
 *  - offener Eintrag, bereits benachrichtigt    → rearm (notified_at wieder
 *    auf NULL; Wunschorte-Snapshot auffrischen, sofern die neue Auflösung
 *    nicht leer ist — sonst den alten matchbaren Snapshot behalten)
 *
 * Bewusst ohne Laravel-Dependencies, damit rein PHPUnit-testbar
 * (Repo-Konvention: keine DB-/Feature-Tests im Modul).
 */
class WaitlistEnrollmentPlanner
{
    /**
     * Normalisiert das beschaftigungsort-Extra-Field zu einer sauberen
     * Ort-Liste; ohne gepflegte Wunschorte fällt sie auf den Ort der
     * primären Stelle zurück, damit die Zeile per whereJsonContains
     * matchbar bleibt.
     */
    public static function resolveWunschorte(mixed $extraField, ?string $fallbackOrt): array
    {
        $orte = is_array($extraField) ? $extraField : [$extraField];
        $orte = array_values(array_filter($orte, fn ($v) => $v !== null && $v !== ''));

        if (empty($orte) && !empty($fallbackOrt)) {
            $orte = [$fallbackOrt];
        }

        return $orte;
    }

    /**
     * @param array{notified: bool, wunschorte: array}|null $openEntry
     * @return array{action: 'noop'|'create'|'rearm', wunschorte: array}
     */
    public static function plan(?array $openEntry, array $resolvedWunschorte): array
    {
        if ($openEntry === null) {
            return empty($resolvedWunschorte)
                ? ['action' => 'noop', 'wunschorte' => []]
                : ['action' => 'create', 'wunschorte' => $resolvedWunschorte];
        }

        if (!$openEntry['notified']) {
            return ['action' => 'noop', 'wunschorte' => []];
        }

        $wunschorte = empty($resolvedWunschorte)
            ? $openEntry['wunschorte']
            : $resolvedWunschorte;

        return ['action' => 'rearm', 'wunschorte' => $wunschorte];
    }

    /**
     * Entscheidung für den Termin-Warteliste-Klick ("Benachrichtige mich,
     * wenn hier ein Platz frei wird"). Anders als plan() ohne Orte-Guard:
     * Termin-Einträge matchen über rec_interview_id, nicht über Wunschorte.
     *
     * @param array{notified: bool}|null $openEntry
     * @return array{action: 'noop'|'create'|'rearm'}
     */
    public static function planForInterview(?array $openEntry): array
    {
        if ($openEntry === null) {
            return ['action' => 'create'];
        }

        return $openEntry['notified']
            ? ['action' => 'rearm']
            : ['action' => 'noop'];
    }
}
