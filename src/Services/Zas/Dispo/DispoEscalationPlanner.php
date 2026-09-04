<?php
namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Reine Stufen-Entscheidung der Eskalation. Kein DB-/Config-Zugriff (testbar).
 * Gibt die HÖCHSTE fällige Stufe zurück (1/2/3) oder null.
 * Fairness-Guard: eine Stufe feuert nur, wenn der Erstversand VOR ihrer Uhrzeit lag.
 *
 * Schonfrist (Kunde 03.09., Nummern-Nachzug): Stufe 3 (Rausnahme + Portalsperre)
 * zusaetzlich erst, wenn die letzte Ansprache mindestens $graceHours Stunden her
 * ist. Sie verschiebt sich fuer Nachzuegler einfach nach hinten am selben Tag
 * (der Command laeuft alle 5 Minuten); reicht der Tag nicht, bleibt die Person
 * offen statt ohne echte Reaktionschance gesperrt zu werden. Fuer den Normalfall
 * (Versand Tage vorher) aendert die Frist nichts. 0 = aus (altes Verhalten).
 */
class DispoEscalationPlanner
{
    /**
     * @param array{reminder_sent_at:?\DateTimeImmutable, confirmed_at:?\DateTimeImmutable, escalation_1_at:?\DateTimeImmutable, escalation_2_at:?\DateTimeImmutable, deletion_marked_at:?\DateTimeImmutable} $state
     * @param array{1:string,2:string,3:string} $times HH:MM
     */
    public function dueStage(array $state, \DateTimeImmutable $now, array $times, int $graceHours = 0): ?int
    {
        $t = fn (string $hhmm): \DateTimeImmutable => $now->modify($hhmm); // gleicher Tag wie $now

        return $this->dueStageAt($state, $now, [1 => $t($times[1]), 2 => $t($times[2]), 3 => $t($times[3])], $graceHours);
    }

    /**
     * Wie dueStage(), aber mit ABSOLUTEN Zeitpunkten je Stufe — der Kern fuer
     * "Eskalation pro Sendung" (Kunde 04.09.): Nachzuegler tragen ihren Plan
     * als konkrete Zeitpunkte an der Einbuchung, unabhaengig vom Lauf-Tag.
     *
     * @param array{1:\DateTimeImmutable,2:\DateTimeImmutable,3:\DateTimeImmutable} $due
     */
    public function dueStageAt(array $state, \DateTimeImmutable $now, array $due, int $graceHours = 0): ?int
    {
        // Absage (Kunde 04.09.) beendet die Eskalation genauso wie eine Bestaetigung.
        if ($state['confirmed_at'] !== null || $state['deletion_marked_at'] !== null
            || ($state['declined_at'] ?? null) !== null) {
            return null;
        }
        $sent = $state['reminder_sent_at'];
        if ($sent === null) {
            return null; // nie angeschrieben -> nichts zu eskalieren
        }

        // Stufe 3 (Rausnahme) hat Vorrang, wenn ihre Zeit erreicht ist —
        // und die Schonfrist seit der letzten Ansprache abgelaufen ist.
        if ($now >= $due[3] && $sent <= $due[2] && $sent->modify('+' . $graceHours . ' hours') <= $now) {
            return 3;
        }
        if ($now >= $due[2] && $state['escalation_2_at'] === null && $sent <= $due[2]) {
            return 2;
        }
        if ($now >= $due[1] && $state['escalation_1_at'] === null && $state['escalation_2_at'] === null && $sent <= $due[1]) {
            return 1;
        }
        return null;
    }
}
