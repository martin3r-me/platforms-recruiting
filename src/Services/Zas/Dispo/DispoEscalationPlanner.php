<?php
namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Reine Stufen-Entscheidung der Eskalation. Kein DB-/Config-Zugriff (testbar).
 * Gibt die HÖCHSTE fällige Stufe zurück (1/2/3) oder null.
 * Fairness-Guard: eine Stufe feuert nur, wenn der Erstversand VOR ihrer Uhrzeit lag.
 */
class DispoEscalationPlanner
{
    /**
     * @param array{reminder_sent_at:?\DateTimeImmutable, confirmed_at:?\DateTimeImmutable, escalation_1_at:?\DateTimeImmutable, escalation_2_at:?\DateTimeImmutable, deletion_marked_at:?\DateTimeImmutable} $state
     * @param array{1:string,2:string,3:string} $times HH:MM
     */
    public function dueStage(array $state, \DateTimeImmutable $now, array $times): ?int
    {
        if ($state['confirmed_at'] !== null || $state['deletion_marked_at'] !== null) {
            return null;
        }
        $sent = $state['reminder_sent_at'];
        if ($sent === null) {
            return null; // nie angeschrieben -> nichts zu eskalieren
        }

        $t = fn (string $hhmm): \DateTimeImmutable => $now->modify($hhmm); // gleicher Tag wie $now

        // Stufe 3 (Rausnahme) hat Vorrang, wenn ihre Zeit erreicht ist.
        if ($now >= $t($times[3]) && $state['escalation_1_at'] !== null && $state['escalation_2_at'] !== null && $sent <= $t($times[3])) {
            return 3;
        }
        if ($now >= $t($times[2]) && $state['escalation_2_at'] === null && $sent <= $t($times[2])) {
            return 2;
        }
        if ($now >= $t($times[1]) && $state['escalation_1_at'] === null && $sent <= $t($times[1])) {
            return 1;
        }
        return null;
    }
}
